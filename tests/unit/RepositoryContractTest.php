<?php

declare(strict_types=1);

use App\Domain\TenantContext;

/**
 * Strukturna pravila repository sloja, preverjena brez baze.
 */
final class RepositoryContractTest extends TestCase
{
    /** @return list<string> */
    private function files(): array
    {
        return glob(__DIR__ . '/../../src/Repository/*.php') ?: [];
    }

    public function testEveryRepositoryRequiresTenantContext(): void
    {
        $this->assertTrue(count($this->files()) > 0, 'ni repositoryjev');
        foreach ($this->files() as $file) {
            $class = 'App\\Repository\\' . basename($file, '.php');
            $ctor = (new ReflectionClass($class))->getConstructor();
            $types = array_map(fn($p) => (string) $p->getType(), $ctor?->getParameters() ?? []);
            $this->assertTrue(in_array(TenantContext::class, $types, true), "{$class} nima TenantContext v konstruktorju");
        }
    }

    /**
     * Vsak SQL stavek nad tabelo s tenant_id mora omeniti tenant_id.
     * Grobo preverjanje besedila, ne razčlenjevanje SQL; ujame pozabljen pogoj.
     */
    public function testEverySqlMentionsTenantId(): void
    {
        $checked = 0;
        foreach ($this->files() as $file) {
            $src = (string) file_get_contents($file);
            // Vsak klic $this->db->run/one/all do konca PHP stavka, z vsemi
            // konkatenacijami. Konstanta COLUMNS vsebuje samo imena stolpcev.
            foreach (preg_split('/\$this->db->(?=run|one|all)/', $src) as $i => $segment) {
                if ($i === 0) {
                    continue;
                }
                $call = substr($segment, 0, (int) strpos($segment, ';'));
                if (!preg_match('/\b(FROM|INTO|UPDATE)\s+\w+/', $call)) {
                    continue; // SET time_zone in podobno
                }
                $checked++;
                $this->assertTrue(
                    str_contains($call, 'tenant_id'),
                    basename($file) . ': SQL brez tenant_id: ' . preg_replace('/\s+/', ' ', substr($call, 0, 140))
                );
            }
        }
        $this->assertTrue($checked >= 15, "preverjenih le {$checked} stavkov; razčlenjevanje ne deluje");
    }

    public function testNoSqlOutsideRepositoryAndSupport(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../src'));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            if (!$f->isFile() || str_contains($path, '/Repository/') || str_contains($path, '/Support/Database.php')) {
                continue;
            }
            $src = (string) file_get_contents($f->getPathname());
            $this->assertFalse(
                (bool) preg_match('/[\'"]\s*(SELECT|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b/i', $src),
                "SQL izven repositoryja: {$path}"
            );
        }
    }
}
