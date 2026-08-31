<?php

require_once('config.inc');  // phalcon autoloader

require_once(__DIR__ . '/dhcpcarp-common.php');

function dhcpcarp_npt_iterator()
{
    foreach ((new \OPNsense\Firewall\Filter())->npt->rule->iterateItems() as $id => $item) {
        $record = [];
        foreach ($item->iterateItems() as $key => $value) {
            $record[$key] = (string) $value;
        }
        $record['uuid'] = (string) $item->getAttributes()['uuid'];
        yield $record;
    }
}

function dhcpcarp_find_npt(string $npt_descr)
{
    foreach (dhcpcarp_npt_iterator() as $rec) {
        if ($rec['description'] === $npt_descr) {
            return $rec;
        }
    }
    return null;
}

function dhcpcarp_check_npt(array $found, string $newPrefix): bool
{
    return ($found['destination_net'] ?? '') === $newPrefix;
}

function dhcpcarp_build_nptRule(array $found, string $newPrefix): array
{
    return [
        'uuid' => $found['uuid'],
        'destination_net' => $newPrefix,
    ];
}

function dhcpcarp_build_nptData(string $descr): array
{
    $new_prefixes = dhcpcarp_get_dhcp6_prefixes();

    if (empty($new_prefixes)) {
        return [];
    }

    $nptData = [];

    foreach ($new_prefixes as $idx => $prefix) {
        $npt_descr = "$descr prefix $idx";
        $found = dhcpcarp_find_npt($npt_descr);

        if ($found === null) {
            dhcpcarp_log("did not find NPTv6 rule for $npt_descr");
            continue;
        }

        $nptRule_dest = $found['destination_net'] ?? '';
        $new_prefix = $prefix["addr"] . "/" . $prefix["len"];

        if (dhcpcarp_check_npt($found, $new_prefix)) {
            dhcpcarp_log("nothing to update for $npt_descr");
        } else {
            dhcpcarp_log("updating $npt_descr NPTv6 rule from $nptRule_dest to $new_prefix");
            $nptData[] = dhcpcarp_build_nptRule($found, $new_prefix);
        }
    }

    return $nptData;
}

function dhcpcarp_apply_nptData(array $nptDatas): void
{
    $filter = new \OPNsense\Firewall\Filter();

    foreach ($nptDatas as $npt) {
        if (empty($npt['uuid']) || empty($npt['destination_net'])) {
            continue;
        }

        $nptUuid = $npt['uuid'];
        $dest = $npt['destination_net'];
        $node = $filter->getNodeByReference("npt.rule.$nptUuid");

        if ($node === null) {
            dhcpcarp_log("NPTv6 uuid $nptUuid not found, skipping");
            continue;
        }

        $node->setNodes(['destination_net' => $dest]);
        dhcpcarp_log("updated NPTv6 to $dest");
    }

    $filter->serializeToConfig();
}
