#!/usr/local/bin/php
<?php

require_once('filter.inc');
require_once('interfaces.inc');
require_once('legacy_bindings.inc');
require_once('plugins.inc');
require_once('system.inc');

require_once(__DIR__ . '/dhcpcarp-common.php');
require_once(__DIR__ . '/dhcpcarp-config-alias.php');
require_once(__DIR__ . '/dhcpcarp-config-gateway.php');
require_once(__DIR__ . '/dhcpcarp-config-npt.php');
require_once(__DIR__ . '/dhcpcarp-config-vip.php');
require_once(__DIR__ . '/dhcpcarp-config.php');
require_once(__DIR__ . '/dhcpcarp-peerops.php');

# Get info from dhcpcd
$interface = getenv('interface');
$reason = getenv('reason');
$new_ip_address = getenv('new_ip_address');
$new_subnet_cidr = getenv('new_subnet_cidr');
$new_routers = getenv('new_routers');

$ipv4_reasons = ["BOUND", "REBOOT"];
$ipv6_reasons = ["BOUND6", "REBOOT6", "ROUTERADVERT"];

if (in_array($reason, $ipv4_reasons)) {
    $ipv6 = false;
} elseif (in_array($reason, $ipv6_reasons)) {
    $ipv6 = true;
    $ra = $reason === "ROUTERADVERT";
} else {
    exit(0);
}

if ($ipv6) {
    $new_ip_address = getenv('new_dhcp6_ia_na1_ia_addr1');
    $new_subnet_cidr = getenv('nd1_prefix_information1_length');
    $new_routers = getenv('nd1_from');
}

# Die if we don't have the necessary info
if ($ipv6 && $ra && (empty($interface) || empty($new_subnet_cidr) || empty($new_routers))) {
    dhcpcarp_log_and_exit("ra $reason: did not get one of '$interface', '$new_subnet_cidr', '$new_routers'");
} elseif ($ipv6 && !$ra && (empty($interface) || empty($new_ip_address))) {
    dhcpcarp_log_and_exit("v6 $reason: did not get one of '$interface', '$new_ip_address'");
} elseif (!$ipv6 && (empty($interface) || empty($new_ip_address) || empty($new_subnet_cidr) || empty($new_routers))) {
    dhcpcarp_log_and_exit("v4 $reason: did not get one of '$interface', '$new_ip_address', '$new_subnet_cidr', '$new_routers'");
}

if ($ipv6) {
    if ($ra) {
        //dhcpcarp_log("$interface ra: */$new_subnet_cidr, gateway $new_routers");
    } else {
        dhcpcarp_log("$interface v6: $new_ip_address");
        foreach (dhcpcarp_get_dhcp6_prefixes() as $i => $prefix) {
            $_addr = $prefix["addr"];
            $_len = $prefix["len"];
            dhcpcarp_log("$interface v6 prefix $i: $_addr/$_len");
        }
    }
} else {
    dhcpcarp_log("$interface v4: $new_ip_address/$new_subnet_cidr, gateway $new_routers");
}

# Translate interface name
$ifname = convert_real_interface_to_friendly_interface_name($interface);
//dhcpcarp_log("mapped $interface to $ifname");

if ($ipv6) {
    $descr = "$ifname DHCPv6";
} else {
    $descr = "$ifname DHCP";
}

# Find existing configs
$vip = dhcpcarp_find_vip($descr);
if ($vip === null) {
    dhcpcarp_log_and_exit("did not find VIP for $descr");
}

$gateway = dhcpcarp_find_gateway($descr);
if ($gateway === null) {
    dhcpcarp_log_and_exit("did not find gateway for $descr");
}

$alias = dhcpcarp_find_alias($descr);
if ($alias === null) {
    dhcpcarp_log_and_exit("did not find firewall alias for $descr");
}

$nptData = dhcpcarp_build_nptData($descr);

