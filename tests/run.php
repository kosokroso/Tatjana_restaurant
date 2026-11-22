<?php
/**
 * Tekač testov brez PHPUnit (Composerja ni; nova odvisnost ni utemeljena).
 *
 *   php tests/run.php              vsi testi
 *   php tests/run.php unit         samo tests/unit
 *   php tests/run.php integration  samo tests/integration (potrebuje testno bazo)
 *
 * Najde *Test.php, ustvari razred, požene vse javne metode test*().
 * Izhodna koda 1, če kateri pade. Integracijski testi brez testne baze se
 * preskočijo in to je izrecno izpisano: preskočen test ni zelen test.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/lib/TestCase.php';
require __DIR__ . '/lib/SkipTest.php';
require __DIR__ . '/lib/TestDb.php';
require __DIR__ . '/lib/Fixtures.php';

$filter = $argv[1] ?? '';
$dirs = $filter !== '' ? [__DIR__ . '/' . $filter] : [__DIR__ . '/unit', __DIR__ . '/integration', __DIR__ . '/concurrency'];

$files = [];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        fwrite(STDERR, "Ni mape: {$dir}\n");
        exit(2);
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
        if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
            $files[] = $f->getPathname();
        }
    }
}
sort($files);

$passed = $failed = $skipped = 0;
$failures = [];

foreach ($files as $file) {
    $before = get_declared_classes();
    require_once $file;
    $classes = array_filter(
        array_diff(get_declared_classes(), $before),
        static fn(string $c): bool => is_subclass_of($c, TestCase::class)
    );
    foreach ($classes as $class) {
        foreach (get_class_methods($class) as $method) {
            if (!str_starts_with($method, 'test')) {
                continue;
            }
            $label = $class . '::' . $method;
            try {
                $t = new $class();
                $t->setUp();
                try {
                    $t->$method();
                } finally {
                    $t->tearDown();
                }
                $passed++;
                echo "  ok    {$label}\n";
            } catch (SkipTest $e) {
                $skipped++;
                echo "  SKIP  {$label}: {$e->getMessage()}\n";
            } catch (Throwable $e) {
                $failed++;
                $failures[] = $label;
                echo "  FAIL  {$label}\n        " . get_class($e) . ': ' . $e->getMessage()
                    . "\n        " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
            }
        }
    }
}

echo "\n{$passed} ok, {$failed} padlo, {$skipped} preskočeno\n";
exit($failed > 0 ? 1 : 0);
