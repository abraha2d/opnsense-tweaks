<?php

/*
 * Pest bootstrap: provide minimal OPNsense stubs so that
 * dhcpcarp-*.php files can be required without a real OPNsense install.
 * Stubs are intentionally lightweight — they mimic the MVC surface used
 * by the tweak scripts, not the full framework.
 */

// --- Global function stubs (must exist before config.inc etc. is loaded) ---
if (!function_exists('log_error')) {
    function log_error($msg) { /* capture in $GLOBALS for assertions if needed */ $GLOBALS['_test_log'][] = $msg; }
}
if (!function_exists('write_config')) {
    function write_config($desc = '', $backup = true) { $GLOBALS['_test_write_config_calls'][] = $desc; return true; }
}
if (!function_exists('system_routing_configure')) {
    function system_routing_configure($verbose = false, $interface_map = null, $monitor = true, $family = null) { $GLOBALS['_test_system_routing_calls'][] = func_get_args(); return true; }
}
if (!function_exists('filter_configure')) {
    function filter_configure() { $GLOBALS['_test_filter_configure_calls'][] = true; return true; }
}
if (!function_exists('plugins_configure')) {
    function plugins_configure($hook, $verbose = false, $args = []) { $GLOBALS['_test_plugins_configure_calls'][] = $hook; return true; }
}
if (!function_exists('configd_run')) {
    function configd_run($cmd, $detach = false) { $GLOBALS['_test_configd_run_calls'][] = $cmd; return true; }
}
if (!function_exists('get_real_interface')) {
    function get_real_interface($iface = 'wan', $family = 'all') { return $GLOBALS['_test_real_interface_map'][$iface] ?? $iface; }
}
if (!function_exists('convert_real_interface_to_friendly_interface_name')) {
    function convert_real_interface_to_friendly_interface_name($interface = 'wan') {
        $map = $GLOBALS['_test_friendly_map'] ?? [];
        if (isset($map[$interface])) { return $map[$interface]; }
        // fallback: reverse lookup via real_interface_map
        foreach (($GLOBALS['_test_real_interface_map'] ?? []) as $friendly => $real) {
            if ($real === $interface) { return $friendly; }
        }
        return $interface;
    }
}
if (!function_exists('get_interface_ip')) {
    function get_interface_ip($iface) { return $GLOBALS['_test_interface_ip_map'][$iface] ?? ''; }
}
if (!function_exists('interface_vip_bring_down')) {
    function interface_vip_bring_down($vip) { $GLOBALS['_test_vip_bring_down'][] = $vip; }
}
if (!function_exists('interface_carp_configure')) {
    function interface_carp_configure($vip) { $GLOBALS['_test_carp_configure'][] = $vip; }
}
if (!function_exists('interface_ipalias_configure')) {
    function interface_ipalias_configure($vip) { $GLOBALS['_test_ipalias_configure'][] = $vip; }
}
if (!function_exists('listtags')) {
    function listtags() { return []; }
}
if (!function_exists('shell_safe')) {
    function shell_safe($fmt, ...$args) { return vsprintf($fmt, array_map('escapeshellarg', $args)); }
}
if (!function_exists('legacy_config_get_interfaces')) {
    function legacy_config_get_interfaces($filters = [], $exclude = []) { return []; }
}

