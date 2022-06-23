#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once('filter.inc');
require_once('interfaces.inc');
require_once('legacy_bindings.inc');
require_once('plugins.inc');
require_once('system.inc');
require_once('util.inc');

# Get info from dhcpcd
$interface = getenv('interface');
$new_ip_address = getenv('new_ip_address');
$new_subnet_cidr = getenv('new_subnet_cidr');
$new_routers = getenv('new_routers');

# Die if we don't have the necessary info
if (empty($interface) || empty($new_ip_address) || empty($new_subnet_cidr) || empty($new_routers)) {
  exit(1);
}

# Translate interface name
$ifname = convert_real_interface_to_friendly_interface_name($interface);

# Find existing CARP config
$a_vip = &config_read_array('virtualip', 'vip');
$vid = array_search($ifname, array_column($a_vip, 'interface'));
log_error("Found $ifname CARP at index $vid");
$subnet = $a_vip[$vid]['subnet'];

# Don't do anything if the new lease matches the existing config
if ($a_vip[$vid]['subnet'] == $new_ip_address && $a_vip[$vid]['subnet_bits'] == $new_subnet_cidr) {
  exit(0);
}

# Update existing gateway
$a_gateway_item = &config_read_array('gateways', 'gateway_item');
$gid = array_search($ifname, array_column($a_gateway_item, 'interface'));
log_error("Updating gateway $gid to $new_routers");
$a_gateway_item[$gid]['gateway'] = $new_routers;

# Update existing NAT outbound rule
$a_out = &config_read_array('nat', 'outbound', 'rule');
$oids = array_keys(array_column($a_out, 'target'), $subnet);
foreach ($oids as $oid) {
  log_error("Updating outbound NAT rule $oid to $new_ip_address");
  $a_out[$oid]['target'] = $new_ip_address;
}

# De-configure the CARP virtual IP
log_error("Bringing $ifname CARP down");
interface_vip_bring_down($a_vip[$vid]);

# Update the CARP config
log_error("Updating $ifname CARP IP address to $new_ip_address/$new_subnet_cidr");
$a_vip[$vid]['subnet'] = $new_ip_address;
$a_vip[$vid]['subnet_bits'] = $new_subnet_cidr;
write_config();

# Re-configure the CARP virtual IP
log_error("Re-configuring $ifname CARP");
interface_carp_configure($a_vip[$vid]);
system_routing_configure();
plugins_configure('monitor');
filter_configure();

# Synchronize config to backup
configd_run('filter sync');
