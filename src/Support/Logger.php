<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dnevnik zahtevkov v JSON Lines (logs/requests-YYYY-MM-DD.log).
 *
 * Telesa zahtevkov se ne pišejo: vsebujejo imena in telefone gostov. Za
 * sledenje zadostujejo request_id, pot, status in tenant. IP je maskiran.
 * Po vzoru Tatjanin tools/core/Logger.php.
 */
final class Logger
{
    public function __construct(
        private readonly string $dir,
        private readonly bool $maskPii = true,
        private readonly int $retentionDays = 14,
    ) {
    }

    /** @param array<string, mixed> $entry */
    public function request(array $entry): void
    {
        if (isset($entry['ip'])) {
            $entry['ip'] = $this->maskIp((string) $entry['ip']);
        }
        $this->write('requests', ['ts' => gmdate('c')] + $entry);
    }

    /** @param array<string, mixed> $entry */
    public function error(array $entry): void
    {
        $this->write('errors', ['ts' => gmdate('c')] + $entry);
    }

    /** @param array<string, mixed> $entry */
    private function write(string $prefix, array $entry): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . PHP_EOL;
        // Napaka pri pisanju dnevnika ne sme podreti odgovora.
        @file_put_contents($this->dir . '/' . $prefix . '-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);

        if (random_int(1, 200) === 1) {
            $cutoff = time() - $this->retentionDays * 86400;
            foreach ((array) glob($this->dir . '/*.log') as $file) {
                if (is_string($file) && is_file($file) && filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }

    public function maskIp(string $ip): string
    {
        if (!$this->maskPii) {
            return $ip;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) preg_replace('/\.\d+$/', '.x', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)) . '::x';
        }
        return '-';
    }
}
