#!/usr/local/bin/php
<?php

require_once('interfaces.inc');

require_once(__DIR__ . '/dhcpcarp-common.php');
require_once(__DIR__ . '/dhcpcarp-dhcp.php');
require_once(__DIR__ . '/dhcpcarp-interfaces.php');

function usage($msg = null)
{
    if ($msg) {
        fwrite(STDERR, "$msg\n\n");
    }

    $ifs = dhcpcarp_interfaces();
    $hint = !empty($ifs) ? implode('|', $ifs) : '<interface>';

    fwrite(STDERR, "Usage: dhcpcarp-renew.php [$hint] [--renew|--reset]\n");
    fwrite(STDERR, "  $hint — friendly interface name (required)\n");
    fwrite(STDERR, "  --renew — dhcpcd -n rebind (default, no MAC address change)\n");
    fwrite(STDERR, "  --reset — kill dhcpcd, clean lease, (re)set CARP MAC address, start dhcpcd\n");
    exit($msg ? 1 : 0);
}

function dhcpcarp_renew($iface, $mode)
{
    $pidFile = "/var/run/dhcpcd/{$iface}.pid";
    $running = file_exists($pidFile) && trim(@file_get_contents($pidFile)) !== '';

    if ($mode === 'renew') {
        if ($running) {
            dhcpcarp_log("dhcpcd is running on '$iface', requesting renewal");
            return dhcpcarp_exec("dhcpcd -n " . escapeshellarg($iface)) === 0;
        }

        return dhcpcarp_start_dhcpcd($iface);
    }

    if ($mode === 'reset') {
        dhcpcarp_stop_dhcpcd($iface);
        return dhcpcarp_start_dhcpcd($iface);
    }

    return false;
}

$args = array_slice($argv, 1);
$ifname = null;
$mode = 'renew';

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--renew') {
        $mode = 'renew';
    } else if ($a === '--reset') {
        $mode = 'reset';
    } else if ($a === '-h' || $a === '--help') {
        usage();
    } else if ($a[0] === '-') {
        usage("unknown option $a");
    } else {
        $ifname = $a;
    }
}

if ($ifname === null) {
    $hint = implode('|', dhcpcarp_interfaces());
    usage("no interface provided");
}

if (!dhcpcarp_is_interface_eligible($ifname)) {
    $hint = implode('|', dhcpcarp_interfaces());
    usage("invalid interface '$ifname' - " . (empty($hint) ? 'no eligible interfaces' : "expected one of $hint"));
}

$iface = get_real_interface($ifname);
if (empty($iface)) {
    dhcpcarp_log_and_exit("no real interface for '$ifname'");
}

$is_master = dhcpcarp_is_carp_master($iface);

if ($is_master === false) {
    dhcpcarp_log_and_exit("$ifname CARP is currently BACKUP, refusing to run");
} else if ($is_master === null) {
    dhcpcarp_log("$ifname CARP is currently unknown");
}

$ok = dhcpcarp_renew($iface, $mode);
if (!$ok) {
    dhcpcarp_log_and_exit("renew failed for '$ifname' (mode=$mode)");
}

dhcpcarp_log("renewed lease for '$ifname' (mode=$mode)");
