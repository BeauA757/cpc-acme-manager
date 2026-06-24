<?php
namespace Cpc\ITop\AcmeManager\Controller;

use Cpc\ITop\AcmeManager\Service\CertificatePipeline;

// Legacy standalone preview page. It is not used by iTop (the real page is
// pages/CertManagerPage.php), but keep it functional for local CLI preview.
$vendor = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($vendor)) {
    require_once $vendor;
} else {
    // Manual PSR-4 autoloader when Composer is not installed locally
    spl_autoload_register(function ($class) {
        $prefix = 'Cpc\\ITop\\AcmeManager\\';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }
        $file = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, $len)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

try {
    $pipeline = new CertificatePipeline();
    $checks = $pipeline->verifyConfig();
    $domains = $pipeline->plannedDomains();
    $pullPlans = $pipeline->pullPlans();
    $deployPlans = $pipeline->localDeployPlans();
    $downstreamPlans = $pipeline->downstreamPlans();
    $notification = $pipeline->notificationPreview();
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<h1>Configuration error</h1><p>' . \htmlspecialchars($e->getMessage()) . '</p>';
    exit;
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Certificate Management</title>
<style>
body{font-family:Arial,sans-serif;margin:24px;background:#f5f7fb;color:#222} h1,h2{margin:0 0 12px} .card{background:#fff;padding:16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:16px} code,pre{background:#f0f3f8;padding:2px 4px;border-radius:4px} table{width:100%;border-collapse:collapse} th,td{padding:8px;border:1px solid #d7dce5;text-align:left;vertical-align:top} .ok{color:#0a7b34;font-weight:bold} .bad{color:#b42318;font-weight:bold}
</style>
</head>
<body>
<h1>Certificate Management</h1>
<div class="card"><strong>Intended route:</strong> <code>/certmanager</code> via iTop extension page mapping.</div>
<div class="card"><h2>Config checks</h2><table><tr><th>Item</th><th>Status</th></tr><?php foreach ($checks as $k=>$v): ?><tr><td><?= \htmlspecialchars($k) ?></td><td class="<?= $v ? 'ok':'bad' ?>"><?= $v ? 'OK':'Missing' ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Domains</h2><table><tr><th>Domain</th><th>Synology source</th><th>Cert names</th></tr><?php foreach ($domains as $domain=>$meta): ?><tr><td><?= \htmlspecialchars($domain) ?></td><td><code><?= \htmlspecialchars($meta['source_dir']) ?></code></td><td><?= \htmlspecialchars(implode(', ', $meta['cert_names'])) ?></td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Pull plan</h2><pre><?= \htmlspecialchars(print_r($pullPlans, true)) ?></pre></div>
<div class="card"><h2>Local iTop cert update</h2><pre><?= \htmlspecialchars(print_r($deployPlans, true)) ?></pre></div>
<div class="card"><h2>Downstream distribution</h2><pre><?= \htmlspecialchars(print_r($downstreamPlans, true)) ?></pre></div>
<div class="card"><h2>Notification preview</h2><pre><?= \htmlspecialchars(print_r($notification, true)) ?></pre></div>
</body>
</html>
