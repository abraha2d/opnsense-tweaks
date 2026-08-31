<?php

require_once('config.inc');  // phalcon autoloader

require_once(__DIR__ . '/dhcpcarp-common.php');

function dhcpcarp_alias_iterator()
{
    foreach ((new \OPNsense\Firewall\Alias())->aliases->alias->iterateItems() as $id => $item) {
        $record = [];
        foreach ($item->iterateItems() as $key => $value) {
            $record[$key] = (string) $value;
        }
        $record['uuid'] = (string) $item->getAttributes()['uuid'];
        yield $record;
    }
}

function dhcpcarp_find_alias(string $descr)
{
    foreach (dhcpcarp_alias_iterator() as $rec) {
        if ($rec['description'] === $descr) {
            return $rec;
        }
    }
    return null;
}

function dhcpcarp_check_alias(array $found, string $newIp): bool
{
    return (string) ($found['content'] ?? '') === $newIp;
}

function dhcpcarp_build_aliasData(array $found, string $newIp): array
{
    return [
        'uuid' => $found['uuid'],
        'content' => $newIp,
    ];
}

function dhcpcarp_apply_aliasData(array $aliasData): void
{
    $aliasUuid = $aliasData['uuid'];
    $aliasContent = $aliasData['content'] ?? null;

    if ($aliasContent === null) {
        return;
    }

    $aliasModel = new \OPNsense\Firewall\Alias();
    $node = $aliasModel->getNodeByReference("aliases.alias.$aliasUuid");

    if ($node === null) {
        dhcpcarp_log("alias uuid $aliasUuid not found, skipping");
        return;
    }

    $node->setNodes(['content' => $aliasContent]);
    $aliasModel->serializeToConfig();
    dhcpcarp_log("updated alias to $aliasContent");
}
