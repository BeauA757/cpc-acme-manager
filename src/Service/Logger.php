<?php
namespace Cpc\ITop\AcmeManager\Service;

class Logger
{
    private array $buffer = [];
    private int $bufferSize = 0;
    private int $maxBufferSize;
    private int $maxLogSizeBytes;
    private int $maxLogFiles;

    public function __construct(
        private string $logFile,
        int $maxBufferSize = 32,
        int $maxLogSizeBytes = 10485760, // 10 MB
        int $maxLogFiles = 5
    ) {
        $this->maxBufferSize = max(1, $maxBufferSize);
        $this->maxLogSizeBytes = max(1048576, $maxLogSizeBytes); // min 1 MB
        $this->maxLogFiles = max(1, $maxLogFiles);

        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    public function __destruct()
    {
        $this->flush();
    }

    public function write(string $level, string $message): void
    {
        $this->buffer[] = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), strtoupper($level), $message);
        $this->bufferSize++;

        if ($this->bufferSize >= $this->maxBufferSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->bufferSize === 0) {
            return;
        }

        $this->rotateIfNeeded();

        $data = implode('', $this->buffer);
        file_put_contents($this->logFile, $data, FILE_APPEND | LOCK_EX);

        $this->buffer = [];
        $this->bufferSize = 0;
    }

    private function rotateIfNeeded(): void
    {
        if (!is_file($this->logFile) || filesize($this->logFile) < $this->maxLogSizeBytes) {
            return;
        }

        $base = $this->logFile;
        $ext = '';
        if (strpos($base, '.') !== false) {
            $ext = '.' . pathinfo($base, PATHINFO_EXTENSION);
            $base = substr($base, 0, -strlen($ext));
        }

        for ($i = $this->maxLogFiles - 1; $i >= 1; $i--) {
            $old = $base . '.' . $i . $ext;
            $new = $base . '.' . ($i + 1) . $ext;
            if (is_file($old)) {
                rename($old, $new);
            }
        }
        rename($this->logFile, $base . '.1' . $ext);
    }

    public function debug(string $message): void
    {
        $this->write('DEBUG', $message);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }
}
