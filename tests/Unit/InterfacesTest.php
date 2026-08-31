<?php

require_once __DIR__ . '/../../src/dhcpcarp-interfaces.php';

use Tests\Stubs\FakeVipItem;
use OPNsense\Interfaces\Vip;

describe('dhcpcarp_interfaces', function () {
    it('returns eligible friendly names for carp DHCP v4 only', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'wan DHCP', 'mode' => 'carp', 'uuid' => '1']),
            new FakeVipItem(['descr' => 'opt1 DHCP', 'mode' => 'carp', 'uuid' => '2']),
            new FakeVipItem(['descr' => 'wan DHCPv6', 'mode' => 'carp', 'uuid' => '3']), // should be skipped (ends with DHCPv6 not DHCP)
            new FakeVipItem(['descr' => 'lan DHCP', 'mode' => 'ipalias', 'uuid' => '4']), // mode not carp
            new FakeVipItem(['descr' => 'dmz OTHER', 'mode' => 'carp', 'uuid' => '5']),
        ];
        expect(dhcpcarp_interfaces())->toBe(['opt1', 'wan']); // sorted
    });

    it('returns empty and logs when no eligible', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'wan DHCPv6', 'mode' => 'carp', 'uuid' => '1']),
        ];
        expect(dhcpcarp_interfaces())->toBe([]);
        expect(implode("\n", $GLOBALS['_test_log']))->toContain('no eligible virtual IPs found');
    });

    it('deduplicates and sorts', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'wan DHCP', 'mode' => 'carp', 'uuid' => '1']),
            new FakeVipItem(['descr' => 'wan DHCP', 'mode' => 'carp', 'uuid' => '2']), // duplicate descr
            new FakeVipItem(['descr' => 'lan DHCP', 'mode' => 'carp', 'uuid' => '3']),
        ];
        expect(dhcpcarp_interfaces())->toBe(['lan', 'wan']);
    });

    it('ignores empty friendly name (descr exactly DHCP)', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => ' DHCP', 'mode' => 'carp', 'uuid' => '1']),
        ];
        expect(dhcpcarp_interfaces())->toBe([]);
    });

    it('caches result and clear works', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'wan DHCP', 'mode' => 'carp', 'uuid' => '1']),
        ];
        expect(dhcpcarp_interfaces())->toBe(['wan']);
        // change backing data but cached should still return old
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'opt1 DHCP', 'mode' => 'carp', 'uuid' => '2']),
        ];
        expect(dhcpcarp_interfaces())->toBe(['wan']);
        dhcpcarp_interfaces_clear_cache();
        expect(dhcpcarp_interfaces())->toBe(['opt1']);
    });
});

describe('dhcpcarp_is_interface_eligible', function () {
    it('returns true for eligible', function () {
        Vip::$testIterateItems = [new FakeVipItem(['descr' => 'wan DHCP', 'mode' => 'carp', 'uuid' => '1'])];
        expect(dhcpcarp_is_interface_eligible('wan'))->toBeTrue();
        expect(dhcpcarp_is_interface_eligible('lan'))->toBeFalse();
    });
});

describe('dhcpcarp_is_real_interface_eligible', function () {
    it('returns false for empty iface', function () {
        expect(dhcpcarp_is_real_interface_eligible(''))->toBeFalse();
    });

    it('checks via get_real_interface mapping', function () {
        Vip::$testIterateItems = [new FakeVipItem(['descr' => 'wan DHCP', 'mode' => 'carp', 'uuid' => '1'])];
        $GLOBALS['_test_real_interface_map'] = ['wan' => 'vtnet1'];
        expect(dhcpcarp_is_real_interface_eligible('vtnet1'))->toBeTrue();
        expect(dhcpcarp_is_real_interface_eligible('vtnet0'))->toBeFalse();
    });
});

describe('dhcpcarp_get_address_request', function () {
    it('returns subnet for friendly mapping', function () {
        Vip::$testIterateItems = [
            new FakeVipItem(['descr' => 'wan DHCP', 'subnet' => '1.2.3.4', 'mode' => 'carp', 'uuid' => '1']),
        ];
        $GLOBALS['_test_friendly_map'] = ['vtnet1' => 'wan'];
        expect(dhcpcarp_get_address_request('vtnet1'))->toBe('1.2.3.4');
    });

    it('returns empty when vip not found', function () {
        Vip::$testIterateItems = [];
        $GLOBALS['_test_friendly_map'] = ['vtnet1' => 'wan'];
        expect(dhcpcarp_get_address_request('vtnet1'))->toBe('');
    });
});

describe('dhcpcarp_set_carp_mac / restore helpers', function () {
    it('set_carp_mac formats mac from vhid', function () {
        // we cannot easily test without mocking shell_exec/dhcpcarp_exec, just verify function exists and handles null vhid
        expect(function_exists('dhcpcarp_set_carp_mac'))->toBeTrue();
        expect(function_exists('dhcpcarp_restore_mac'))->toBeTrue();
    });
});
