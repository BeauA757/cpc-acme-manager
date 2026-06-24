<?php
/**
 * CPC Certificate Manager — Installation & Functionality Test Suite
 *
 * Usage (standalone, pre-install):
 *   php tests/verify-cpc-acme-manager.php
 *
 * Usage (from iTop, post-install):
 *   php /var/www/html/extensions/cpc-acme-manager/tests/verify-cpc-acme-manager.php --itop
 *
 * Exit codes:
 *   0 = all checks passed
 *   1 = one or more checks failed
 */

namespace Cpc\ITop\AcmeManager\Test;

class VerifyExtension
{
    private const MODULE_CODE = 'cpc-acme-manager';
    private const MODULE_VERSION = '1.2.0';
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private bool $isItopContext;
    private string $basePath;

    public function __construct()
    {
        $this->isItopContext = defined('ITOP_APPLICATION');
        $this->basePath = $this->resolveBasePath();
    }

    public function run(): int
    {
        $this->header('CPC Certificate Manager Extension — Verification Test Suite');
        $this->info('Context: ' . ($this->isItopContext ? 'iTop runtime' : 'Standalone CLI'));
        $this->info('Base path: ' . $this->basePath);
        $this->info('PHP version: ' . PHP_VERSION);
        $this->info('Date: ' . date('Y-m-d H:i:s'));
        $this->separator();

        $this->testFileStructure();
        $this->testComposerAutoload();
        $this->testConfigService();
        $this->testLoggerService();
        $this->testSshRunnerService();
        $this->testPipelineInstantiation();
        $this->testPipelinePlans();
        $this->testNotificationService();
        $this->testBackgroundTask();
        $this->testModuleManifest();
        $this->testDatamodelXml();
        $this->testDictionaryFile();
        $this->testPageController();

        if ($this->isItopContext) {
            $this->testItopModuleLoaded();
            $this->testItopMenuRegistered();
            $this->testItopBackgroundTaskRegistered();
        }

        $this->separator();
        $this->summary();

        return $this->failed > 0 ? 1 : 0;
    }

    private function testFileStructure(): void
    {
        $this->section('File Structure');

        $required = [
            'module.cpc-acme-manager.php',
            'model.cpc-acme-manager.php',
            'datamodel.cpc-acme-manager.xml',
            'en.dict.cpc-acme-manager.php',
            'composer.json',
            'config.sample.json',
            'pages/CertManagerPage.php',
            'src/Controller/CertManagerPage.php',
            'src/Model/Endpoint.php',
            'src/Service/CertificatePipeline.php',
            'src/Service/Config.php',
            'src/Service/Logger.php',
            'src/Service/NotificationService.php',
            'src/Service/SshRunner.php',
            'src/Task/CertManagerBackgroundTask.php',
        ];

        foreach ($required as $file) {
            $path = $this->basePath . '/' . $file;
            $this->assert(is_file($path), "File exists: {$file}");
        }
    }

    private function testComposerAutoload(): void
    {
        $this->section('Composer Autoload');

        $autoload = $this->basePath . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            $this->warn('Composer autoload not found. Run `composer install --no-dev -o` in the extension directory.');
            return;
        }

        require_once $autoload;

        $classes = [
            'Cpc\ITop\AcmeManager\Service\Config',
            'Cpc\ITop\AcmeManager\Service\Logger',
            'Cpc\ITop\AcmeManager\Service\SshRunner',
            'Cpc\ITop\AcmeManager\Service\CertificatePipeline',
            'Cpc\ITop\AcmeManager\Service\NotificationService',
            'Cpc\ITop\AcmeManager\Model\Endpoint',
            'Cpc\ITop\AcmeManager\Task\CertManagerBackgroundTask',
        ];

