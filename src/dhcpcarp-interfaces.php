<?php

require_once('interfaces.inc');

require_once(__DIR__ . '/dhcpcarp-common.php');
require_once(__DIR__ . '/dhcpcarp-config-vip.php');

function dhcpcarp_interfaces(): array
{
    // fresh process per hook call, never stale — cached via global for testability
    if (array_key_exists('_dhcpcarp_interfaces_cache', $GLOBALS) && $GLOBALS['_dhcpcarp_interfaces_cache'] !== null) {
        return $GLOBALS['_dhcpcarp_interfaces_cache'];
    }

    $list = [];
    foreach (dhcpcarp_vip_iterator() as $rec) {
        if (($rec['mode'] ?? '') !== 'carp') {
            continue;
        }

        $d = $rec['descr'] ?? '';
        if (!str_ends_with($d, ' DHCP')) {
            // Skip everything except ' DHCP' (v4). There are no v6-only CARP VIPs;
            // v6 state is carried by IP Alias VIPs (see dhcpcarp-dhcp-hook.php:72
            // descr "$ifname DHCPv6")
            continue;
        }

        $f = substr($d, 0, -5);
        if ($f !== '') {
            $list[] = $f;
        }
    }

    $list = array_unique($list);
    sort($list);

    if (empty($list)) {
        dhcpcarp_log("no eligible virtual IPs found (mode CARP, description '<ifname> DHCP')");
    }

    $GLOBALS['_dhcpcarp_interfaces_cache'] = $list;
    return $list;
}

function dhcpcarp_interfaces_clear_cache(): void
{
    unset($GLOBALS['_dhcpcarp_interfaces_cache']);
}

function dhcpcarp_is_interface_eligible(string $ifname): bool
{
    return in_array($ifname, dhcpcarp_interfaces(), true);
}

function dhcpcarp_is_real_interface_eligible(string $iface): bool
{
    if ($iface === '') {
        return false;
    }

    foreach (dhcpcarp_interfaces() as $ifname) {
        if ($iface === get_real_interface($ifname)) {
            return true;
        }
    }

    return false;
}

function dhcpcarp_get_address_request(string $iface): string
{
    $ifname = convert_real_interface_to_friendly_interface_name($iface);
    $vip = dhcpcarp_find_vip("$ifname DHCP");
    return $vip['subnet'] ?? '';
}

function dhcpcarp_get_vhid($iface)
{
    $out = shell_exec("ifconfig " . escapeshellarg($iface) . " 2>&1");
    if ($out && preg_match('/vhid\s+(\d+)/', $out, $m)) {
        return (int) $m[1];
    }
    return null;
}

function dhcpcarp_is_carp_master($iface)
{
    $out = shell_exec("ifconfig " . escapeshellarg($iface) . " 2>&1");
    if ($out === null) {
        return null;
    }
    if (preg_match('/carp:\s*MASTER/i', $out)) {
        return true;
    }
    if (preg_match('/carp:\s*BACKUP/i', $out)) {
        return false;
    }
    return null;
}

function dhcpcarp_set_carp_mac(string $iface, ?int $vhid = null): void
{
    $vhid ??= dhcpcarp_get_vhid($iface);
    if ($vhid !== null) {
        $mac = sprintf('00:00:5e:00:01:%02x', $vhid);
        dhcpcarp_log("setting '$iface' MAC address to '$mac'");
        dhcpcarp_exec("ifconfig " . escapeshellarg($iface) . " ether " . escapeshellarg($mac));
    }
}

function dhcpcarp_restore_mac(string $iface): void
{
    // hwaddr appears only if custom MAC address set
    $out = shell_exec("ifconfig " . escapeshellarg($iface) . " 2>&1");
    if ($out !== null && preg_match('/hwaddr\s+(\S+)/', $out, $m)) {
        $mac = trim($m[1]);
        if ($mac !== '') {
            dhcpcarp_log("restoring '$iface' MAC address to '$mac'");
            dhcpcarp_exec("ifconfig " . escapeshellarg($iface) . " ether " . escapeshellarg($mac));
        }
    }
}
