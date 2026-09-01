#!/usr/local/bin/php
<?php

require_once(__DIR__ . '/dhcpcarp-common.php');
require_once(__DIR__ . '/dhcpcarp-dhcp.php');
require_once(__DIR__ . '/dhcpcarp-interfaces.php');

$argv = $_SERVER['argv'] ?? [];
$subsystem = $argv[1] ?? '';
$type = $argv[2] ?? '';

if (!strstr($subsystem, '@')) {
    dhcpcarp_log_and_exit("CARP event '$type' triggered from wrong source '$subsystem'");
}

[$vhid, $iface] = explode('@', $subsystem, 2);

if (!ctype_digit((string) $vhid)) {
    dhcpcarp_log_and_exit("CARP event '$type' has invalid VHID '$vhid' (source '$subsystem')");
}

$vhidInt = (int) $vhid;
if ($vhidInt < 1 || $vhidInt > 255) {
    dhcpcarp_log_and_exit("CARP event '$type' has out-of-range VHID '$vhid' (source '$subsystem')");
}

if (empty($iface)) {
    dhcpcarp_log_and_exit("CARP event '$type' has empty interface (source '$subsystem')");
}

if (!dhcpcarp_is_real_interface_eligible($iface)) {
    dhcpcarp_log_and_exit("ignoring non-eligible interface '$iface'", 0);
}

if ($type === 'MASTER') {
    dhcpcarp_log("starting dhcpcd on interface '$iface' due to CARP event '$type'");
    dhcpcarp_start_dhcpcd($iface, $vhidInt);

} elseif ($type === 'BACKUP') {
    dhcpcarp_log("stopping dhcpcd on interface '$iface' due to CARP event '$type'");
    dhcpcarp_stop_dhcpcd($iface);

} else {
    dhcpcarp_log_and_exit("CARP event '$type' unknown (source '$subsystem')");
}
