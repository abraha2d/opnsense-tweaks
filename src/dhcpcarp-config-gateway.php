<?php

require_once('config.inc');  // phalcon autoloader

require_once(__DIR__ . '/dhcpcarp-common.php');

function dhcpcarp_gateway_iterator()
{
    $gateways = new \OPNsense\Routing\Gateways();
    foreach ($gateways->gatewayIterator() as $id => $item) {
        yield $item;
    }
}

function dhcpcarp_find_gateway(string $descr)
{
    foreach (dhcpcarp_gateway_iterator() as $item) {
        if ((string) ($item['descr'] ?? '') === $descr) {
            return $item;
        }
    }
    return null;
}

function dhcpcarp_check_gateway(array $found, string $newRouter): bool
{
    return (string) ($found['gateway'] ?? '') === $newRouter;
}

function dhcpcarp_build_gatewayData(array $found, string $newRouter): array
{
    return [
        'uuid' => (string) ($found['uuid'] ?? ''),
        'gateway' => $newRouter,
    ];
}

function dhcpcarp_apply_gatewayData(array $gwData): void
{
    $gwUuid = $gwData['uuid'];
    $gwAddr = $gwData['gateway'] ?? null;

    if ($gwAddr === null) {
        return;
    }

    $gateways = new \OPNsense\Routing\Gateways();
    $node = $gateways->getNodeByReference("gateways.gateway.$gwUuid");
    if ($node !== null) {
        $gateways->createOrUpdateGateway(['gateway' => $gwAddr], $gwUuid);
        dhcpcarp_log("updated gateway to $gwAddr");
    } else {
        dhcpcarp_log("gateway uuid $gwUuid not found, skipping");
    }
}
