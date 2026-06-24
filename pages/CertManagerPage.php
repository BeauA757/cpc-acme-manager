<?php
/**
 * CPC Certificate Manager admin page for iTop 3.1/3.2
 *
 * Properly extends iTopWebPage with authentication, breadcrumbs, and output buffering.
 */

namespace Cpc\ITop\AcmeManager\Controller;

use Cpc\ITop\AcmeManager\Service\CertificatePipeline;

// Ensure iTop runtime dependencies (including SetupUtils) are loaded when
// this page is executed via exec.php, which does not include startup.inc.php.
// SetupUtils is required by ThemeHandler and DataModelDependantCache in iTop 3.2
require_once(APPROOT.'/setup/setuputils.class.inc.php');
require_once(APPROOT.'/application/application.inc.php');
require_once(APPROOT.'/application/startup.inc.php');

class CertManagerPageController
{
    public static function Run(): void
    {
        // Use WebPage instead of iTopWebPage in exec.php context to avoid
        // NavigationMenuFactory and theme initialization errors in iTop 3.2
        $oPage = new \WebPage('CPC Certificate Manager');

        // Enforce authentication (iTop 3.1+ uses UserRights)
        if (!\UserRights::IsLoggedIn()) {
            $oPage->add_header('Location: ' . \utils::GetAbsoluteUrlAppRoot() . 'pages/UI.php');
            $oPage->output();
            return;
        }

        // Restrict to administrators (configurable)
        $bIsAdmin = \UserRights::IsAdministrator();
        if (!$bIsAdmin) {
            $oPage->add('<div class="alert alert-danger">' . \Dict::S('UI:Login:Error:AccessRestricted') . '</div>');
            $oPage->output();
            return;
        }

        try {
            $pipeline = new CertificatePipeline();
        } catch (\Throwable $e) {
            $oPage->add('<div class="cpc-cert-card"><h2>Configuration Error</h2><p class="cpc-cert-bad">' . \htmlentities($e->getMessage()) . '</p></div>');
            $oPage->output();
            return;
        }

        $action = $_GET['action'] ?? 'dashboard';

        switch ($action) {
            case 'execute':
                self::RenderExecution($oPage, $pipeline);
                break;
            case 'dashboard':
            default:
                self::RenderDashboard($oPage, $pipeline);
                break;
        }

        $oPage->output();
    }

    private static function RenderDashboard(\WebPage $oPage, CertificatePipeline $pipeline): void
    {
        $checks = $pipeline->verifyConfig();
        $domains = $pipeline->plannedDomains();
        $pullPlans = $pipeline->pullPlans();
        $deployPlans = $pipeline->localDeployPlans();
        $downstreamPlans = $pipeline->downstreamPlans();
        $notification = $pipeline->notificationPreview();

        $oPage->add_style(<<<'CSS'
.cpc-cert-card{background:#fff;padding:16px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:16px}
.cpc-cert-card h2{margin:0 0 12px;font-size:1.25rem}
.cpc-cert-table{width:100%;border-collapse:collapse}
.cpc-cert-table th,.cpc-cert-table td{padding:8px;border:1px solid #d7dce5;text-align:left;vertical-align:top}
.cpc-cert-ok{color:#0a7b34;font-weight:bold}
.cpc-cert-bad{color:#b42318;font-weight:bold}
.cpc-cert-pre{background:#f0f3f8;padding:8px;border-radius:4px;overflow:auto;max-height:400px}
CSS
        );

        $oPage->add('<div class="page_header">');
        $oPage->add('<h1><span class="fas fa-shield-alt"></span> CPC Certificate Manager</h1>');
        $oPage->add('</div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<p><strong>Version:</strong> 1.2.0 | <strong>Compatible:</strong> iTop 3.1/3.2</p>');
$oPage->add('<a href="?exec_module=cpc-acme-manager&amp;exec_page=pages/CertManagerPage.php&amp;action=execute" class="btn btn-primary"><span class="fas fa-play"></span> Execute Pipeline</a>');
        $oPage->add('</div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<h2>Config Checks</h2>');
        $oPage->add('<table class="cpc-cert-table"><tr><th>Item</th><th>Status</th></tr>');
        foreach ($checks as $k => $v) {
            $cls = $v ? 'cpc-cert-ok' : 'cpc-cert-bad';
            $status = $v ? 'OK' : 'Missing';
            $oPage->add('<tr><td>' . \htmlentities($k) . '</td><td class="' . $cls . '">' . $status . '</td></tr>');
        }
        $oPage->add('</table></div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<h2>Domains</h2>');
        $oPage->add('<table class="cpc-cert-table"><tr><th>Domain</th><th>Synology Source</th><th>Cert Names</th></tr>');
        foreach ($domains as $domain => $meta) {
            $oPage->add('<tr><td>' . \htmlentities($domain) . '</td><td><code>' . \htmlentities($meta['source_dir']) . '</code></td><td>' . \htmlentities(implode(', ', $meta['cert_names'])) . '</td></tr>');
        }
        $oPage->add('</table></div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<h2>Pull Plan</h2>');
        $oPage->add('<pre class="cpc-cert-pre">' . \htmlentities(print_r($pullPlans, true)) . '</pre></div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<h2>Local iTop Cert Update</h2>');
        $oPage->add('<pre class="cpc-cert-pre">' . \htmlentities(print_r($deployPlans, true)) . '</pre></div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<h2>Downstream Distribution</h2>');
        $oPage->add('<pre class="cpc-cert-pre">' . \htmlentities(print_r($downstreamPlans, true)) . '</pre></div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<h2>Notification Preview</h2>');
        $oPage->add('<pre class="cpc-cert-pre">' . \htmlentities(print_r($notification, true)) . '</pre></div>');
    }

    private static function RenderExecution(\WebPage $oPage, CertificatePipeline $pipeline): void
    {
        $oPage->add_style('.cpc-cert-pre{background:#f0f3f8;padding:8px;border-radius:4px;overflow:auto;max-height:600px}');
        $oPage->add('<div class="page_header">');
        $oPage->add('<h1><span class="fas fa-shield-alt"></span> CPC Certificate Manager — Execution</h1>');
        $oPage->add('</div>');

        $oPage->add('<div class="cpc-cert-card">');
        $oPage->add('<p>Pipeline execution started at ' . \htmlentities(date('Y-m-d H:i:s')) . '</p>');

        $report = $pipeline->execute();

        $oPage->add('<h2>Execution Report</h2>');
        $oPage->add('<pre class="cpc-cert-pre">' . \htmlentities(json_encode($report, JSON_PRETTY_PRINT)) . '</pre>');

$oPage->add('<a href="?exec_module=cpc-acme-manager&amp;exec_page=pages/CertManagerPage.php" class="btn btn-default"><span class="fas fa-arrow-left"></span> Back to Dashboard</a>');
        $oPage->add('</div>');
    }
}

CertManagerPageController::Run();
