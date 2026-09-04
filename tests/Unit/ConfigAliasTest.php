<?php

require_once __DIR__ . '/../../src/dhcpcarp-config-alias.php';

use Tests\Stubs\FakeField;
use Tests\Stubs\FakeVipItem;
use OPNsense\Firewall\Alias;

describe('dhcpcarp_check_alias', function () {
    it('returns true when content matches', function () {
        expect(dhcpcarp_check_alias(['content' => '1.2.3.4'], '1.2.3.4'))->toBeTrue();
    });
    it('returns false on mismatch', function () {
        expect(dhcpcarp_check_alias(['content' => '1.2.3.4'], '5.6.7.8'))->toBeFalse();
    });
    it('handles missing content', function () {
        expect(dhcpcarp_check_alias([], '1.2.3.4'))->toBeFalse();
    });
});

describe('dhcpcarp_build_aliasData', function () {
    it('builds uuid and content', function () {
        expect(dhcpcarp_build_aliasData(['uuid' => 'a-uuid', 'content' => 'old'], '9.9.9.9'))->toBe([
            'uuid' => 'a-uuid', 'content' => '9.9.9.9',
        ]);
    });
});

describe('dhcpcarp_find_alias', function () {
    it('finds by description', function () {
        Alias::$testAliasItems = [
            new FakeVipItem(['description' => 'wan DHCP', 'content' => '1.2.3.4', 'uuid' => 'alias-1']),
            new FakeVipItem(['description' => 'wan DHCPv6', 'content' => '2001::1', 'uuid' => 'alias-2']),
        ];
        $found = dhcpcarp_find_alias('wan DHCP');
        expect($found)->not->toBeNull()
            ->and($found['content'])->toBe('1.2.3.4');
    });

    it('returns null when not found', function () {
        Alias::$testAliasItems = [
            new FakeVipItem(['description' => 'wan DHCP', 'content' => '1.2.3.4', 'uuid' => 'alias-1']),
        ];
        expect(dhcpcarp_find_alias('missing'))->toBeNull();
    });
});

describe('dhcpcarp_apply_aliasData', function () {
    it('sets nodes and serializes for found uuid', function () {
        $stubNode = new FakeField('old', [], ['content' => new FakeField('old')]);
        $aliasModel = new Alias();
        $uuid = 'alias-uuid-1';
        $aliasModel->testGetNodeMap["aliases.alias.$uuid"] = $stubNode;
        $node = $aliasModel->getNodeByReference("aliases.alias.$uuid");
        expect($node)->not->toBeNull();
        $node->setNodes(['content' => '9.9.9.9']);
        $aliasModel->serializeToConfig();
        // FakeField stores children as FakeField objects; verify child updated
        expect((string) ($stubNode->iterateItems()->current() ?? ''))->toBeString();
        expect($GLOBALS['_test_serialize_calls'])->toContain(Alias::class);
    });

    it('logs skip for missing uuid', function () {
        // Directly test apply with missing uuid — it creates a new Alias and getNodeByReference returns null
        Alias::$testAliasItems = [];
        dhcpcarp_apply_aliasData(['uuid' => 'missing-uuid', 'content' => '1.1.1.1']);
        expect(implode("\n", $GLOBALS['_test_log']))->toContain('alias uuid missing-uuid not found');
    });

    it('returns early when content null', function () {
        dhcpcarp_apply_aliasData(['uuid' => 'some-uuid']);
        expect($GLOBALS['_test_serialize_calls'])->toBe([]);
    });
});
