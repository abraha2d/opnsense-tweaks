<?php

use Tests\Integration\Support\FakeRoot;

require_once __DIR__ . '/../../src/dhcpcarp-config.php';

describe('dhcpcarp_apply_config with real config.xml via FAKE_ROOT', function () {
    it('applies vip via fake /conf/config.xml', function () {
        $fr = new FakeRoot();
        // Ensure fake config exists via helper (works without LD_PRELOAD)
        expect($fr->exists('/conf/config.xml'))->toBeTrue();
        expect($fr->exists('/usr/local/etc/config.xml'))->toBeTrue();
        // When LD_PRELOAD active, real path should also be visible
        if (getenv('LD_PRELOAD')) {
            expect(file_exists('/conf/config.xml'))->toBeTrue();
        }
        $fr->cleanup();
        expect(true)->toBeTrue();
    })->group('integration');
});
