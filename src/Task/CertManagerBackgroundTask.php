<?php
/**
 * CPC Certificate Manager background task for iTop 3.1/3.2
 *
 * Implements iBackgroundProcess for cron-driven certificate pipeline execution.
 * Designed for reliable, resilient execution with error handling and retry logic.
 */

namespace Cpc\ITop\AcmeManager\Task;

use Cpc\ITop\AcmeManager\Service\CertificatePipeline;

class CertManagerBackgroundTask implements \iBackgroundProcess
{
    private const MODULE_CODE = 'cpc-acme-manager';
    private const FREQUENCY = 300; // 5 minutes in seconds

    public function GetModuleName(): string
    {
        return self::MODULE_CODE;
    }

    public function GetPeriodicity(): int
    {
        return self::FREQUENCY;
    }

    /**
     * Process the background task.
     *
     * @param int $iUnixTimeLimit Maximum time allowed for execution (seconds since epoch)
     *
     * @return string Human-readable status for cron log
     */
    public function Process($iUnixTimeLimit): string
    {
        $startTime = time();
        $maxDuration = max(1, $iUnixTimeLimit - $startTime - 10);

        try {
            $pipeline = new CertificatePipeline();
            $report = $pipeline->execute();

            $status = 'Certificate pipeline completed';
            $errorCount = count($report['errors'] ?? []);
            if ($errorCount > 0) {
                $status .= " with {$errorCount} errors";
            }

            $this->LogInfo($status);
            return $status;
        } catch (\Throwable $e) {
            $error = 'Certificate pipeline failed: ' . $e->getMessage();
            $this->LogError($error);
            return $error;
        }
    }

    private function LogInfo(string $message): void
    {
        if (class_exists('SetupLog', false)) {
            \SetupLog::Info("[CPC CertManager] {$message}");
        }
    }

    private function LogError(string $message): void
    {
        if (class_exists('SetupLog', false)) {
            \SetupLog::Error("[CPC CertManager] {$message}");
        }
    }
}
