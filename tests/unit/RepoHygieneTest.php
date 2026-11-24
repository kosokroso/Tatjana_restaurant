<?php

declare(strict_types=1);

/**
 * Pravila repozitorija, ki so v Tatjani že enkrat povzročila izpad:
 * BOM tiho podre odgovore, skrivnost v gitu je javna.
 */
final class RepoHygieneTest extends TestCase
{
    /** @return list<string> */
    private function textFiles(): array
    {
        $root = realpath(__DIR__ . '/../..');
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            if (str_contains($path, '/.git/') || str_contains($path, '/logs/')) {
                continue;
            }
            if (preg_match('/\.(php|md|sql|js|json|css|html|yml|yaml|htaccess|gitignore)$/', $path) || basename($path) === '.htaccess') {
                $out[] = $path;
            }
        }
        return $out;
    }

    public function testNoUtf8Bom(): void
    {
        $files = $this->textFiles();
        $this->assertTrue(count($files) > 20, 'premalo datotek; iskanje ne deluje');
        foreach ($files as $path) {
            $this->assertFalse(str_starts_with((string) file_get_contents($path, false, null, 0, 3), "\xEF\xBB\xBF"), "BOM: {$path}");
        }
    }

    public function testLocalConfigsAreIgnored(): void
    {
        $ignore = (string) file_get_contents(__DIR__ . '/../../.gitignore');
        foreach (['config/config.php', 'tests/config.test.php', 'logs/'] as $entry) {
            $this->assertTrue(str_contains($ignore, $entry), ".gitignore nima {$entry}");
        }
    }

    public function testNoSecretsInTrackedLookingFiles(): void
    {
        foreach ($this->textFiles() as $path) {
            if (str_ends_with($path, 'config/config.php') || str_ends_with($path, 'tests/config.test.php')) {
                continue; // lokalni, v .gitignore
            }
            $src = (string) file_get_contents($path);
            $this->assertFalse((bool) preg_match('/\b(sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|AC[0-9a-f]{32})\b/', $src), "videti kot ključ: {$path}");
            // Prazno ali nadomestek z velikimi črkami (npr. 'GESLO') je dovoljeno.
            $this->assertFalse((bool) preg_match("/'pass'\s*=>\s*'(?![A-Z_]*')[^']+'/", $src), "geslo v datoteki: {$path}");
        }
    }
}
