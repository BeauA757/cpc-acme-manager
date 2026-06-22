<?php
namespace Cpc\ITop\AcmeManager\Service;

class CertificatePipeline
{
    private array $config;
    private ?Logger $logger = null;
    private ?SshRunner $ssh = null;
    private ?NotificationService $notificationService = null;
    private array $planCache = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? Config::load();
    }

    // Lazy initialization of heavy services to avoid unnecessary overhead
    private function logger(): Logger
    {
        if ($this->logger === null) {
            $this->logger = new Logger($this->config['paths']['logs'] . '/certmanager.log');
        }
        return $this->logger;
    }

    private function ssh(): SshRunner
    {
        if ($this->ssh === null) {
            $this->ssh = new SshRunner(
                $this->config['paths']['working'] . '/.ssh_mux_' . getmypid(),
                300
            );
        }
        return $this->ssh;
    }

    private function notificationService(): NotificationService
    {
        if ($this->notificationService === null) {
            $this->notificationService = new NotificationService($this->config);
        }
        return $this->notificationService;
    }

    public function verifyConfig(): array
    {
        $checks = [];
        foreach (['runtime_base','paths','synology','targets','notifications'] as $required) {
            $checks[$required] = array_key_exists($required, $this->config);
        }
        return $checks;
    }

    public function plannedDomains(): array
    {
        return $this->config['synology']['domains'] ?? [];
    }

    public function pullPlans(): array
    {
        $cacheKey = 'pullPlans';
        if (isset($this->planCache[$cacheKey])) {
            return $this->planCache[$cacheKey];
        }

        $plans = [];
        $ssh = $this->ssh();
        $synologyKey = $this->config['synology']['ssh_key'];
        $synologyUser = $this->config['synology']['user'];
        $synologyHost = $this->config['synology']['host'];

        foreach ($this->plannedDomains() as $domain => $meta) {
            $workDir = $this->config['paths']['working'] . '/' . $domain;
            if (!is_dir($workDir)) {
                mkdir($workDir, 0750, true);
            }

            $files = [
                ['remotePath' => $meta['source_dir'] . '/fullchain.cer', 'localPath' => $workDir . '/fullchain.cer'],
                ['remotePath' => $meta['source_dir'] . '/' . $domain . '.cer', 'localPath' => $workDir . '/' . $domain . '.cer'],
                ['remotePath' => $meta['source_dir'] . '/' . $domain . '.key', 'localPath' => $workDir . '/' . $domain . '.key'],
            ];

            $plans[$domain] = [
                'source_dir' => $meta['source_dir'],
                'work_dir' => $workDir,
                'commands' => $ssh->scpFromBatch($synologyKey, $synologyUser, $synologyHost, $files)
            ];
        }

        $this->planCache[$cacheKey] = $plans;
        return $plans;
    }

    public function localDeployPlans(): array
    {
        $cacheKey = 'localDeployPlans';
        if (isset($this->planCache[$cacheKey])) {
            return $this->planCache[$cacheKey];
        }

        $plans = [];
        $localBase = rtrim($this->config['paths']['local_itop_certs'], '/');
        foreach (array_keys($this->plannedDomains()) as $domain) {
            $dest = $localBase . '/' . $domain;
            if (!is_dir($dest)) {
                mkdir($dest, 0750, true);
            }
            $plans[$domain] = [
                'destination' => $dest,
                'files' => ['fullchain.pem', 'cert.pem', 'privkey.pem', 'chain.pem']
            ];
        }

        $this->planCache[$cacheKey] = $plans;
        return $plans;
    }

    public function downstreamPlans(): array
    {
        $cacheKey = 'downstreamPlans';
        if (isset($this->planCache[$cacheKey])) {
            return $this->planCache[$cacheKey];
        }

        $plans = [];
        foreach ($this->config['targets'] as $target) {
            foreach (array_keys($this->plannedDomains()) as $domain) {
                $remoteBase = rtrim($target['base_destination'], '/') . '/' . $domain;
                $plans[] = [
                    'target' => $target['name'],
                    'host' => $target['host'],
                    'user' => $target['user'],
                    'domain' => $domain,
                    'remote_base' => $remoteBase,
                ];
            }
        }

        $this->planCache[$cacheKey] = $plans;
        return $plans;
    }

    public function notificationPreview(): array
    {
        return $this->notificationService()->sendSummary(
            'Certificate pipeline preview',
            'Planned domains: ' . implode(', ', array_keys($this->plannedDomains()))
        );
    }

    /**
     * Execute the full pipeline: pull, validate, deploy locally, and distribute downstream.
     * Returns a structured execution report.
     */
    public function execute(): array
    {
        $report = ['steps' => [], 'errors' => []];
        $logger = $this->logger();

        $logger->info('Starting certificate pipeline execution');

        try {
            $checks = $this->verifyConfig();
            $report['steps'][] = ['step' => 'verifyConfig', 'status' => 'ok', 'details' => $checks];
            $logger->info('Config verification completed');
        } catch (\Throwable $e) {
            $report['errors'][] = ['step' => 'verifyConfig', 'error' => $e->getMessage()];
            $logger->error('Config verification failed: ' . $e->getMessage());
            return $report;
        }

        try {
            $pull = $this->pullPlans();
            foreach ($pull as $domain => $plan) {
                foreach ($plan['commands'] as $cmd) {
                    exec($cmd . ' 2>&1', $output, $exitCode);
                    if ($exitCode !== 0) {
                        $report['errors'][] = ['step' => 'pull', 'domain' => $domain, 'error' => implode("\n", $output)];
                        $logger->error("Pull failed for {$domain}: " . implode("\n", $output));
                    } else {
                        $logger->info("Pull succeeded for {$domain}");
                    }
                }
            }
            $report['steps'][] = ['step' => 'pull', 'status' => 'completed'];
        } catch (\Throwable $e) {
            $report['errors'][] = ['step' => 'pull', 'error' => $e->getMessage()];
            $logger->error('Pull step failed: ' . $e->getMessage());
        }

        try {
            $deploy = $this->localDeployPlans();
            foreach ($deploy as $domain => $plan) {
                $workDir = $this->config['paths']['working'] . '/' . $domain;
                $dest = $plan['destination'];

                $mapping = [
                    'fullchain.pem' => 'fullchain.cer',
                    'cert.pem' => $domain . '.cer',
                    'privkey.pem' => $domain . '.key',
                    'chain.pem' => 'fullchain.cer',
                ];

                foreach ($mapping as $outName => $srcName) {
                    $src = $workDir . '/' . $srcName;
                    $dst = $dest . '/' . $outName;
                    if (is_file($src)) {
                        copy($src, $dst);
                        chmod($dst, 0644);
                    }
                }
                $logger->info("Local deploy completed for {$domain}");
            }
            $report['steps'][] = ['step' => 'localDeploy', 'status' => 'completed'];
        } catch (\Throwable $e) {
            $report['errors'][] = ['step' => 'localDeploy', 'error' => $e->getMessage()];
            $logger->error('Local deploy failed: ' . $e->getMessage());
        }

        try {
            $downstream = $this->downstreamPlans();
            $targets = $this->config['targets'];
            $targetMap = [];
            foreach ($targets as $t) {
                $targetMap[$t['name']] = $t;
            }

            foreach ($downstream as $plan) {
                $t = $targetMap[$plan['target']] ?? null;
                if (!$t) {
                    continue;
                }
                $identity = $this->config['paths']['endpoints'] . '/' . $plan['target'];
                if (!is_file($identity)) {
                    $identity = $this->config['paths']['endpoints'] . '/default';
                }
                $workDir = $this->config['paths']['working'] . '/' . $plan['domain'];
                $remoteDest = $plan['remote_base'];

                foreach (['fullchain.cer', $plan['domain'] . '.cer', $plan['domain'] . '.key'] as $file) {
                    $cmd = $this->ssh()->scpTo($identity, $t['user'], $t['host'], $workDir . '/' . $file, $remoteDest . '/' . $file);
                    exec($cmd . ' 2>&1', $output, $exitCode);
                    if ($exitCode !== 0) {
                        $report['errors'][] = [
                            'step' => 'downstream',
                            'target' => $plan['target'],
                            'domain' => $plan['domain'],
                            'error' => implode("\n", $output)
                        ];
                        $logger->error("Downstream deploy failed for {$plan['target']}/{$plan['domain']}: " . implode("\n", $output));
                    } else {
                        $logger->info("Downstream deploy succeeded for {$plan['target']}/{$plan['domain']}");
                    }
                }
            }
            $report['steps'][] = ['step' => 'downstream', 'status' => 'completed'];
        } catch (\Throwable $e) {
            $report['errors'][] = ['step' => 'downstream', 'error' => $e->getMessage()];
            $logger->error('Downstream deploy failed: ' . $e->getMessage());
        }

        try {
            $summary = $this->notificationService()->sendSummary(
                'Certificate pipeline executed',
                'Domains: ' . implode(', ', array_keys($this->plannedDomains())) . ' | Errors: ' . count($report['errors'])
            );
            $report['steps'][] = ['step' => 'notification', 'status' => 'completed', 'details' => $summary];
            $logger->info('Notification sent');
        } catch (\Throwable $e) {
            $report['errors'][] = ['step' => 'notification', 'error' => $e->getMessage()];
            $logger->error('Notification failed: ' . $e->getMessage());
        }

        $this->ssh()->closeAllConnections();
        $logger->flush();
        return $report;
    }

    public function __destruct()
    {
        if ($this->ssh !== null) {
            $this->ssh->closeAllConnections();
        }
        if ($this->logger !== null) {
            $this->logger->flush();
        }
    }
}
