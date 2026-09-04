# opnsense-tweaks — DHCP + CARP HA for OPNsense

Run `dhcpcd` with a CARP virtual MAC so a single dynamic ISP lease survives OPNsense HA failover.

Many ISPs (notably cable modems) lock a DHCP lease to a single MAC and only hand out one IPv4 address. OPNsense's default `dhclient` coupled with CARP does not handle this well — the backup firewall either cannot obtain a lease or the modem refuses to re-issue one after a failover.

This project runs `dhcpcd` on the CARP virtual MAC address and keeps OPNsense's virtual IP, gateway, firewall alias, and NPTv6 rules in sync with the current lease.


## Requirements

| Requirement | Note |
|---|---|
| OPNsense **26.7** | Only version currently tested. Other versions may work but are untested. |
| HA pair (optional but expected) | `System > High Availability` with `Synchronize Peer IP` and `pfsync` interface configured. Single-node works; peer sync is skipped. |
| Clone location | Repository must live at `/root/opnsense-tweaks` — hooks use absolute paths (`/root/opnsense-tweaks/src/dhcpcarp-*.php`). |
| Ports tree | `opnsense-code ports tools` must succeed. |

### OPNsense pre-configuration

The hooks discover what to manage by description. Create these objects **before** installing:

| Object | Location | Description must be |
|---|---|---|
| CARP virtual IP (IPv4) | `Interfaces > Virtual IPs` (Type: CARP) | `<ifname> DHCP` (e.g. `wan DHCP`) |
| IP Alias (IPv6) | `Interfaces > Virtual IPs` (Type: IP Alias) | `<ifname> DHCPv6` |
| Gateway | `System > Gateways` | `<ifname> DHCP` / `<ifname> DHCPv6` |
| Firewall Alias | `Firewall > Aliases` (Type: Host) | `<ifname> DHCP` / `<ifname> DHCPv6` |
| NPTv6 Rules | `Firewall > NAT > NPTv6` | `<ifname> DHCPv6 prefix 1`, `prefix 2`, ... — one per delegated prefix you use |

IPv6 CARP virtual IPs are not supported; IPv6 state is carried by IP Alias (avoids split-brain).

Firewall aliases are to be used for outbound NAT/port-forward rules so they follow the dynamic IP without manual edits.


## Installation

The shipped `dhcpcd.conf` contains `interface vtnet1` with `ia_na` and `ia_pd 1`-`5`. If needed, **edit this before installing** — replace `vtnet1` with your real WAN interface name (`igb0`, `vtnet0`, `em0`, etc.). Use `ifconfig` or `Interfaces > Assignments` to find it. Add or remove `ia_pd` lines to match the number of delegated prefixes your ISP provides.

Then, run as `root` on the primary firewall:

```sh
# clone to the expected path
git clone https://github.com/abraha2d/opnsense-tweaks.git /root/opnsense-tweaks
cd /root/opnsense-tweaks

# edit the interface name to match your WAN, if needed
vi usr/local/etc/dhcpcd.conf   # change `interface vtnet1`

sh install.sh
```

Re-run `sh install.sh` after pulling updates — it will re-sync the peer to the same commit.


## Usage

### Manual renew

```sh
php /root/opnsense-tweaks/src/dhcpcarp-renew.php <ifname> [--renew|--reset]
```

* `--renew` (default) — gentle rebind: `dhcpcd -n <iface>`
* `--reset` — cold reset: `kill -9 dhcpcd`, clean `*.lease`, (re)set MAC address, then `dhcpcd -r <address_request>`

Refuses to run if the interface's CARP state is `BACKUP`.


## Troubleshooting

