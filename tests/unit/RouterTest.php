<?php

declare(strict_types=1);

use App\Http\Request;
use App\Http\Response;
use App\Http\Router;

final class RouterTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $h = static fn(Request $q): Response => Response::ok(null);
        $r->add('GET', '/api/v1/staff/reservations/{id}', $h, 'staff');
        $r->add('PATCH', '/api/v1/staff/reservations/{id}', $h, 'staff');
        $r->add('GET', '/api/v1/staff/day', $h, 'staff');
        return $r;
    }

    public function testParameterIsExtracted(): void
    {
        $m = $this->router()->match('GET', '/api/v1/staff/reservations/42');
        $this->assertSame(200, $m['status']);
        $this->assertSame(['id' => '42'], $m['params']);
    }

    public function testParameterAcceptsDigitsOnly(): void
    {
        $this->assertSame(404, $this->router()->match('GET', '/api/v1/staff/reservations/4a')['status']);
        $this->assertSame(404, $this->router()->match('GET', '/api/v1/staff/reservations/../day')['status']);
    }

    public function testWrongMethodIs405WithAllow(): void
    {
        $m = $this->router()->match('DELETE', '/api/v1/staff/reservations/1');
        $this->assertSame(405, $m['status']);
        $this->assertSame(['GET', 'PATCH'], $m['allow']);
    }

    public function testUnknownPathIs404(): void
    {
        $this->assertSame(404, $this->router()->match('GET', '/api/v1/nic')['status']);
        // Brez delnega ujemanja na začetku ali koncu.
        $this->assertSame(404, $this->router()->match('GET', '/api/v1/staff/day/x')['status']);
    }

    public function testJsonBodyRejectsListAndGarbage(): void
    {
        $this->expectThrows(App\Domain\AppException::class,
            fn() => (new Request('POST', '/', rawBody: '[1,2]'))->json(), 'invalid_input');
        $this->expectThrows(App\Domain\AppException::class,
            fn() => (new Request('POST', '/', rawBody: '{nope'))->json(), 'invalid_input');
        $this->expectThrows(App\Domain\AppException::class,
            fn() => (new Request('POST', '/', rawBody: str_repeat('a', Request::MAX_BODY_BYTES + 1)))->json(), 'too_large');
        $this->assertSame([], (new Request('POST', '/', rawBody: ''))->json());
    }
}
