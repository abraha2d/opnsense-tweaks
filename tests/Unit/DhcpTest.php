<?php

require_once __DIR__ . '/../../src/dhcpcarp-dhcp.php';

describe('dhcpcarp-dhcp helpers', function () {
    it('functions exist', function () {
        expect(function_exists('dhcpcarp_start_dhcpcd'))->toBeTrue();
        expect(function_exists('dhcpcarp_stop_dhcpcd'))->toBeTrue();
    });

    // start/stop involve ifconfig/dhcpcd/pid files and are not safely unit-testable without mocking exec/shell_exec.
    // We verify the build logic for address request is covered via InterfacesTest.
});