* **Logs** — All actions log to both `STDERR` and the system log. Check `System > Log Files > General` or `clog /var/log/system.log | grep dhcpcarp`.
* **CARP hold/release failed** — The hook logs `failed to hold CARP on peer` / `failed to release`. Manual release: `ssh root@<peer_ip> 'sysctl net.inet.carp.allow=1'`.
* **no eligible virtual IPs found** — No CARP virtual IP with description `<ifname> DHCP` exists, or mode is not `carp`.
* **did not find VIP/gateway/alias/NPT for \<descr\>** — Description mismatch; see [Pre-configuration](#OPNsense-Pre-configuration).
* **Peer unreachable** — Hook proceeds without hold/sync and logs `peer <ip> unreachable`. Verify mutual SSH key trust, host key acceptance, and that `.dhcpcarp_peer` contains the correct IP. SSH keys are generated with an empty passphrase (`ssh-keygen -N ''`) so `ssh -o BatchMode=yes` can run non-interactively.
* **Lease not updating** — Confirm `dhcpcd` is running: `cat /var/run/dhcpcd/<interface>.pid` and `ps aux | grep dhcpcd`. Use `src/dhcpcarp-renew.php wan --reset` to force a cold restart.


## Uninstall

```sh
sh /root/opnsense-tweaks/uninstall.sh
```

Remove the CARP virtual IP / gateway / firewall alias / NPTv6 rules manually if no longer needed.


## How it works

### CARP hook

`/usr/local/etc/rc.syshook.d/carp/10-wandhcp` delegates to `src/dhcpcarp-carp-hook.php` (`/root/opnsense-tweaks/src/dhcpcarp-carp-hook.php`):

* `MASTER` — sets the WAN interface MAC to `00:00:5e:00:01:{vhid:02x}` and starts `dhcpcd`. If a previous lease address is known, it is passed via `-r` to encourage the server to re-issue the same IP.
* `BACKUP` — stops `dhcpcd` and restores the original `hwaddr`.

Interface eligibility is determined by scanning CARP virtual IPs whose description ends with ` DHCP`.

### `dhcpcd` hook

`/usr/local/libexec/dhcpcd-hooks/10-wancarp` delegates to `src/dhcpcarp-dhcp-hook.php` (`/root/opnsense-tweaks/src/dhcpcarp-dhcp-hook.php`). It handles:

* `BOUND` / `REBOOT` — IPv4 lease (`new_ip_address`, `new_subnet_cidr`, `new_routers`)
* `BOUND6` / `REBOOT6` — DHCPv6 address (`new_dhcp6_ia_na1_ia_addr1`)
* `ROUTERADVERT` — SLAAC / RA prefix length and router (`nd1_prefix_information1_length`, `nd1_from`)
* Delegated prefixes (`new_dhcp6_ia_pd1_prefix{1..N}`)

Updates are idempotent — if the lease matches the stored virtual IP / gateway / firewall alias / NPTv6 rules, the hook exits early.

When a change is required:

1. Hold CARP on the HA peer (`sysctl net.inet.carp.allow=0`) if reachable.
2. Bring the local virtual IP down (`interface_vip_bring_down`).
3. Update the OPNsense MVC models, then apply the configuration (`write_config` / `system_routing_configure` / `plugins_configure('monitor')` / `filter_configure`).
4. Re-configure the virtual IP (`interface_carp_configure` / `interface_ipalias_configure`).
5. Sync to peer — selective JSON sync via `php src/dhcpcarp-config.php` (`/root/opnsense-tweaks/src/dhcpcarp-config.php`) over SSH if this node is the HA backup, otherwise `configd_run('filter sync')`.
6. Release the peer CARP hold (`net.inet.carp.allow=1`).

### `dhcpcd` configuration

* **Stable DUID/MAC** — The CARP virtual MAC `00:00:5e:00:01:{vhid}` is programmed with `ifconfig ether` before `dhcpcd` starts. `dhcpcd.conf` pins `duid 00:03:00:01:00:00:5e:00:01:01` (type 3, link-layer + `00:00:5e:00:01:01`) and `xidhwaddr` so the client identifier and transaction ID are tied to the virtual MAC, not the physical NIC. This avoids the race where multiple WANs generate competing DUIDs at boot (commit `592563a`).

* **`xidhwaddr` + `persistent`** — `xidhwaddr` forces the DHCP transaction ID to be derived from the hardware address, making retransmits recognizable to the server after a failover. `persistent` keeps the interface configured when `dhcpcd` exits so CARP virtual IP bring-down/up is controlled explicitly by the hook rather than `dhcpcd`.

* **Prefix delegation** — `ia_pd 1`-`5` in `dhcpcd.conf` request up to five `/56` or `/60` prefixes. The hook reads `new_dhcp6_ia_pd1_prefix{1..N}` env vars and updates matching NPTv6 rules. Earlier iterations relied on hardware-address-based DUID generation; the current approach hard-codes the DUID to avoid per-interface divergence.
