<?php

use Tests\Integration\Support\FakeRoot;

require_once __DIR__ . '/../../src/dhcpcarp-peerops.php';

describe('dhcpcarp peer ops via FAKE_ROOT', function () {
    it('writes and reads pid file via FAKE_ROOT', function () {
        $fr = new FakeRoot();
        $pidFile = '/var/run/dhcpcd/vtnet1.pid';
        $fr->put($pidFile, "9999\n");
        expect($fr->exists($pidFile))->toBeTrue();
        expect(file_exists($pidFile))->toBeTrue();
        expect(trim(file_get_contents($pidFile)))->toBe('9999');
        unlink($pidFile);
        expect($fr->exists($pidFile))->toBeFalse();
        expect(file_exists($pidFile))->toBeFalse();
        $fr->cleanup();
    })->group('integration');

    it('peer helper handles FAKE_ROOT for dhcpcarp files', function () {
        $fr = new FakeRoot();
        $fr->put('/var/run/dhcpcd/test.pid', '111');
        expect($fr->exists('/var/run/dhcpcd/test.pid'))->toBeTrue();
        expect(file_exists('/var/run/dhcpcd/test.pid'))->toBeTrue();
        $fr->cleanup();
        expect($fr->exists('/var/run/dhcpcd/test.pid'))->toBeFalse();
    })->group('integration');
});
