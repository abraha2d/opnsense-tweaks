<?php

require_once('config.inc');  // phalcon autoloader

require_once(__DIR__ . '/dhcpcarp-common.php');

function dhcpcarp_vip_iterator()
{
    foreach ((new OPNsense\Interfaces\Vip())->vip->iterateItems() as $id => $item) {
        $record = [];
        foreach ($item->iterateItems() as $key => $value) {
            $record[$key] = (string) $value;
        }
        $record['uuid'] = (string) $item->getAttributes()['uuid'];
        yield $record;
    }
}

function dhcpcarp_find_vip(string $descr)
{
    foreach (dhcpcarp_vip_iterator() as $rec) {
        if ($rec['descr'] === $descr) {
            return $rec;
        }
    }
    return null;
}

function dhcpcarp_check_vip(array $found, string $newIp, string $newCidr, bool $ipv6, bool $ra): bool
{
    $subnet = $found['subnet'] ?? '';
    $subnet_bits = $found['subnet_bits'] ?? '';

    if ($ipv6 && $ra) {
        return $subnet_bits === $newCidr;
    }

    if ($ipv6 && !$ra) {
        return $subnet === $newIp;
    }

    return $subnet === $newIp && $subnet_bits === $newCidr;
}

function dhcpcarp_build_vipData(array $found, string $newIp, string $newCidr, bool $ipv6, bool $ra): array
{
    $subnet = $found['subnet'];
    $subnet_bits = $found['subnet_bits'];

    if (!$ipv6 || ($ipv6 && !$ra)) {
        $subnet = $newIp;
    }

    if (!$ipv6 || ($ipv6 && $ra)) {
        $subnet_bits = $newCidr;
    }

    return [
        'uuid' => $found['uuid'],
        'subnet' => $subnet,
        'subnet_bits' => $subnet_bits,
    ];
}

function dhcpcarp_apply_vipData(array $vipData): void
{
    $vipUuid = $vipData['uuid'];
    $vip = $vipData;
    unset($vip['uuid']);

    $vipModel = new OPNsense\Interfaces\Vip();
    $node = $vipModel->getNodeByReference("vip.$vipUuid");

    if ($node === null) {
        dhcpcarp_log("VIP uuid $vipUuid not found, skipping");
        return;
    }

    // setNodes merges — absent keys unchanged, partial update safe
    $node->setNodes($vip);
    $vipModel->serializeToConfig();
    dhcpcarp_log("updated VIP to {$vip['subnet']}/{$vip['subnet_bits']}");
}
