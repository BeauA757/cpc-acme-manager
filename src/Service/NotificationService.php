<?php
namespace Cpc\ITop\AcmeManager\Service;

class NotificationService
{
    private array $queue = [];
    private int $maxRetries = 3;
    private float $retryDelaySeconds = 2.0;

    public function __construct(private array $config)
    {
    }

    /**
     * Queue a notification for batch delivery.
     */
    public function queue(string $subject, string $body): void
    {
        $this->queue[] = ['subject' => $subject, 'body' => $body];
    }

    /**
     * Flush all queued notifications with retry logic.
     */
    public function flushQueue(): array
    {
        $results = [];
        foreach ($this->queue as $item) {
            $results[] = $this->sendWithRetry($item['subject'], $item['body']);
        }
        $this->queue = [];
        return $results;
    }

    /**
     * Send a summary notification with retry logic.
     */
    public function sendSummary(string $subject, string $body): array
    {
        return $this->sendWithRetry($subject, $body);
    }

    private function sendWithRetry(string $subject, string $body): array
    {
        $subject = trim(($this->config['notifications']['email_subject_prefix'] ?? 'CPC Cert Alert') . ' - ' . $subject);
        $targets = array_filter(array_merge(
            [$this->config['notifications']['teams_channel_email'] ?? ''],
            $this->config['notifications']['also_email'] ?? []
        ));

        $result = [
            'subject' => $subject,
            'targets' => array_values($targets),
            'body' => $body,
            'attempts' => 0,
            'success' => false,
            'last_error' => null,
            'note' => 'Integrate with your existing iTop/MS365 notification configuration before production enablement.'
        ];

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $result['attempts'] = $attempt;
            try {
                $this->doSend($result['targets'], $subject, $body);
                $result['success'] = true;
                break;
            } catch (\Throwable $e) {
                $result['last_error'] = $e->getMessage();
                if ($attempt < $this->maxRetries) {
                    usleep((int) ($this->retryDelaySeconds * 1000000));
                }
            }
        }

        return $result;
    }

    /**
     * Send via iTop email if available, otherwise fall back to mail().
     *
     * @param array<int, string> $to
     */
    private function doSend(array $to, string $subject, string $body): void
    {
        if (class_exists('EmailMessage', false)) {
            $oEmail = new \EmailMessage();
            $oEmail->SetSubject($subject);
            $oEmail->SetBody($body, 'text/plain', 'utf-8');
            foreach ($to as $address) {
                $oEmail->AddTo($address);
            }
            $oEmail->Send();
            return;
        }

        foreach ($to as $address) {
            $sent = mail($address, $subject, $body, 'Content-Type: text/plain; charset=utf-8');
            if (!$sent) {
                throw new \RuntimeException('mail() failed for ' . $address);
            }
        }
    }
}
