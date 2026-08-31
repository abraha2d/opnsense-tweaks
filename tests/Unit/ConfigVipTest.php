<?php

require_once __DIR__ . '/../../src/dhcpcarp-config-vip.php';

use Tests\Stubs\FakeVipItem;
use OPNsense\Interfaces\Vip;

describe('dhcpcarp_check_vip', function () {
    it('returns true for matching v4', function () {
        $found = ['subnet' => '1.2.3.4', 'subnet_bits' => '24'];
        expect(dhcpcarp_check_vip($found, '1.2.3.4', '24', false, false))->toBeTrue();
    });

    it('returns false for v4 ip mismatch', function () {
        $found = ['subnet' => '1.2.3.4', 'subnet_bits' => '24'];
        expect(dhcpcarp_check_vip($found, '1.2.3.5', '24', false, false))->toBeFalse();
    });

    it('returns false for v4 bits mismatch', function () {
        $found = ['subnet' => '1.2.3.4', 'subnet_bits' => '24'];
        expect(dhcpcarp_check_vip($found, '1.2.3.4', '25', false, false))->toBeFalse();
    });

    it('checks only bits for ipv6 RA', function () {
        $found = ['subnet' => '2001:db8::', 'subnet_bits' => '64'];
        expect(dhcpcarp_check_vip($found, '2001:db8::1', '64', true, true))->toBeTrue();
        expect(dhcpcarp_check_vip($found, 'anything', '48', true, true))->toBeFalse();
    });

    it('checks only subnet for ipv6 non-RA', function () {
        $found = ['subnet' => '2001:db8::1', 'subnet_bits' => '128'];
        expect(dhcpcarp_check_vip($found, '2001:db8::1', '999', true, false))->toBeTrue();
        expect(dhcpcarp_check_vip($found, '2001:db8::2', '128', true, false))->toBeFalse();
    });
});

describe('dhcpcarp_build_vipData', function () {
    it('updates both subnet and bits for v4', function () {
        $found = ['uuid' => 'u1', 'subnet' => '1.2.3.4', 'subnet_bits' => '24'];
        expect(dhcpcarp_build_vipData($found, '5.6.7.8', '16', false, false))->toBe([
            'uuid' => 'u1', 'subnet' => '5.6.7.8', 'subnet_bits' => '16',
        ]);
    });

    it('updates only subnet for ipv6 non-RA', function () {
        $found = ['uuid' => 'u1', 'subnet' => '2001:db8::1', 'subnet_bits' => '64'];
        expect(dhcpcarp_build_vipData($found, '2001:db8::2', '48', true, false))->toBe([
            'uuid' => 'u1', 'subnet' => '2001:db8::2', 'subnet_bits' => '64',
        ]);
    });

    it('updates only bits for ipv6 RA', function () {
        $found = ['uuid' => 'u1', 'subnet' => '2001:db8::1', 'subnet_bits' => '64'];
        expect(dhcpcarp_build_vipData($found, 'ignored', '48', true, true))->toBe([
            'uuid' => 'u1', 'subnet' => '2001:db8::1', 'subnet_bits' => '48',
        ]);
    });
});

describe('dhcpcarp_find_vip', function () {
    it('finds by description', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'wan DHCP', 'subnet' => '1.2.3.4', 'subnet_bits' => '24', 'mode' => 'carp', 'uuid' => 'uuid-1']),
            new FakeVipItem(['descr' => 'opt1 DHCP', 'subnet' => '2.2.2.2', 'subnet_bits' => '24', 'mode' => 'carp', 'uuid' => 'uuid-2']),
        ];
        $found = dhcpcarp_find_vip('wan DHCP');
        expect($found)->not->toBeNull()
            ->and($found['descr'])->toBe('wan DHCP')
            ->and($found['uuid'])->toBe('uuid-1');
    });

    it('returns null when not found', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'wan DHCP', 'subnet' => '1.2.3.4', 'subnet_bits' => '24', 'mode' => 'carp', 'uuid' => 'uuid-1']),
        ];
        expect(dhcpcarp_find_vip('missing DHCP'))->toBeNull();
    });
});

describe('dhcpcarp_apply_vipData', function () {
    it('updates matching uuid node', function () {
        $uuid = 'vip-uuid-123';
        $item = new FakeVipItem(['descr' => 'wan DHCP', 'subnet' => '1.1.1.1', 'subnet_bits' => '24', 'mode' => 'carp', 'uuid' => $uuid]);
        Vip::$testIterateItems = [$item];

        // build a real Vip model that will lookup via static items
        $data = ['uuid' => $uuid, 'subnet' => '9.9.9.9', 'subnet_bits' => '24'];
        dhcpcarp_apply_vipData($data);

        // The model instance inside apply_vipData creates a new Vip(); its getNodeByReference should resolve
        // via static items. Our FakeVipItem was mutated via setNodes.
        // Verify via the static item still reflects update
        expect((string)$item->testChildren['subnet'])->toBe('9.9.9.9');
        expect($GLOBALS['_test_serialize_calls'])->toContain(Vip::class);
    });

    it('logs and skips when uuid not found', function () {
        Vip::$testIterateItems = [];
        dhcpcarp_apply_vipData(['uuid' => 'missing', 'subnet' => '1.1.1.1', 'subnet_bits' => '24']);
        expect(implode("\n", $GLOBALS['_test_log']))->toContain('VIP uuid missing not found');
    });
});
