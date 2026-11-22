<?php

declare(strict_types=1);

use App\Domain\Phone;
use App\Domain\ReservationStatus as S;
use App\Support\Random;

final class DomainTest extends TestCase
{
    public function testPhoneNormalization(): void
    {
        $this->assertSame('+38641234567', Phone::normalize('041 234 567', '386'));
        $this->assertSame('+38641234567', Phone::normalize('+386 41 234-567', '386'));
        $this->assertSame('+38641234567', Phone::normalize('00386 (41) 234 567', '386'));
        $this->assertSame('+4915112345678', Phone::normalize('+49 151 12345678', '386'));
        $this->assertSame(null, Phone::normalize('123', '386'));
        $this->assertSame(null, Phone::normalize('041 234 567; DROP', '386'));
        $this->assertSame(null, Phone::normalize('', '386'));
    }

    public function testStatusTransitions(): void
    {
        $this->assertTrue(S::Confirmed->canTransitionTo(S::Arrived));
        $this->assertTrue(S::Confirmed->canTransitionTo(S::Cancelled));
        $this->assertTrue(S::Confirmed->canTransitionTo(S::NoShow));
        $this->assertTrue(S::Arrived->canTransitionTo(S::Completed));
        $this->assertFalse(S::Cancelled->canTransitionTo(S::Arrived));
        $this->assertFalse(S::NoShow->canTransitionTo(S::Confirmed));
        $this->assertFalse(S::Arrived->canTransitionTo(S::Cancelled));
        $this->assertFalse(S::Completed->canTransitionTo(S::Arrived));
        $this->assertTrue(S::Arrived->holdsTable());
        $this->assertFalse(S::Cancelled->holdsTable());
    }

    public function testRandomFormats(): void
    {
        $code = Random::code();
        $this->assertSame(1, preg_match('/^[A-HJKMNP-Z2-9]{8}$/', $code), "koda {$code}");
        $this->assertSame(43, strlen(Random::token()));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', Random::token()));
    }

    public function testSchemaSplitsIntoCreateStatements(): void
    {
        $stmts = TestDb::statements((string) file_get_contents(__DIR__ . '/../../sql/schema.sql'));
        $creates = array_filter($stmts, fn($s) => str_starts_with($s, 'CREATE TABLE'));
        $this->assertSame(count($stmts) - 1, count($creates), 'vse razen SET NAMES so CREATE TABLE');
        $this->assertTrue(count($creates) >= 15, 'pričakovanih vsaj 15 tabel');
    }
}
