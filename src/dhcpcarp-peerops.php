<?php

require_once('config.inc');
require_once(__DIR__ . '/dhcpcarp-common.php');

function dhcpcarp_get_peer_ip()
{
    $config = OPNsense\Core\Config::getInstance()->object();
    $peer = trim((string) ($config->hasync->synchronizetoip ?? ''));

    if ($peer !== '' && filter_var($peer, FILTER_VALIDATE_IP)) {
        return $peer;
    }

    $f = dirname(__DIR__) . '/.dhcpcarp_peer';
    if (is_file($f)) {
        $p = trim(@file_get_contents($f));
        if (filter_var($p, FILTER_VALIDATE_IP)) {
            return $p;
        }
    }

    return null;
}

function dhcpcarp_is_peer_primary()
{
    $config = OPNsense\Core\Config::getInstance()->object();
    return trim((string) ($config->hasync->synchronizetoip ?? '')) !== '';
}

function dhcpcarp_peer_exec($peer_ip, $cmd, $timeout = 2, $stdin = null)
{
    if (empty($peer_ip) || !filter_var($peer_ip, FILTER_VALIDATE_IP)) {
        return [false, 'invalid peer ip'];
    }

    $ssh_cmd = sprintf(
        'ssh -o BatchMode=yes -o ConnectTimeout=%d -o StrictHostKeyChecking=accept-new -o LogLevel=ERROR root@%s %s',
        $timeout,
        $peer_ip,
        escapeshellarg($cmd),
    );

    if ($stdin === null) {
        $out = [];
        $ret = 0;
        exec("$ssh_cmd 2>&1", $out, $ret);
        return [$ret === 0, implode("\n", $out)];
    }

    // Pipe $stdin via proc_open to avoid ARG_MAX and shell quoting of payload
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($ssh_cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return [false, 'proc_open failed'];
    }

    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $ret = proc_close($proc);
    $combined = trim($stdout . ($stderr !== '' ? "\n$stderr" : ''));

    return [$ret === 0, $combined];
}

function dhcpcarp_is_peer_reachable($peer_ip, $timeout = 2)
{
    if (empty($peer_ip)) {
        return false;
    }

    [$ok, $out] = dhcpcarp_peer_exec($peer_ip, 'true', $timeout);
    return $ok;
}

function dhcpcarp_hold_peer_carp($peer_ip)
{
    [$ok, $out] = dhcpcarp_peer_exec($peer_ip, 'sysctl net.inet.carp.allow=0', 2);

    if ($ok) {
        dhcpcarp_log("held CARP on peer $peer_ip");
        return true;
    }

    dhcpcarp_log("failed to hold CARP on peer $peer_ip: $out");
    return false;
}

function dhcpcarp_release_peer_carp($peer_ip)
{
    [$ok, $out] = dhcpcarp_peer_exec($peer_ip, 'sysctl net.inet.carp.allow=1', 2);

    if ($ok) {
        dhcpcarp_log("released CARP on peer $peer_ip");
        return true;
    }

    dhcpcarp_log("failed to release CARP on peer $peer_ip: $out");
    return false;
}

function dhcpcarp_peer_apply_config($peer_ip, $json)
{
    // peer assumed to have identical $DIR layout as local host
    $cmd = 'php ' . __DIR__ . '/dhcpcarp-config.php';
    [$ok, $out] = dhcpcarp_peer_exec($peer_ip, $cmd, 2, $json);

    if ($ok) {
        dhcpcarp_log("selective sync to peer $peer_ip succeeded");
        return true;
    }

    dhcpcarp_log("selective sync to peer $peer_ip failed: $out");
    return false;
}
