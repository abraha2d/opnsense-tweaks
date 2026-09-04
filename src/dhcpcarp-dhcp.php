<?php

require_once(__DIR__ . '/dhcpcarp-common.php');
require_once(__DIR__ . '/dhcpcarp-interfaces.php');

function dhcpcarp_is_dhcpcd_running(string $interface): bool
{
    $pidFile = "/var/run/dhcpcd/{$interface}.pid";
    return file_exists($pidFile) && trim(@file_get_contents($pidFile)) !== '';
}

function dhcpcarp_start_dhcpcd(string $interface, ?int $vhid = null): bool
{
    dhcpcarp_set_carp_mac($interface, $vhid);

    $request = dhcpcarp_get_address_request($interface);
    if (!empty($request)) {
        dhcpcarp_log("starting dhcpcd on interface '$interface' with request '$request'");
        return dhcpcarp_exec("dhcpcd -b -r " . escapeshellarg($request) . " --noconfigure " . escapeshellarg($interface)) === 0;
    }

    dhcpcarp_log("starting dhcpcd on interface '$interface'");
    return dhcpcarp_exec("dhcpcd -b --noconfigure " . escapeshellarg($interface)) === 0;
}

function dhcpcarp_stop_dhcpcd(string $interface): void
{
    $pidFile = "/var/run/dhcpcd/{$interface}.pid";
    $running = dhcpcarp_is_dhcpcd_running($interface);

    if ($running) {
        dhcpcarp_log("stopping dhcpcd on interface '$interface'");
        dhcpcarp_exec('kill -9 $(cat ' . escapeshellarg($pidFile) . ') 2>&1');
        sleep(1);
    } else {
        dhcpcarp_log("dhcpcd not running on interface '$interface'");
    }

    @unlink("/var/db/dhcpcd/{$interface}.lease");
    @unlink("/var/db/dhcpcd/{$interface}.lease6");

    dhcpcarp_restore_mac($interface);
}