// --- OPNsense\Core\Config stub (Singleton) ---
if (!class_exists('OPNsense\Core\Config')) {
    // Define a global stub class and alias it, because namespaced class_exists check above
    // fails if autoloader hasn't run — we define via eval to allow namespaced declaration.
    eval('
    namespace OPNsense\Core;
    class Config {
        private static $instance = null;
        public $objectData = null;
        public $toArrayData = [];
        public static function getInstance() {
            if (self::$instance === null) { self::$instance = new self(); }
            return self::$instance;
        }
        public static function reset() { self::$instance = null; }
        public function object() {
            if ($this->objectData !== null) { return $this->objectData; }
            // default empty object with hasync
            $o = new \stdClass();
            $o->hasync = new \stdClass();
            $o->hasync->synchronizetoip = "";
            $o->hasync->pfsyncinterface = "";
            return $o;
        }
        public function toArray($forceList = null, $node = null) {
            return $this->toArrayData;
        }
        public function fromArray($arr) { $this->toArrayData = $arr; }
        public function save($rev, $backup) { return true; }
        public function forceReload() {}
        public function toArrayFromFile($f, $l) { return []; }
    }
    ');
}

// --- OPNsense\Base\BaseModel stub ---
if (!class_exists('OPNsense\Base\BaseModel')) {
    eval('
    namespace OPNsense\Base;
    abstract class BaseModel {
        public $testNodes = [];
        public $testGetNodeMap = [];
        protected $internalData = null;
        public function __construct() {}
        public function getNodeByReference($ref) {
            if (isset($this->testGetNodeMap[$ref])) { return $this->testGetNodeMap[$ref]; }
            return null;
        }
        public function setNodes($data) { $this->testNodes = array_merge($this->testNodes, $data); }
        public function serializeToConfig($validateFullModel = false, $disable_validation = false) { $GLOBALS["_test_serialize_calls"][] = get_class($this); return true; }
        public function iterateItems() { return new \ArrayIterator([]); }
        public function getFlatNodes() { return []; }
        public function getNodes() { return $this->testNodes; }
        public function getNodeContent() { return $this->testNodes; }
        public function performValidation($full = false) { return new class implements \Countable, \IteratorAggregate { public function count(): int { return 0; } public function getIterator(): \Traversable { return new \ArrayIterator([]); } }; }
        public function isVolatile() { return false; }
    }
    ');
}

// --- Model stubs used by the project ---
if (!class_exists('OPNsense\Interfaces\Vip')) {
    eval('
    namespace OPNsense\Interfaces;
    class Vip extends \OPNsense\Base\BaseModel {
        public static $testIterateItems = [];
        public $vip;
        public function __construct() { $this->vip = $this; }
        public function iterateItems() { return new \ArrayIterator(static::$testIterateItems); }
        // allow test to set nodes via getNodeByReference
        public function getNodeByReference($ref) {
            if (isset($this->testGetNodeMap[$ref])) { return $this->testGetNodeMap[$ref]; }
            // support "vip.<uuid>" lookups for apply
            foreach (static::$testIterateItems as $item) {
                if ($ref === "vip." . ($item->getAttributes()["uuid"] ?? "")) { return $item; }
            }
            return parent::getNodeByReference($ref);
        }
    }
    ');
}

// Simple field stub that mimics BaseField for Vip iterator items
if (!class_exists('Tests\Stubs\FakeField')) {
    eval('
    namespace Tests\Stubs;
    class FakeField {
        private $value;
        private $attrs;
        private $children = [];
        public function __construct($value = "", $attrs = [], $children = []) { $this->value = $value; $this->attrs = $attrs; $this->children = $children; }
        public function __toString() { return (string)$this->value; }
        public function getAttributes() { return $this->attrs; }
        public function iterateItems() { return new \ArrayIterator($this->children); }
        public function getChild($name) { return $this->children[$name] ?? null; }
        public function hasChild($name) { return isset($this->children[$name]); }
        public function setNodes($data) { foreach ($data as $k=>$v) { $this->children[$k] = new self($v); } }
        public function isFieldChanged() { return true; }
        public function getInternalXMLTagName() { return "field"; }
        public function getParentNode() { return $this; }
        public function __get($n) { return $this->children[$n] ?? new self(""); }
        public function __isset($n) { return isset($this->children[$n]); }
        public function isEmpty() { return $this->value === "" || $this->value === null; }
    }
    class FakeVipItem {
        private $data;
        private $uuid;
        public $testChildren = [];
        public function __construct(array $data) { $this->data = $data; $this->uuid = $data["uuid"] ?? ""; foreach ($data as $k=>$v) { if ($k==="uuid") continue; $this->testChildren[$k] = new FakeField($v); } }
        public function iterateItems() { return new \ArrayIterator($this->testChildren); }
        public function getAttributes() { return ["uuid"=>$this->uuid]; }
        public function getParentNode() { return $this; }
        public function getInternalXMLTagName() { return "vip"; }
        public function isFieldChanged() { return true; }
        public function setNodes($data) { foreach ($data as $k=>$v) { $this->testChildren[$k] = new FakeField($v); $this->data[$k]=$v; } }
        public function __get($n) { return $this->testChildren[$n] ?? new FakeField(""); }
    }
    ');
}

if (!class_exists('OPNsense\Routing\Gateways')) {
    eval('
    namespace OPNsense\Routing;
    class Gateways extends \OPNsense\Base\BaseModel {
        public static $testGatewayRecords = null;
        public $gateway_item;
        public function __construct() { $this->gateway_item = new class { public function iterateItems(){ return new \ArrayIterator([]); } public function getDpingerDefaults(){ return []; } }; }
        public function gatewayIterator() {
            if (static::$testGatewayRecords !== null) {
                foreach (static::$testGatewayRecords as $rec) { yield $rec; }
                return;
            }
            // fallback empty
            return; yield from [];
        }
        public function createOrUpdateGateway($fields, $uuid) { $GLOBALS["_test_gateway_create"][] = [$fields,$uuid]; }
    }
    ');
}

if (!class_exists('OPNsense\Firewall\Alias')) {
    eval('
    namespace OPNsense\Firewall;
    class Alias extends \OPNsense\Base\BaseModel {
        public $aliases;
        public static $testAliasItems = null;
        public function __construct() {
            $this->aliases = new class {
                public $alias;
                public function __construct(){ $this->alias = new class {
                    public function iterateItems(){ 
                        if (\OPNsense\Firewall\Alias::$testAliasItems !== null) {
                            foreach (\OPNsense\Firewall\Alias::$testAliasItems as $it) { yield $it; }
                            return;
                        }
                        return; yield from [];
                    }
                }; }
            };
        }
        public function getNodeByReference($ref){
            if (isset($this->testGetNodeMap[$ref])) return $this->testGetNodeMap[$ref];
            return parent::getNodeByReference($ref);
        }
    }
    ');
}

if (!class_exists('OPNsense\Firewall\Filter')) {
    eval('
    namespace OPNsense\Firewall;
    class Filter extends \OPNsense\Base\BaseModel {
        public $npt;
        public static $testNptItems = null;
        public function __construct(){
            $this->npt = new class {
                public $rule;
                public function __construct(){ $this->rule = new class {
                    public function iterateItems(){
                        if (\OPNsense\Firewall\Filter::$testNptItems !== null) {
                            foreach (\OPNsense\Firewall\Filter::$testNptItems as $it) { yield $it; }
                            return;
                        }
                        return; yield from [];
                    }
                };}
            };
        }
        public function getNodeByReference($ref){
            if (isset($this->testGetNodeMap[$ref])) return $this->testGetNodeMap[$ref];
            return parent::getNodeByReference($ref);
        }
    }
    ');
}

// --- Stub inc files on include_path ---
$incStubDir = __DIR__ . "/Stubs/inc";
if (is_dir($incStubDir)) {
    set_include_path($incStubDir . PATH_SEPARATOR . get_include_path());
}

// Helper to reset global state between tests
if (!function_exists("test_reset_globals")) {
    function test_reset_globals() {
        $GLOBALS["_test_log"] = [];
        $GLOBALS["_test_write_config_calls"] = [];
        $GLOBALS["_test_system_routing_calls"] = [];
        $GLOBALS["_test_filter_configure_calls"] = [];
        $GLOBALS["_test_plugins_configure_calls"] = [];
        $GLOBALS["_test_configd_run_calls"] = [];
        $GLOBALS["_test_vip_bring_down"] = [];
        $GLOBALS["_test_carp_configure"] = [];
        $GLOBALS["_test_ipalias_configure"] = [];
        $GLOBALS["_test_gateway_create"] = [];
        $GLOBALS["_test_serialize_calls"] = [];
        $GLOBALS["_test_real_interface_map"] = [];
        $GLOBALS["_test_friendly_map"] = [];
        $GLOBALS["_test_interface_ip_map"] = [];
        unset($GLOBALS["_dhcpcarp_interfaces_cache"]);
        if (function_exists("dhcpcarp_interfaces_clear_cache")) { dhcpcarp_interfaces_clear_cache(); }
        // reset singletons
        \OPNsense\Core\Config::reset();
        \OPNsense\Interfaces\Vip::$testIterateItems = [];
        \OPNsense\Routing\Gateways::$testGatewayRecords = null;
        \OPNsense\Firewall\Alias::$testAliasItems = null;
        \OPNsense\Firewall\Filter::$testNptItems = null;
        // clear env DHCPv6 prefixes (up to 20 to avoid cross-test leaks)
        for ($i=1;$i<=20;$i++) { putenv("new_dhcp6_ia_pd1_prefix{$i}"); putenv("new_dhcp6_ia_pd1_prefix{$i}_length"); }
        putenv("new_dhcp6_ia_na1_ia_addr1");
        putenv("nd1_prefix_information1_length");
        putenv("nd1_from");
    }
    test_reset_globals();
}

// Helpers for Integration tests via LD_PRELOAD fake root
if (!function_exists('test_make_fake_root')) {
    function test_make_fake_root(?string $base = null): string {
        $base ??= sys_get_temp_dir() . '/fake_root_' . bin2hex(random_bytes(4));
        foreach (["/var/run/dhcpcd","/var/db/dhcpcd","/conf","/usr/local/etc","/root/opnsense-tweaks"] as $p) {
            $dir = $base.$p;
            @exec("mkdir -p ".escapeshellarg($dir));
        }
        if (!is_file($base."/conf/config.xml")) @file_put_contents($base."/conf/config.xml", '<opnsense><hasync><synchronizetoip/></hasync></opnsense>');
        if (!is_file($base."/usr/local/etc/config.xml")) @file_put_contents($base."/usr/local/etc/config.xml", '<opnsense/>');
        putenv("FAKE_ROOT=$base"); $_ENV['FAKE_ROOT']=$base; $_SERVER['FAKE_ROOT']=$base;
        return $base;
    }
    function test_cleanup_fake_root(string $base): void {
        putenv('FAKE_ROOT'); unset($_ENV['FAKE_ROOT'], $_SERVER['FAKE_ROOT']);
        $tmp = sys_get_temp_dir();
        // safety: only remove paths inside temp dir and not empty/root
        if ($base === '' || $base === '/' || $base === $tmp) {
            return;
        }
        if (!str_starts_with($base, $tmp . '/') && $base !== $tmp) {
            return;
        }
        @exec("rm -rf ".escapeshellarg($base));
    }
}

// Pest expects (only when run via pest runner) — guard for phpstan/other tools
if (function_exists('uses') && isset($_SERVER['argv'][0]) && str_contains((string) $_SERVER['argv'][0], 'pest')) {
    uses()->beforeEach(function(){ test_reset_globals(); })->in("Unit");
    uses()->beforeEach(function(){ test_reset_globals(); })->in("Integration");
    uses()->group('integration')->in("Integration");
}
