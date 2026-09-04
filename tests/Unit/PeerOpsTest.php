<?php

require_once __DIR__ . '/../../src/dhcpcarp-peerops.php';

use OPNsense\Core\Config;

describe('dhcpcarp_get_peer_ip', function () {
    it('returns synchronizetoip from config when valid', function () {
        $obj = new stdClass();
        $obj->hasync = new stdClass();
        $obj->hasync->synchronizetoip = '192.168.1.2';
        Config::getInstance()->objectData = $obj;
        expect(dhcpcarp_get_peer_ip())->toBe('192.168.1.2');
    });

    it('falls back to .dhcpcarp_peer file when config empty', function () {
        $obj = new stdClass();
        $obj->hasync = new stdClass();
        $obj->hasync->synchronizetoip = '';
        Config::getInstance()->objectData = $obj;
        $f = __DIR__ . '/../../.dhcpcarp_peer';
        if (file_exists($f)) {
            unlink($f);
        }
        file_put_contents($f, "10.0.0.2\n");
        expect(dhcpcarp_get_peer_ip())->toBe('10.0.0.2');
        if (file_exists($f)) {
            unlink($f);
        }
    });

    it('returns null when neither config nor file valid', function () {
        $obj = new stdClass();
        $obj->hasync = new stdClass();
        $obj->hasync->synchronizetoip = '';
        Config::getInstance()->objectData = $obj;
        $f = __DIR__ . '/../../.dhcpcarp_peer';
        if (file_exists($f)) {
            unlink($f);
        }
        expect(dhcpcarp_get_peer_ip())->toBeNull();
        file_put_contents($f, "not-an-ip\n");
        expect(dhcpcarp_get_peer_ip())->toBeNull();
        if (file_exists($f)) {
            unlink($f);
        }
    });

    it('rejects invalid IP in config', function () {
        $obj = new stdClass();
        $obj->hasync = new stdClass();
        $obj->hasync->synchronizetoip = 'not-an-ip';
        Config::getInstance()->objectData = $obj;
        $f = __DIR__ . '/../../.dhcpcarp_peer';
        if (file_exists($f)) {
            unlink($f);
        }
        expect(dhcpcarp_get_peer_ip())->toBeNull();
    });
});

describe('dhcpcarp_is_peer_primary', function () {
    it('returns true when synchronizetoip is set', function () {
        $obj = new stdClass();
        $obj->hasync = new stdClass();
        $obj->hasync->synchronizetoip = '192.168.1.2';
        Config::getInstance()->objectData = $obj;
        expect(dhcpcarp_is_peer_primary())->toBeTrue();
    });

    it('returns false when synchronizetoip empty', function () {
        $obj = new stdClass();
        $obj->hasync = new stdClass();
        $obj->hasync->synchronizetoip = '';
        Config::getInstance()->objectData = $obj;
        expect(dhcpcarp_is_peer_primary())->toBeFalse();
    });

    it('trims whitespace', function () {
        $obj = new stdClass();
        $obj->hasync = new stdClass();
        $obj->hasync->synchronizetoip = ' 192.168.1.2 ';
        Config::getInstance()->objectData = $obj;
        expect(dhcpcarp_is_peer_primary())->toBeTrue();
    });
});

describe('dhcpcarp_peer_exec validation', function () {
    it('returns false for invalid peer ip', function () {
        [$ok, $out] = dhcpcarp_peer_exec('', 'true');
        expect($ok)->toBeFalse()->and($out)->toContain('invalid peer ip');
        [$ok, $out] = dhcpcarp_peer_exec('not-ip', 'true');
        expect($ok)->toBeFalse();
    });

    it('returns false for empty ip via helper', function () {
        expect(dhcpcarp_is_peer_reachable('', 1))->toBeFalse();
        expect(dhcpcarp_is_peer_reachable(null, 1))->toBeFalse();
    });
});

// Note: hold/release/peer_apply_config require SSH to peer and are not unit-tested without mocking proc_open.