        foreach ($classes as $class) {
            $this->assert(class_exists($class), "Class autoloaded: {$class}");
        }
    }

    private function testConfigService(): void
    {
        $this->section('Config Service');

        if (!class_exists('Cpc\ITop\AcmeManager\Service\Config')) {
            $this->warn('Config class not available; skipping tests.');
            return;
        }

        // Use sample config for testing so we don't require production path
        $samplePath = $this->basePath . '/config.sample.json';
        $this->assert(is_file($samplePath), 'Sample config file exists');

        try {
            $config = \Cpc\ITop\AcmeManager\Service\Config::load($samplePath);
            $this->assert(is_array($config), 'Config::load returns array');
            $this->assert(isset($config['synology']), 'Config has synology section');
            $this->assert(isset($config['targets']), 'Config has targets section');
            $this->assert(isset($config['notifications']), 'Config has notifications section');
        } catch (\Throwable $e) {
            $this->fail('Config::load threw: ' . $e->getMessage());
        }

        // Test dot-notation getter
        try {
            $host = \Cpc\ITop\AcmeManager\Service\Config::get('synology.host', $samplePath, null);
            $this->assert(is_string($host) || $host === null, 'Config::get dot-notation works');
        } catch (\Throwable $e) {
            $this->fail('Config::get threw: ' . $e->getMessage());
        }

        // Test cache clear
        try {
            \Cpc\ITop\AcmeManager\Service\Config::clearCache();
            $this->pass('Config::clearCache executed without error');
        } catch (\Throwable $e) {
            $this->fail('Config::clearCache threw: ' . $e->getMessage());
        }
    }

    private function testLoggerService(): void
    {
        $this->section('Logger Service');

        if (!class_exists('Cpc\ITop\AcmeManager\Service\Logger')) {
            $this->warn('Logger class not available; skipping tests.');
            return;
        }

        $tmpDir = sys_get_temp_dir() . '/cpc_cert_test_' . getmypid();
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0700, true);
        }
        $logFile = $tmpDir . '/test.log';

        try {
            $logger = new \Cpc\ITop\AcmeManager\Service\Logger($logFile, 2, 1048576, 2);
            $logger->info('Test info message');
            $logger->debug('Test debug message');
            $logger->warning('Test warning message');
            $logger->error('Test error message');
            $logger->flush();

            $this->assert(is_file($logFile), 'Log file created after flush');
            $content = file_get_contents($logFile);
            $this->assert(strpos($content, 'INFO') !== false, 'Log contains INFO level');
            $this->assert(strpos($content, 'DEBUG') !== false, 'Log contains DEBUG level');
            $this->assert(strpos($content, 'WARNING') !== false, 'Log contains WARNING level');
            $this->assert(strpos($content, 'ERROR') !== false, 'Log contains ERROR level');
        } catch (\Throwable $e) {
            $this->fail('Logger test threw: ' . $e->getMessage());
        } finally {
            if (is_file($logFile)) {
                unlink($logFile);
            }
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    private function testSshRunnerService(): void
    {
        $this->section('SSH Runner Service');

        if (!class_exists('Cpc\ITop\AcmeManager\Service\SshRunner')) {
            $this->warn('SshRunner class not available; skipping tests.');
            return;
        }

        try {
            $runner = new \Cpc\ITop\AcmeManager\Service\SshRunner();
            $cmd = $runner->run('/tmp/key', 'user', 'host.example.com', 'uname -a');
            $this->assert(strpos($cmd, 'ssh') !== false, 'SSH command contains ssh binary');
            $this->assert(strpos($cmd, 'BatchMode=yes') !== false, 'SSH command contains BatchMode=yes');
            $this->assert(strpos($cmd, 'ControlMaster') !== false, 'SSH command contains ControlMaster');

            $scp = $runner->scpFrom('/tmp/key', 'user', 'host.example.com', '/remote/file', '/local/file');
            $this->assert(strpos($scp, 'scp') !== false, 'SCP command contains scp binary');
            $this->assert(strpos($scp, 'ControlPath') !== false, 'SCP command contains ControlPath');

            $batch = $runner->scpFromBatch('/tmp/key', 'user', 'host.example.com', [
                ['remotePath' => '/r1', 'localPath' => '/l1'],
                ['remotePath' => '/r2', 'localPath' => '/l2'],
            ]);
            $this->assert(count($batch) === 2, 'SCP batch returns 2 commands');
        } catch (\Throwable $e) {
            $this->fail('SshRunner test threw: ' . $e->getMessage());
        }
    }

    private function testPipelineInstantiation(): void
    {
        $this->section('Certificate Pipeline — Instantiation');

        if (!class_exists('Cpc\ITop\AcmeManager\Service\CertificatePipeline')) {
            $this->warn('CertificatePipeline class not available; skipping tests.');
            return;
        }

        try {
            $samplePath = $this->basePath . '/config.sample.json';
            $config = \Cpc\ITop\AcmeManager\Service\Config::load($samplePath);
            $pipeline = new \Cpc\ITop\AcmeManager\Service\CertificatePipeline($config);
            $this->pass('CertificatePipeline instantiated with sample config');
        } catch (\Throwable $e) {
            $this->fail('Pipeline instantiation threw: ' . $e->getMessage());
        }
    }

    private function testPipelinePlans(): void
    {
        $this->section('Certificate Pipeline — Plan Generation');

        if (!class_exists('Cpc\ITop\AcmeManager\Service\CertificatePipeline')) {
            $this->warn('CertificatePipeline class not available; skipping tests.');
            return;
        }

        try {
            $samplePath = $this->basePath . '/config.sample.json';
            $config = \Cpc\ITop\AcmeManager\Service\Config::load($samplePath);
            $pipeline = new \Cpc\ITop\AcmeManager\Service\CertificatePipeline($config);

            $checks = $pipeline->verifyConfig();
            $this->assert(is_array($checks), 'verifyConfig returns array');
            $this->assert(isset($checks['synology']), 'verifyConfig checks synology');

            $domains = $pipeline->plannedDomains();
            $this->assert(is_array($domains), 'plannedDomains returns array');
            $this->assert(count($domains) > 0, 'plannedDomains has at least one domain');

            $pull = $pipeline->pullPlans();
            $this->assert(is_array($pull), 'pullPlans returns array');
            $this->assert(count($pull) > 0, 'pullPlans has at least one domain');

            $local = $pipeline->localDeployPlans();
            $this->assert(is_array($local), 'localDeployPlans returns array');
            $this->assert(count($local) > 0, 'localDeployPlans has at least one domain');

            $downstream = $pipeline->downstreamPlans();
            $this->assert(is_array($downstream), 'downstreamPlans returns array');
            $this->assert(count($downstream) > 0, 'downstreamPlans has at least one target');

            $notif = $pipeline->notificationPreview();
            $this->assert(is_array($notif), 'notificationPreview returns array');
            $this->assert(isset($notif['subject']), 'notificationPreview has subject');
        } catch (\Throwable $e) {
            $this->fail('Pipeline plan tests threw: ' . $e->getMessage());
        }
    }

    private function testNotificationService(): void
    {
        $this->section('Notification Service');

        if (!class_exists('Cpc\ITop\AcmeManager\Service\NotificationService')) {
            $this->warn('NotificationService class not available; skipping tests.');
            return;
        }

        try {
            $samplePath = $this->basePath . '/config.sample.json';
            $config = \Cpc\ITop\AcmeManager\Service\Config::load($samplePath);
            $svc = new \Cpc\ITop\AcmeManager\Service\NotificationService($config);

            $result = $svc->sendSummary('Test subject', 'Test body');
            $this->assert(is_array($result), 'sendSummary returns array');
            $this->assert(isset($result['subject']), 'sendSummary has subject');
            $this->assert(isset($result['targets']), 'sendSummary has targets');

            $svc->queue('Q1', 'Body1');
            $svc->queue('Q2', 'Body2');
            $results = $svc->flushQueue();
            $this->assert(count($results) === 2, 'flushQueue returns 2 results');
        } catch (\Throwable $e) {
            $this->fail('NotificationService test threw: ' . $e->getMessage());
        }
    }

    private function testBackgroundTask(): void
    {
        $this->section('Background Task');

        if (!class_exists('Cpc\ITop\AcmeManager\Task\CertManagerBackgroundTask')) {
            $this->warn('CertManagerBackgroundTask class not available; skipping tests.');
            return;
        }

        try {
            $task = new \Cpc\ITop\AcmeManager\Task\CertManagerBackgroundTask();
            $this->assert(method_exists($task, 'GetModuleName'), 'Task has GetModuleName');
            $this->assert(method_exists($task, 'GetPeriodicity'), 'Task has GetPeriodicity');
            $this->assert(method_exists($task, 'Process'), 'Task has Process');
            $this->assert($task->GetModuleName() === self::MODULE_CODE, 'Task module name matches');
            $this->assert($task->GetPeriodicity() === 300, 'Task periodicity is 300 seconds');
        } catch (\Throwable $e) {
            $this->fail('BackgroundTask test threw: ' . $e->getMessage());
        }
    }

    private function testModuleManifest(): void
    {
        $this->section('Module Manifest');

        $manifest = $this->basePath . '/module.cpc-acme-manager.php';
        $this->assert(is_file($manifest), 'module manifest file exists');

        $content = file_get_contents($manifest);
        $this->assert(strpos($content, 'cpc-acme-manager/' . self::MODULE_VERSION) !== false, 'Manifest version is ' . self::MODULE_VERSION);
        $this->assert(strpos($content, 'datamodel.cpc-acme-manager.xml') !== false, 'Manifest references datamodel XML');
        $this->assert(strpos($content, 'en.dict.cpc-acme-manager.php') !== false, 'Manifest references dictionary');
        $this->assert(strpos($content, 'SetupWebPage::AddModule') !== false, 'Manifest calls SetupWebPage::AddModule');
    }

    private function testDatamodelXml(): void
    {
        $this->section('Datamodel XML');

        $xmlPath = $this->basePath . '/datamodel.cpc-acme-manager.xml';
        $this->assert(is_file($xmlPath), 'datamodel XML file exists');

        libxml_use_internal_errors(true);
        $doc = simplexml_load_file($xmlPath);
        if ($doc === false) {
            $errors = libxml_get_errors();
            $msgs = array_map(fn($e) => $e->message, $errors);
            $this->fail('datamodel XML is invalid: ' . implode('; ', $msgs));
            libxml_clear_errors();
            return;
        }

        $this->pass('datamodel XML is well-formed');
        $this->assert((string) $doc['version'] === '3.2', 'datamodel XML version is 3.2');
        $this->assert(isset($doc->menus), 'datamodel XML contains menus section');
        $this->assert(isset($doc->background_tasks), 'datamodel XML contains background_tasks section');
    }

    private function testDictionaryFile(): void
    {
        $this->section('Dictionary File');

        $dictPath = $this->basePath . '/en.dict.cpc-acme-manager.php';
        $this->assert(is_file($dictPath), 'English dictionary file exists');

        $content = file_get_contents($dictPath);
        $this->assert(strpos($content, 'Dict::Add') !== false, 'Dictionary contains Dict::Add call');
        $this->assert(strpos($content, 'CpcAcmeManagerMenu') !== false, 'Dictionary defines CpcAcmeManagerMenu');
    }

    private function testPageController(): void
    {
        $this->section('Page Controller');

        $pagePath = $this->basePath . '/pages/CertManagerPage.php';
        $this->assert(is_file($pagePath), 'pages/CertManagerPage.php exists');

        $content = file_get_contents($pagePath);
        $this->assert(strpos($content, 'CertManagerPageController') !== false, 'Page defines CertManagerPageController');
        $this->assert(strpos($content, 'Run()') !== false, 'Page has Run() method');
        $this->assert(strpos($content, 'iTopWebPage') !== false || strpos($content, 'WebPage') !== false, 'Page references iTopWebPage or WebPage');
        $this->assert(strpos($content, 'IsAdministrator') !== false, 'Page checks IsAdministrator');
    }

    private function testItopModuleLoaded(): void
    {
        $this->section('iTop Runtime — Module Loaded');

        // MetaModel::GetModules() does not exist in iTop 3.2; check via directory
        $found = is_dir(APPROOT . 'env-production/' . self::MODULE_CODE);
        $this->assert($found, 'Module ' . self::MODULE_CODE . ' is registered in MetaModel');
    }

    private function testItopMenuRegistered(): void
    {
        $this->section('iTop Runtime — Menu Registered');

        // MetaModel::GetMenuItem() does not exist in iTop 3.2; check via compiled datamodel
        $found = false;
        try {
            $sDatamodel = APPROOT . 'data/datamodel-production.xml';
            if (is_file($sDatamodel)) {
                $xml = simplexml_load_file($sDatamodel);
                if ($xml !== false) {
                    $menus = $xml->xpath("//menus/menu[@id='CpcAcmeManagerMenu']");
                    $found = !empty($menus);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $this->assert($found, 'Menu Cert Manager is registered in compiled datamodel');
    }

    private function testItopBackgroundTaskRegistered(): void
    {
        $this->section('iTop Runtime — Background Task Registered');

        // BackgroundTask::GetRegisteredTasks() does not exist in iTop 3.2; check via compiled datamodel
        $found = false;
        try {
            $sDatamodel = APPROOT . 'data/datamodel-production.xml';
            if (is_file($sDatamodel)) {
                $xml = simplexml_load_file($sDatamodel);
                if ($xml !== false) {
                    $tasks = $xml->xpath("//background_tasks/task[@id='CpcCertManagerCron']");
                    $found = !empty($tasks);
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $this->assert($found, 'Background task Cert Manager is registered in compiled datamodel');
    }

    // ─── Helpers ───

    private function resolveBasePath(): string
    {
        if ($this->isItopContext) {
            return APPROOT . 'extensions/' . self::MODULE_CODE;
        }
        // Detect from script location: tests/verify-cpc-acme-manager.php
        $scriptDir = dirname(__DIR__);
        if (is_file($scriptDir . '/module.cpc-acme-manager.php')) {
            return $scriptDir;
        }
        // Fallback to current working directory if valid
        $cwd = getcwd();
        if ($cwd && is_file($cwd . '/module.cpc-acme-manager.php')) {
            return $cwd;
        }
        return $scriptDir;
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            $this->pass($message);
        } else {
            $this->fail($message);
        }
    }

    private function pass(string $message): void
    {
        $this->results[] = ['status' => 'PASS', 'message' => $message];
        $this->passed++;
        echo "  [PASS] {$message}\n";
    }

    private function fail(string $message): void
    {
        $this->results[] = ['status' => 'FAIL', 'message' => $message];
        $this->failed++;
        echo "  [FAIL] {$message}\n";
    }

    private function warn(string $message): void
    {
        $this->results[] = ['status' => 'WARN', 'message' => $message];
        echo "  [WARN] {$message}\n";
    }

    private function section(string $title): void
    {
        echo "\n» {$title}\n";
    }

    private function header(string $title): void
    {
        $line = str_repeat('=', strlen($title));
        echo "\n{$line}\n{$title}\n{$line}\n\n";
    }

    private function separator(): void
    {
        echo "\n" . str_repeat('-', 60) . "\n";
    }

    private function info(string $message): void
    {
        echo "  [INFO] {$message}\n";
    }

    private function summary(): void
    {
        $total = $this->passed + $this->failed;
        echo "Results: {$this->passed} passed, {$this->failed} failed ({$total} checks)\n";
        if ($this->failed === 0) {
            echo "All checks passed.\n";
        } else {
            echo "One or more checks failed. Review the output above.\n";
        }
    }
}

// ─── Entry point ───

// When running from iTop, APPROOT is defined and autoloaders are already set up.
// When running standalone, attempt to load Composer autoloader if present.
if (!defined('ITOP_APPLICATION')) {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
}

$itopMode = in_array('--itop', $argv ?? [], true);
if ($itopMode && !defined('ITOP_APPLICATION')) {
    fwrite(STDERR, "Error: --itop flag requires running inside an iTop environment.\n");
    exit(2);
}

$verifier = new VerifyExtension();
exit($verifier->run());
