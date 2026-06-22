<?php
namespace Cpc\ITop\AcmeManager\Service;

class SshRunner
{
    private string $controlPathBase;
    private int $controlPersistSeconds;
    private array $openConnections = [];

    public function __construct(
        ?string $controlPathBase = null,
        int $controlPersistSeconds = 300
    ) {
        $this->controlPathBase = $controlPathBase ?? sys_get_temp_dir() . '/cpc_ssh_mux_' . getmypid();
        $this->controlPersistSeconds = max(30, $controlPersistSeconds);
    }

    /**
     * Build SSH command string with ControlMaster multiplexing.
     */
    public function run(string $identityFile, string $user, string $host, string $command): string
    {
        $muxSocket = $this->controlSocketPath($identityFile, $user, $host);
        $this->ensureControlMaster($identityFile, $user, $host, $muxSocket);

        $ssh = sprintf(
            'ssh -i %s -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o ControlPath=%s -o ControlMaster=no %s@%s %s',
            escapeshellarg($identityFile),
            escapeshellarg($muxSocket),
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($command)
        );
        return $ssh;
    }

    /**
     * Build SCP from remote command with multiplexing.
     */
    public function scpFrom(string $identityFile, string $user, string $host, string $remotePath, string $localPath): string
    {
        $muxSocket = $this->controlSocketPath($identityFile, $user, $host);
        $this->ensureControlMaster($identityFile, $user, $host, $muxSocket);

        return sprintf(
            'scp -i %s -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o ControlPath=%s -o ControlMaster=no %s@%s:%s %s',
            escapeshellarg($identityFile),
            escapeshellarg($muxSocket),
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($remotePath),
            escapeshellarg($localPath)
        );
    }

    /**
     * Build SCP to remote command with multiplexing.
     */
    public function scpTo(string $identityFile, string $user, string $host, string $localPath, string $remotePath): string
    {
        $muxSocket = $this->controlSocketPath($identityFile, $user, $host);
        $this->ensureControlMaster($identityFile, $user, $host, $muxSocket);

        return sprintf(
            'scp -i %s -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o ControlPath=%s -o ControlMaster=no %s %s@%s:%s',
            escapeshellarg($identityFile),
            escapeshellarg($muxSocket),
            escapeshellarg($localPath),
            escapeshellarg($user),
            escapeshellarg($host),
            escapeshellarg($remotePath)
        );
    }

    /**
     * Execute a batch of SCP commands for the same host efficiently.
     * Uses a single SSH control master and parallel scp with multiplexing.
     *
     * @param array<int, array{remotePath: string, localPath: string}> $files
     */
    public function scpFromBatch(string $identityFile, string $user, string $host, array $files): array
    {
        $muxSocket = $this->controlSocketPath($identityFile, $user, $host);
        $this->ensureControlMaster($identityFile, $user, $host, $muxSocket);

        $commands = [];
        foreach ($files as $file) {
            $commands[] = sprintf(
                'scp -i %s -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o ControlPath=%s -o ControlMaster=no %s@%s:%s %s',
                escapeshellarg($identityFile),
                escapeshellarg($muxSocket),
                escapeshellarg($user),
                escapeshellarg($host),
                escapeshellarg($file['remotePath']),
                escapeshellarg($file['localPath'])
            );
        }
        return $commands;
    }

    /**
     * Execute a remote command and return the result (not just the command string).
     */
    public function exec(string $identityFile, string $user, string $host, string $command): array
    {
        $cmd = $this->run($identityFile, $user, $host, $command);
        exec($cmd . ' 2>&1', $output, $exitCode);
        return ['exit_code' => $exitCode, 'output' => implode("\n", $output), 'command' => $cmd];
    }

    private function controlSocketPath(string $identityFile, string $user, string $host): string
    {
        return $this->controlPathBase . '_' . md5($identityFile . $user . $host) . '_control';
    }

    private function ensureControlMaster(string $identityFile, string $user, string $host, string $muxSocket): void
    {
        $connKey = md5($identityFile . $user . $host);
        if (($this->openConnections[$connKey] ?? false) && file_exists($muxSocket)) {
            return;
        }

        $dir = dirname($muxSocket);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $master = sprintf(
            'ssh -i %s -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=30 -o ControlPath=%s -o ControlMaster=yes -o ControlPersist=%d -fN %s@%s',
            escapeshellarg($identityFile),
            escapeshellarg($muxSocket),
            $this->controlPersistSeconds,
            escapeshellarg($user),
            escapeshellarg($host)
        );

        exec($master . ' 2>&1', $output, $exitCode);
        $this->openConnections[$connKey] = ($exitCode === 0 && file_exists($muxSocket));
    }

    public function closeAllConnections(): void
    {
        foreach ($this->openConnections as $key => $active) {
            if (!$active) {
                continue;
            }
            // Best-effort cleanup of control sockets in temp dir
            foreach (glob($this->controlPathBase . '_*_control') as $sock) {
                if (file_exists($sock)) {
                    @unlink($sock);
                }
            }
        }
        $this->openConnections = [];
    }

    public function __destruct()
    {
        $this->closeAllConnections();
    }
}
