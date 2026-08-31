<?php
// stub inc for pest test harness - provides fallback globals if not already defined
if (!function_exists('log_error') && !function_exists('log_error_stub_guard')) { function log_error($m){ $GLOBALS['_test_log'][]=$m; } }
