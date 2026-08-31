<?php

require_once('util.inc');

function dhcpcarp_log($msg)
{
    log_error("[dhcpcarp] $msg");
    fwrite(STDERR, "$msg\n");
}

function dhcpcarp_log_and_exit($msg, $code = 1)
{
    dhcpcarp_log($msg);
    exit($code);
}

function dhcpcarp_exec(string $cmd): int
{
    $ret = 0;
    passthru($cmd, $ret);
    return $ret;
}

function dhcpcarp_get_dhcp6_prefixes(): array
{
    $new_prefixes = [];
    $i = 1;

    while (true) {
        $_addr = getenv("new_dhcp6_ia_pd1_prefix$i");
        $_len = getenv("new_dhcp6_ia_pd1_prefix{$i}_length");

        if (!empty($_addr) && !empty($_len)) {
            $new_prefixes[$i] = ["addr" => $_addr, "len" => $_len];
            $i++;
            continue;
        }

        break;
    }

    return $new_prefixes;
}
