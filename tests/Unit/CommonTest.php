<?php

require_once __DIR__ . '/../../src/dhcpcarp-common.php';

describe('dhcpcarp_get_dhcp6_prefixes', function () {
    it('returns empty when no env vars set', function () {
        expect(dhcpcarp_get_dhcp6_prefixes())->toBe([]);
    });

    it('parses a single prefix', function () {
        putenv('new_dhcp6_ia_pd1_prefix1=2001:db8::');
        putenv('new_dhcp6_ia_pd1_prefix1_length=56');
        expect(dhcpcarp_get_dhcp6_prefixes())->toBe([
            1 => ['addr' => '2001:db8::', 'len' => '56'],
        ]);
    });

    it('parses multiple prefixes', function () {
        putenv('new_dhcp6_ia_pd1_prefix1=2001:db8::');
        putenv('new_dhcp6_ia_pd1_prefix1_length=56');
        putenv('new_dhcp6_ia_pd1_prefix2=2001:db8:1::');
        putenv('new_dhcp6_ia_pd1_prefix2_length=60');
        putenv('new_dhcp6_ia_pd1_prefix3=2001:db8:2::');
        putenv('new_dhcp6_ia_pd1_prefix3_length=60');
        expect(dhcpcarp_get_dhcp6_prefixes())->toBe([
            1 => ['addr' => '2001:db8::', 'len' => '56'],
            2 => ['addr' => '2001:db8:1::', 'len' => '60'],
            3 => ['addr' => '2001:db8:2::', 'len' => '60'],
        ]);
    });

    it('stops at first gap', function () {
        putenv('new_dhcp6_ia_pd1_prefix1=2001:db8::');
        putenv('new_dhcp6_ia_pd1_prefix1_length=56');
        // skip prefix2 to create gap
        putenv('new_dhcp6_ia_pd1_prefix3=2001:db8:2::');
        putenv('new_dhcp6_ia_pd1_prefix3_length=60');
        expect(dhcpcarp_get_dhcp6_prefixes())->toBe([
            1 => ['addr' => '2001:db8::', 'len' => '56'],
        ]);
    });

    it('ignores incomplete entries (addr without len)', function () {
        putenv('new_dhcp6_ia_pd1_prefix1=2001:db8::');
        putenv('new_dhcp6_ia_pd1_prefix1_length='); // empty len
        expect(dhcpcarp_get_dhcp6_prefixes())->toBe([]);
    });

    it('ignores len without addr', function () {
        putenv('new_dhcp6_ia_pd1_prefix1='); // empty addr
        putenv('new_dhcp6_ia_pd1_prefix1_length=56');
        expect(dhcpcarp_get_dhcp6_prefixes())->toBe([]);
    });
});

describe('dhcpcarp_exec', function () {
    it('returns 0 for successful command', function () {
        expect(dhcpcarp_exec('true'))->toBe(0);
    });

    it('returns non-zero for failing command', function () {
        expect(dhcpcarp_exec('false'))->not->toBe(0);
    });
});

describe('dhcpcarp_log', function () {
    it('writes to STDERR and calls log_error', function () {
        dhcpcarp_log('test message');
        expect($GLOBALS['_test_log'])->toContain('[dhcpcarp] test message');
    });
});
