#!/usr/local/bin/php
<?php

/*
 * JSON schema:
 * {
 *   "descr":   "<ifname> DHCP" | "<ifname> DHCPv6",
 *   "ifname":  "wan",  // friendly name (see dhcpcarp_interfaces())
 *   "ipv6":    false,
 *   "ra":      false,  // only for ipv6
 *   "vip":     {"uuid": "...", "subnet": "1.2.3.4", "subnet_bits": "24", ...},
 *   "gateway": {"uuid": "...", "gateway": "1.2.3.1"},
 *   "alias":   {"uuid": "...", "content": "1.2.3.4"},
 *   "npt":   [ {"uuid": "...", "destination_net": "2001:db8::/56", "descr": "<ifname> DHCPv6 prefix 1"}, ... ]
 * }
 */

require_once('config.inc');
require_once('filter.inc');
require_once('plugins.inc');
require_once('system.inc');

require_once(__DIR__ . '/dhcpcarp-common.php');
require_once(__DIR__ . '/dhcpcarp-config-alias.php');
require_once(__DIR__ . '/dhcpcarp-config-gateway.php');
require_once(__DIR__ . '/dhcpcarp-config-npt.php');
require_once(__DIR__ . '/dhcpcarp-config-vip.php');

function dhcpcarp_apply_config(array $data, bool $do_configure = true)
{
    $descr = $data['descr'] ?? 'unknown';
    dhcpcarp_log("applying updates for $descr");

    if (!empty($data['vip']) && !empty($data['vip']['uuid'])) {
        dhcpcarp_apply_vipData($data['vip']);
    }

    if (!empty($data['gateway']) && !empty($data['gateway']['uuid'])) {
        dhcpcarp_apply_gatewayData($data['gateway']);
    }

    if (!empty($data['alias']) && !empty($data['alias']['uuid'])) {
        dhcpcarp_apply_aliasData($data['alias']);
    }

    if (!empty($data['npt']) && is_array($data['npt'])) {
        dhcpcarp_apply_nptData($data['npt']);
    }

    global $config;
    $config = OPNsense\Core\Config::getInstance()->toArray(listtags());
    write_config();

    if ($do_configure) {
        system_routing_configure();
        plugins_configure('monitor');
        filter_configure();
    }

    dhcpcarp_log("applied updates for $descr");
}

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $json = '';
    $stdin = fopen('php://stdin', 'r');

    if ($stdin) {
        $json = stream_get_contents($stdin);
        fclose($stdin);
    }

    if (empty($json)) {
        dhcpcarp_log_and_exit('no JSON payload on stdin');
    }

    $data = json_decode($json, true);
    if ($data === null) {
        dhcpcarp_log_and_exit('invalid JSON: ' . json_last_error_msg());
    }

    dhcpcarp_apply_config($data, true);
    exit(0);
}
