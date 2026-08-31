#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once('interfaces.inc');

$config = OPNsense\Core\Config::getInstance()->object();
$mode = $argv[1] ?? '';

if ($mode === 'peer-ip') {
    echo trim((string) ($config->hasync->synchronizetoip ?? ''));

} elseif ($mode === 'pfsync-ip') {
    $ifname = trim((string) ($config->hasync->pfsyncinterface ?? ''));
    if ($ifname === '') {
        exit(1);
    }

    $iface = get_real_interface($ifname);
    if ($iface === '') {
        $iface = $ifname;
    }

    $ip = get_interface_ip($iface);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        exit(1);
    }

    echo $ip;
}
