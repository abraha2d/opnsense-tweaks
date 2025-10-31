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
$reason = getenv('reason');
$new_ip_address = getenv('new_ip_address');
$new_subnet_cidr = getenv('new_subnet_cidr');
$new_routers = getenv('new_routers');

$ipv4_reasons = array("BOUND", "REBOOT");
$ipv6_reasons = array("BOUND6", "REBOOT6", "ROUTERADVERT");

if (in_array($reason, $ipv4_reasons)) {
  $ipv6 = false;
} else if (in_array($reason, $ipv6_reasons)) {
  $ipv6 = true;
  $ra = $reason === "ROUTERADVERT";
} else {
  exit(0);
}

if ($ipv6) {
  $new_ip_address = getenv('new_dhcp6_ia_na1_ia_addr1');
  $new_subnet_cidr = getenv('nd1_prefix_information1_length');
  $new_routers = getenv('nd1_from');
  $new_prefixes = array();

  $i = 1;
  while (true) {
    $_addr = getenv("new_dhcp6_ia_pd1_prefix$i");
    $_len = getenv("new_dhcp6_ia_pd1_prefix{$i}_length");
    if (!empty($_addr) && !empty($_len)) {
      $new_prefixes[$i] = array("addr"=>$_addr, "len"=>$_len);
      $last_prefix_num = $i++;
    } else {
      break;
    }
  }
}

# Die if we don't have the necessary info
if ($ipv6 && $ra && (empty($interface) || empty($new_subnet_cidr) || empty($new_routers))) {
  log_error("[ra $reason] Did not get one of '$interface', '$new_subnet_cidr', '$new_routers'");
  exit(1);
} else if ($ipv6 && !$ra && (empty($interface) || empty($new_ip_address))) {
  log_error("[v6 $reason] Did not get one of '$interface', '$new_ip_address'");
  exit(1);
} else if (!$ipv6 && (empty($interface) || empty($new_ip_address) || empty($new_subnet_cidr) || empty($new_routers))) {
  log_error("[v4 $reason] Did not get one of '$interface', '$new_ip_address', '$new_subnet_cidr', '$new_routers'");
  exit(1);
}

if ($ipv6) {
  if ($ra) {
    //log_error("[ra] $interface: */$new_subnet_cidr, gateway $new_routers");
  } else {
    log_error("[v6] $interface: $new_ip_address");
    foreach ($new_prefixes as $i => $prefix) {
      $_addr = $prefix["addr"];
      $_len = $prefix["len"];
      log_error("[v6] $interface prefix $i: $_addr/$_len");
    }
  }
} else {
  log_error("[v4] $interface: $new_ip_address/$new_subnet_cidr, gateway $new_routers");
}

# Translate interface name
$ifname = convert_real_interface_to_friendly_interface_name($interface);
//log_msg("Mapped $interface to $ifname");

if ($ipv6) {
  $descr = "$ifname DHCPv6";
} else {
  $descr = "$ifname DHCP";
}

# Find existing VIP config
function vipIterator() {
  foreach ((new OPNsense\Interfaces\Vip())->vip->iterateItems() as $id => $item) {
    $record = [];
    foreach ($item->iterateItems() as $key => $value) {
      $record[$key] = (string)$value;
    }
    $record['uuid'] = (string)$item->getAttributes()['uuid'];
    yield $record;
  }
}

$a_vip = iterator_to_array(vipIterator());
$vid = array_search($descr, array_column($a_vip, 'descr'));
If ($vid === false) {
  log_error("Did not find VIP for $ifname");
  exit(1);
}

//log_msg("Found $ifname VIP at index $vid");
$subnet = $a_vip[$vid]['subnet'];
$subnet_bits = $a_vip[$vid]['subnet_bits'];
//log_msg("$ifname VIP: $subnet/$subnet_bits");

# Find existing gateway config
$gateways = new \OPNsense\Routing\Gateways();
$a_gateway_item = iterator_to_array($gateways->gatewayIterator());
$gid = array_search($descr, array_column($a_gateway_item, 'descr'));
if ($gid === false) {
  log_error("Did not find gateway for $ifname");
  exit(1);
}

//log_msg("Found $ifname gateway at index $gid");
$gateway = $a_gateway_item[$gid]['gateway'];
//log_msg("$ifname gateway: $gateway");

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
$aid = array_search($descr, array_column($a_alias, 'description'));
if ($aid === false) {
  log_error("Did not find firewall alias for $ifname");
  exit(1);
}

