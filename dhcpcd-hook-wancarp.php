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
  log_error("Did not get one of '$interface', '$new_ip_address', '$new_subnet_cidr', '$new_routers'");
  exit(1);
}
log_error("$interface DHCP: $new_ip_address/$new_subnet_cidr, gateway $new_routers");

# Translate interface name
$ifname = convert_real_interface_to_friendly_interface_name($interface);
log_error("Mapped $interface to $ifname");

# Find existing CARP config
function carpIterator() {
  foreach ((new OPNsense\Interfaces\Vip())->vip->iterateItems() as $id => $item) {
    if ($item->mode == 'carp') {
      $record = [];
      foreach ($item->iterateItems() as $key => $value) {
        $record[$key] = (string)$value;
      }
      $record['uuid'] = (string)$item->getAttributes()['uuid'];
      yield $record;
    }
  }
}

$a_vip = iterator_to_array(carpIterator());
$vid = array_search($ifname, array_column($a_vip, 'interface'));
if ($vid === false) {
  log_error("Did not find CARP for $ifname");
  exit(1);
}

log_error("Found $ifname CARP at index $vid");
$subnet = $a_vip[$vid]['subnet'];
$subnet_bits = $a_vip[$vid]['subnet_bits'];
log_error("$ifname CARP: $subnet/$subnet_bits");

# Find existing gateway config
$gateways = new \OPNsense\Routing\Gateways();
$a_gateway_item = iterator_to_array($gateways->gatewayIterator());
$gid = array_search($ifname, array_column($a_gateway_item, 'interface'));
if ($gid === false) {
  log_error("Did not find gateway for $ifname");
  exit(1);
}

log_error("Found $ifname gateway at index $gid");
$gateway = $a_gateway_item[$gid]['gateway'];
log_error("$ifname gateway: $gateway");

# Find existing firewall alias
function aliasIterator() {
  foreach ((new \OPNsense\Firewall\Alias())->aliases->alias->iterateItems() as $id => $item) {
    $record = [];
    foreach ($item->iterateItems() as $key => $value) {
      $record[$key] = (string)$value;
    }
    $record['uuid'] = (string)$item->getAttributes()['uuid'];
    yield $record;
  }
}

$a_alias = iterator_to_array(aliasIterator());
$aid = array_search("_{$ifname}_address", array_column($a_alias, 'name'));
if ($aid === false) {
  log_error("Did not find firewall alias _{$ifname}_address");
  exit(1);
}

log_error("Found firewall alias for $ifname at index $aid");
$alias_address = $a_alias[$aid]['content'];
log_error("_{$ifname}_address: $alias_address");

# Don't do anything if the new lease matches the existing config
if ($subnet == $new_ip_address
  && $subnet_bits == $new_subnet_cidr
  && $gateway == $new_routers
  && $alias_address == $new_ip_address
) {
  log_error("Nothing to update for $ifname");
  exit(0);
}

# Update existing gateway
log_error("Updating $ifname gateway from $gateway to $new_routers");
$gateways->createOrUpdateGateway(['gateway' => $new_routers], $a_gateway_item[$gid]['uuid']);

# Update existing firewall alias
log_error("Updating firewall alias _{$ifname}_address from $alias_address to $new_ip_address");
$alias = new \OPNsense\Firewall\Alias();
$alias_node = $alias->getNodeByReference('aliases.alias.' . $a_alias[$aid]['uuid']);
$alias_node->setNodes(['content' => $new_ip_address]);
$alias->serializeToConfig();

# De-configure the CARP virtual IP
log_error("Bringing $ifname CARP down");
interface_vip_bring_down($a_vip[$vid]);

# Update the CARP config
log_error("Updating $ifname CARP IP address from $subnet/$subnet_bits to $new_ip_address/$new_subnet_cidr");
$a_vip[$vid]['subnet'] = $new_ip_address;
$a_vip[$vid]['subnet_bits'] = $new_subnet_cidr;
$vip = new OPNsense\Interfaces\Vip();
$vip_node = $vip->getNodeByReference('vip.' . $a_vip[$vid]['uuid']);
$vip_node->setNodes($a_vip[$vid]);
$vip->serializeToConfig();

# Serialize and write config
$config = OPNsense\Core\Config::getInstance()->toArray(listtags());
write_config();

# Re-configure the CARP virtual IP
log_error("Re-configuring $ifname CARP");
interface_carp_configure($a_vip[$vid]);
system_routing_configure();
plugins_configure('monitor');
filter_configure();

# Synchronize config to backup
configd_run('filter sync');
