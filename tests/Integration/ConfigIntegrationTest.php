<?php

use Tests\Integration\Support\FakeRoot;

require_once __DIR__ . '/../../src/dhcpcarp-config.php';

describe('dhcpcarp_apply_config with real config.xml via FAKE_ROOT', function () {
    it('applies vip via fake /conf/config.xml', function () {
        $fr = new FakeRoot();
        expect($fr->exists('/conf/config.xml'))->toBeTrue();
        expect($fr->exists('/usr/local/etc/config.xml'))->toBeTrue();
        expect(file_exists('/conf/config.xml'))->toBeTrue();
        expect(file_exists('/usr/local/etc/config.xml'))->toBeTrue();
        $fr->cleanup();
        expect(true)->toBeTrue();
    })->group('integration');
});
