<?php
/**
 * Simple PSR-4 autoloader for CPC Acme Manager extension
 * Generated manually because composer is not available on the target server.
 *
 * This autoloader mirrors the PSR-4 mapping from composer.json:
 *   "Cpc\\ITop\\AcmeManager\\" => "src/"
 */

spl_autoload_register(function ($class) {
    $prefix = 'Cpc\\ITop\\AcmeManager\\';
    $base_dir = __DIR__ . '/../src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});

// When running outside iTop, provide a stub for the iTop background task
// interface so the task class can be loaded and verified by the standalone
// test suite. The real interface is provided by iTop at runtime.
if (!interface_exists('iBackgroundProcess', false)) {
    interface iBackgroundProcess
    {
        public function GetModuleName();
        public function GetPeriodicity();
        public function Process($iUnixTimeLimit);
    }
}
