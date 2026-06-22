<?php
/**
 * iTop datamodel bootstrap for CPC Certificate Manager extension.
 *
 * This file is loaded by iTop after the module has been selected during
 * setup/upgrade. It is also parsed by iTop's module discovery scanner, so it
 * must not depend on classes that are not available at scan time (e.g.
 * ModuleInstallerAPI).
 */

if (!defined('ITOP_APPLICATION')) {
    // Discovery scanner: stop executing here. The module file is still
    // syntactically valid because no classes that depend on iTop runtime are
    // instantiated in the top-level code.
    return;
}

// Manual PSR-4 autoloader for environments without Composer (e.g. the iTop
// 3.2 test server). The namespace prefix matches composer.json.
$g_aCpcAcmeAutoloadMap = [
    'Cpc\\ITop\\AcmeManager\\' => __DIR__ . '/src/',
];

spl_autoload_register(function ($class) use ($g_aCpcAcmeAutoloadMap) {
    foreach ($g_aCpcAcmeAutoloadMap as $prefix => $baseDir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// Prefer Composer autoloader if available
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Installer class for setup-time directory creation and permissions.
// Only define it when the iTop installer API is available, so the
// discovery scanner never sees an unresolved parent class.
if (class_exists('ModuleInstallerAPI', true)) {
    class CpcAcmeManagerInstaller extends ModuleInstallerAPI
    {
        public static function BeforeWritingConfig(\Config $oConfiguration): void
        {
            // Hook for pre-config validation
        }

        public static function AfterDatabaseCreation(\Config $oConfiguration, $sPreviousVersion, $sCurrentVersion): void
        {
            $baseDir = '/var/opt/cert-manager';
            $subDirs = ['logs', 'endpoints', 'work', 'archive', 'live'];

            foreach ($subDirs as $sub) {
                $dir = $baseDir . '/' . $sub;
                if (!is_dir($dir)) {
                    mkdir($dir, 0750, true);
                }
            }
        }
    }
}
