#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once('filter.inc');
require_once('legacy_bindings.inc');
require_once('interfaces.inc');
require_once('util.inc');

# Get info from dhcpcd
$new_ip_address = getenv('new_ip_address');
$new_subnet_cidr = getenv('new_subnet_cidr');

# Die if we don't have the necessary info
if (empty($new_ip_address) || empty($new_subnet_cidr)) {
  exit(1);
}

# Find existing CARP config
$a_vip = &config_read_array('virtualip', 'vip');
$vid = array_search('wan', array_column($a_vip, 'interface'));

# Don't do anything if the new lease matches the existing config
if ($a_vip[$vid]['subnet'] == $new_ip_address && $a_vip[$vid]['subnet_bits'] == $new_subnet_cidr) {
  exit(0);
}

# De-configure the CARP virtual IP
log_error("De-configuring $a_vip[$vid]['interface']");
interface_vip_bring_down($a_vip[$vid]);

# Update the CARP config
log_error("Updating '$a_vip[$vid]['interface']' config to $new_ip_address/$new_subnet_cidr");
$a_vip[$vid]['subnet'] = $new_ip_address;
$a_vip[$vid]['subnet_bits'] = $new_subnet_cidr;
write_config();

# Re-configure the CARP virtual IP
log_error("Re-configuring $a_vip[$vid]['interface']");
interface_carp_configure($a_vip[$vid]);
filter_configure();

# Synchronize config to backup
configd_run('filter sync');