# Don't do anything if the new lease matches the existing config
if (
    $ipv6 && $ra
    && dhcpcarp_check_vip($vip, $new_ip_address, $new_subnet_cidr, $ipv6, $ra)
    && dhcpcarp_check_gateway($gateway, $new_routers)
) {
    //dhcpcarp_log_and_exit("ra: nothing to update for $descr", 0);
    exit(0);
} elseif (
    $ipv6 && !$ra
    && dhcpcarp_check_vip($vip, $new_ip_address, $new_subnet_cidr, $ipv6, $ra)
    && empty($nptData)
) {
    dhcpcarp_log_and_exit("v6: nothing to update for $descr", 0);
} elseif (
    !$ipv6
    && dhcpcarp_check_vip($vip, $new_ip_address, $new_subnet_cidr, $ipv6, false)
    && dhcpcarp_check_gateway($gateway, $new_routers)
    && dhcpcarp_check_alias($alias, $new_ip_address)
) {
    dhcpcarp_log_and_exit("v4: nothing to update for $descr", 0);
}

# Hold CARP on peer before update
$peer_ip = dhcpcarp_get_peer_ip();
$peer_is_primary = dhcpcarp_is_peer_primary();
$peer_reachable = false;
$peer_carp_held = false;

if (empty($peer_ip)) {
    dhcpcarp_log("skipping CARP hold, no peer configured");
} else {
    $peer_reachable = dhcpcarp_is_peer_reachable($peer_ip, 2);
    if ($peer_reachable) {
        $peer_carp_held = dhcpcarp_hold_peer_carp($peer_ip);
        if (!$peer_carp_held) {
            dhcpcarp_log("CARP hold failed, proceeding without hold");
        }
    } else {
        dhcpcarp_log("peer $peer_ip unreachable, proceeding without CARP hold");
    }
}

try {
    # De-configure the virtual IP
    dhcpcarp_log("bringing $ifname VIP down");
    interface_vip_bring_down($vip);

    # Apply all configs
    $oldSubnet = $vip['subnet'];
    $oldBits = $vip['subnet_bits'];
    $vipData = dhcpcarp_build_vipData($vip, $new_ip_address, $new_subnet_cidr, $ipv6, $ra ?? false);
    dhcpcarp_log("Updating $ifname VIP from $oldSubnet/$oldBits to {$vipData['subnet']}/{$vipData['subnet_bits']}");

    $payload = [
        'descr' => $descr,
        'ifname' => $ifname,
        'ipv6' => $ipv6,
        'ra' => $ipv6 ? $ra : false,
        'vip' => $vipData,
    ];

    if (!$ipv6 || $ra) {
        $payload['gateway'] = dhcpcarp_build_gatewayData($gateway, $new_routers);
    }

    if (!$ipv6 || !$ra) {
        $payload['alias'] = dhcpcarp_build_aliasData($alias, $new_ip_address);
    }

    if (!empty($nptData)) {
        $payload['npt'] = $nptData;
    }

    dhcpcarp_apply_config($payload, false);

    # Re-configure the virtual IP
    dhcpcarp_log("reconfiguring $ifname VIP");
    $vip['subnet'] = $vipData['subnet'];
    $vip['subnet_bits'] = $vipData['subnet_bits'];
    switch ($vip['mode']) {
        case 'carp':
            interface_carp_configure($vip);
            break;
        case 'ipalias':
            interface_ipalias_configure($vip);
            break;
    }

    # Re-configure everything else
    system_routing_configure();
    plugins_configure('monitor');
    filter_configure();

    # Sync config to peer
    if ($peer_reachable) {
        if ($peer_is_primary) {
            $json = json_encode($payload);
            $ok = dhcpcarp_peer_apply_config($peer_ip, $json);
            if (!$ok) {
                dhcpcarp_log("selective sync to HA primary peer $peer_ip failed");
            }
        } else {
            configd_run('filter sync');
        }
    } else {
        dhcpcarp_log("peer is offline, skipping sync");
    }

} finally {
    if ($peer_carp_held) {
        $ok = dhcpcarp_release_peer_carp($peer_ip);
        if (!$ok) {
            dhcpcarp_log("CARP release failed — run `ssh root@$peer_ip 'sysctl net.inet.carp.allow=1'` to manually release");
        }
    }
}
