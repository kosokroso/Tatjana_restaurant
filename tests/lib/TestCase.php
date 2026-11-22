<?php

declare(strict_types=1);

/** Najmanjši nabor preverjanj, ki ga testi potrebujejo. */
abstract class TestCase
{
    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionError(($msg !== '' ? $msg . ': ' : '')
                . 'pričakovano ' . var_export($expected, true) . ', dobljeno ' . var_export($actual, true));
        }
    }

    protected function assertTrue(bool $cond, string $msg = 'pričakovano true'): void
    {
        if (!$cond) {
            throw new AssertionError($msg);
        }
    }

    protected function assertFalse(bool $cond, string $msg = 'pričakovano false'): void
    {
        $this->assertTrue(!$cond, $msg);
    }

    protected function assertCount(int $n, array $arr, string $msg = ''): void
    {
        $this->assertSame($n, count($arr), $msg !== '' ? $msg : 'število elementov');
    }

    /**
     * Pričakuje izjemo; vrne jo za nadaljnja preverjanja.
     *
     * @template T of Throwable
     * @param class-string<T> $class
     * @return T
     */
    protected function expectThrows(string $class, callable $fn, ?string $errorCode = null): Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionError("pričakovano {$class}, dobljeno " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($errorCode !== null && ($e->errorCode ?? null) !== $errorCode) {
                throw new AssertionError("pričakovana koda {$errorCode}, dobljeno " . ($e->errorCode ?? '-'));
            }
            return $e;
        }
        throw new AssertionError("pričakovano {$class}, izjeme ni bilo");
    }

    protected function skip(string $why): never
    {
        throw new SkipTest($why);
    }
}
