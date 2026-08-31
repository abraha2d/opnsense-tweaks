<?php

require_once __DIR__ . '/../../src/dhcpcarp-config-gateway.php';

use OPNsense\Routing\Gateways;

describe('dhcpcarp_check_gateway', function () {
    it('returns true for matching gateway', function () {
        expect(dhcpcarp_check_gateway(['gateway' => '1.2.3.1'], '1.2.3.1'))->toBeTrue();
    });
    it('returns false for mismatch', function () {
        expect(dhcpcarp_check_gateway(['gateway' => '1.2.3.1'], '1.2.3.2'))->toBeFalse();
    });
    it('handles missing gateway key', function () {
        expect(dhcpcarp_check_gateway([], '1.2.3.1'))->toBeFalse();
    });
});

describe('dhcpcarp_build_gatewayData', function () {
    it('builds with uuid and gateway', function () {
        $found = ['uuid' => 'gw-uuid', 'gateway' => '1.1.1.1'];
        expect(dhcpcarp_build_gatewayData($found, '9.9.9.1'))->toBe([
            'uuid' => 'gw-uuid', 'gateway' => '9.9.9.1',
        ]);
    });
});

describe('dhcpcarp_find_gateway', function () {
    it('finds by description', function () {
        Gateways::$testGatewayRecords = [
            ['descr' => 'wan DHCP', 'gateway' => '1.2.3.1', 'uuid' => 'uuid-gw-1'],
            ['descr' => 'wan DHCPv6', 'gateway' => 'fe80::1', 'uuid' => 'uuid-gw-2'],
        ];
        $found = dhcpcarp_find_gateway('wan DHCP');
        expect($found)->not->toBeNull()
            ->and($found['gateway'])->toBe('1.2.3.1');
    });

    it('returns null when not found', function () {
        Gateways::$testGatewayRecords = [
            ['descr' => 'wan DHCP', 'gateway' => '1.2.3.1', 'uuid' => 'uuid-gw-1'],
        ];
        expect(dhcpcarp_find_gateway('missing'))->toBeNull();
    });
});

describe('dhcpcarp_apply_gatewayData', function () {
    it('calls createOrUpdateGateway for existing uuid', function () {
        Gateways::$testGatewayRecords = [
            ['descr' => 'wan DHCP', 'gateway' => '1.2.3.1', 'uuid' => 'uuid-gw-1'],
        ];
        dhcpcarp_apply_gatewayData(['uuid' => 'uuid-gw-1', 'gateway' => '9.9.9.9']);
        expect($GLOBALS['_test_gateway_create'])->toContain([['gateway' => '9.9.9.9'], 'uuid-gw-1']);
    });

    it('logs skip for missing uuid', function () {
        Gateways::$testGatewayRecords = [];
        dhcpcarp_apply_gatewayData(['uuid' => 'missing', 'gateway' => '9.9.9.9']);
        expect(implode("\n", $GLOBALS['_test_log']))->toContain('gateway uuid missing not found');
    });

    it('returns early when gateway is null', function () {
        Gateways::$testGatewayRecords = [
            ['descr' => 'wan DHCP', 'gateway' => '1.2.3.1', 'uuid' => 'uuid-gw-1'],
        ];
        dhcpcarp_apply_gatewayData(['uuid' => 'uuid-gw-1']);
        expect($GLOBALS['_test_gateway_create'])->toBe([]);
    });
});