//log_msg("Found firewall alias for $ifname at index $aid");
$alias_address = $a_alias[$aid]['content'];
//log_msg("$ifname firewall alias: $alias_address");

# Update NPTv6 rules, if needed
function nptRuleIterator() {
  foreach ((new \OPNsense\Firewall\Filter())->npt->rule->iterateItems() as $id => $item) {
    $record = [];
    foreach ($item->iterateItems() as $key => $value) {
      $record[$key] = (string)$value;
    }
    $record['uuid'] = (string)$item->getAttributes()['uuid'];
    yield $record;
  }
}

$a_nptRule = iterator_to_array(nptRuleIterator());
$nptRules_updated = false;

foreach ($new_prefixes as $i => $prefix) {
  $nrid = array_search($descr . " prefix $i", array_column($a_nptRule, 'description'));
  if ($nrid === false) {
    log_error("Did not find NPTv6 rule for $ifname prefix $i");
    continue;
  }

  $nptRule_dest = $a_nptRule[$nrid]['destination_net'];
  $new_prefix = $prefix["addr"] . "/" . $prefix["len"];

  if ($nptRule_dest == $new_prefix) {
    log_msg("[v6] Nothing to update for $ifname prefix $i");
  } else {
    log_error("Updating $ifname prefix $i NPTv6 rule from $nptRule_dest to $new_prefix");
    $filter = new \OPNsense\Firewall\Filter();
    $filter_node = $filter->getNodeByReference('npt.rule.' . $a_nptRule[$nrid]['uuid']);
    $filter_node->setNodes(['destination_net' => $new_prefix]);
    $filter->serializeToConfig();
    $nptRules_updated = true;
  }
}

# Don't do anything if the new lease matches the existing config
if ($ipv6 && $ra
  && $subnet_bits == $new_subnet_cidr
  && $gateway == $new_routers
) {
  //log_msg("[ra] Nothing to update for $ifname");
  exit(0);
} else if ($ipv6 && !$ra
  && $subnet == $new_ip_address
  && !$nptRules_updated
) {
  log_msg("[v6] Nothing to update for $ifname");
  exit(0);
} else if (!$ipv6
  && $subnet == $new_ip_address
  && $subnet_bits == $new_subnet_cidr
  && $gateway == $new_routers
  && $alias_address == $new_ip_address
) {
  log_msg("[v4] Nothing to update for $ifname");
  exit(0);
}

# Update existing gateway
if (!$ipv6 || $ipv6 && $ra) {
  log_error("Updating $ifname gateway from $gateway to $new_routers");
  $gateways->createOrUpdateGateway(['gateway' => $new_routers], $a_gateway_item[$gid]['uuid']);
}

# Update existing firewall alias
if (!$ipv6 || $ipv6 && !$ra) {
  log_error("Updating $ifname firewall alias from $alias_address to $new_ip_address");
  $alias = new \OPNsense\Firewall\Alias();
  $alias_node = $alias->getNodeByReference('aliases.alias.' . $a_alias[$aid]['uuid']);
  $alias_node->setNodes(['content' => $new_ip_address]);
  $alias->serializeToConfig();
}

# De-configure the virtual IP
log_msg("Bringing $ifname VIP down");
interface_vip_bring_down($a_vip[$vid]);

# Update the VIP config
log_error("Updating $ifname VIP from $subnet/$subnet_bits to $new_ip_address/$new_subnet_cidr");
if (!$ipv6 || $ipv6 && !$ra) {
  $a_vip[$vid]['subnet'] = $new_ip_address;
}
if (!$ipv6 || $ipv6 && $ra) {
  $a_vip[$vid]['subnet_bits'] = $new_subnet_cidr;
}
$vip = new OPNsense\Interfaces\Vip();
$vip_node = $vip->getNodeByReference('vip.' . $a_vip[$vid]['uuid']);
$vip_node->setNodes($a_vip[$vid]);
$vip->serializeToConfig();

# Serialize and write config
$config = OPNsense\Core\Config::getInstance()->toArray(listtags());
write_config();

# Re-configure the virtual IP
log_msg("Re-configuring $ifname VIP");
switch ($a_vip[$vid]['mode']) {
  case 'ipalias':
    interface_ipalias_configure($a_vip[$vid]);
    break;
  case 'carp':
    interface_carp_configure($a_vip[$vid]);
    break;
}

# Re-configure everything else
system_routing_configure();
plugins_configure('monitor');
filter_configure();

# Synchronize config to backup
configd_run('filter sync');
