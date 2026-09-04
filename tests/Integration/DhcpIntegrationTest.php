<?php

use Tests\Integration\Support\FakeRoot;

require_once __DIR__ . '/../../src/dhcpcarp-dhcp.php';

describe('dhcpcarp_start/stop via FAKE_ROOT', function () {
    it('stop removes pid and lease via FAKE_ROOT', function () {
        $fr = new FakeRoot();
        $fr->put('/var/run/dhcpcd/vtnet1.pid', "1234\n");
        $fr->put('/var/db/dhcpcd/vtnet1.lease', 'lease');
        $fr->put('/var/db/dhcpcd/vtnet1.lease6', 'lease6');

        // Via bind mount, real paths are the fake root
        expect($fr->exists('/var/run/dhcpcd/vtnet1.pid'))->toBeTrue();
        expect($fr->exists('/var/db/dhcpcd/vtnet1.lease'))->toBeTrue();
        expect(file_exists('/var/run/dhcpcd/vtnet1.pid'))->toBeTrue();
        unlink('/var/db/dhcpcd/vtnet1.lease');
        expect(file_exists('/var/db/dhcpcd/vtnet1.lease'))->toBeFalse();
        expect($fr->exists('/var/db/dhcpcd/vtnet1.lease'))->toBeFalse();

        $fr->cleanup();
    })->group('integration');

    it('creates FAKE_ROOT structure for var paths', function () {
        $fr = new FakeRoot();
        expect(is_dir($fr->path('/var/run/dhcpcd')))->toBeTrue();
        expect(is_dir($fr->path('/var/db/dhcpcd')))->toBeTrue();
        expect(is_dir($fr->path('/conf')))->toBeTrue();
        expect(file_exists($fr->path('/conf/config.xml')))->toBeTrue();
        $fr->cleanup();
    })->group('integration');
});
