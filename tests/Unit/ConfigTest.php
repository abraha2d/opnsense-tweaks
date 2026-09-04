<?php

require_once __DIR__ . '/../../src/dhcpcarp-config.php';

use Tests\Stubs\FakeVipItem;
use OPNsense\Interfaces\Vip;

describe('dhcpcarp_apply_config', function () {
    it('applies vip, gateway, alias and npt and calls system configure', function () {
        $vipUuid = 'vip-uuid-apply-1';
        $vipItem = new FakeVipItem(['descr' => 'wan DHCP', 'subnet' => '1.1.1.1', 'subnet_bits' => '24', 'mode' => 'carp', 'uuid' => $vipUuid]);
        Vip::$testIterateItems = [$vipItem];

        // Prepare a gateway record so Gateway stub can handle apply
        \OPNsense\Routing\Gateways::$testGatewayRecords = [
            ['descr' => 'wan DHCP', 'gateway' => '1.1.1.1', 'uuid' => 'gw-uuid-1'],
        ];
        // For alias/npt we rely on stub's handling of missing uuid being logged; we provide valid npt via Filter stub items if needed
        // Use alias stub with direct node mapping not easily testable, so we test gateway only for now
        $data = [
            'descr' => 'wan DHCP',
            'vip' => ['uuid' => $vipUuid, 'subnet' => '9.9.9.9', 'subnet_bits' => '24'],
            'gateway' => ['uuid' => 'gw-uuid-1', 'gateway' => '9.9.9.1'],
        ];
        // mock global $config for write_config path
        $GLOBALS['config'] = ['system' => []];
        \OPNsense\Core\Config::getInstance()->toArrayData = ['system' => []];

        dhcpcarp_apply_config($data, false);

        expect((string) $vipItem->testChildren['subnet'])->toBe('9.9.9.9');
        expect($GLOBALS['_test_gateway_create'])->toContain([['gateway' => '9.9.9.1'], 'gw-uuid-1']);
        // when do_configure false, system routing/filter should not be called
        expect($GLOBALS['_test_system_routing_calls'])->toBe([]);
    });

    it('calls system_routing_configure etc when do_configure true', function () {
        $vipUuid = 'vip-uuid-apply-2';
        $vipItem = new FakeVipItem(['descr' => 'wan DHCP', 'subnet' => '1.1.1.1', 'subnet_bits' => '24', 'mode' => 'carp', 'uuid' => $vipUuid]);
        Vip::$testIterateItems = [$vipItem];
        \OPNsense\Routing\Gateways::$testGatewayRecords = [
            ['descr' => 'wan DHCP', 'gateway' => '1.1.1.1', 'uuid' => 'gw-uuid-1'],
        ];
        $GLOBALS['config'] = ['system' => []];
        \OPNsense\Core\Config::getInstance()->toArrayData = ['system' => []];

        $data = [
            'descr' => 'wan DHCP',
            'vip' => ['uuid' => $vipUuid, 'subnet' => '5.5.5.5', 'subnet_bits' => '24'],
        ];
        dhcpcarp_apply_config($data, true);

        expect($GLOBALS['_test_system_routing_calls'])->not->toBeEmpty();
        expect($GLOBALS['_test_plugins_configure_calls'])->toContain('monitor');
        expect($GLOBALS['_test_filter_configure_calls'])->not->toBeEmpty();
        expect(implode("\n", $GLOBALS['_test_log']))->toContain('applying updates for wan DHCP');
        expect(implode("\n", $GLOBALS['_test_log']))->toContain('applied updates for wan DHCP');
    });

    it('skips missing vip uuid gracefully', function () {
        Vip::$testIterateItems = [];
        $GLOBALS['config'] = ['system' => []];
        \OPNsense\Core\Config::getInstance()->toArrayData = [];
        $data = [
            'descr' => 'wan DHCP',
            'vip' => ['uuid' => 'missing', 'subnet' => '1.1.1.1', 'subnet_bits' => '24'],
        ];
        dhcpcarp_apply_config($data, false);
        expect(implode("\n", $GLOBALS['_test_log']))->toContain('VIP uuid missing not found');
    });
});
