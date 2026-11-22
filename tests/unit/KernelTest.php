<?php

declare(strict_types=1);

use App\Domain\AppException;
use App\Domain\TenantContext;
use App\Http\Authenticator;
use App\Http\DenyAllAuthenticator;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Http\Routes;

final class FixedAuth implements Authenticator
{
    public function __construct(private readonly ?TenantContext $ctx)
    {
    }

    public function authenticate(Request $request): ?TenantContext
    {
        return $this->ctx;
    }
}

final class KernelTest extends TestCase
{
    private function router(): Router
    {
        $r = new Router();
        $r->add('GET', '/s', fn(Request $q, ?TenantContext $c) => Response::ok(['tenant' => $c?->tenantId]), 'staff');
        $r->add('GET', '/m', fn() => Response::ok('m'), 'manager');
        $r->add('GET', '/a', fn() => Response::ok('a'), 'agent');
        $r->add('GET', '/boom', fn() => throw new RuntimeException('skrivnost baze'), 'none');
        $r->add('GET', '/rule', fn() => throw AppException::rule('closed', 'Zaprto.'), 'none');
        return $r;
    }

    private function get(Kernel $k, string $path, bool $https = true): Response
    {
        return $k->handle(new Request('GET', $path, https: $https));
    }

    public function testRealRoutesAreClosedInPhaseOne(): void
    {
        $k = new Kernel(Routes::build(fn() => throw new LogicException('ne sme do servisa')),
            new DenyAllAuthenticator(), new DenyAllAuthenticator());
        foreach (Routes::build(fn() => null)->routes() as $route) {
            $path = str_replace('{id}', '1', $route['pattern']);
            $res = $k->handle(new Request($route['method'], $path, https: true));
            $this->assertSame(401, $res->status, "{$route['method']} {$path}");
        }
    }

    public function testStaffContextReachesHandler(): void
    {
        $k = new Kernel($this->router(), new FixedAuth(TenantContext::staff(7, 1, 'staff')), new DenyAllAuthenticator());
        $res = $this->get($k, '/s');
        $this->assertSame(200, $res->status);
        $this->assertSame(7, $res->body['data']['tenant']);
        $this->assertTrue(isset($res->body['request_id']));
    }

    public function testAgentContextCannotUseStaffRouteAndViceVersa(): void
    {
        $agent = new FixedAuth(TenantContext::agent(7, 1, 1));
        $staff = new FixedAuth(TenantContext::staff(7, 1, 'manager'));
        // Napačno povezan authenticator ne sme odpreti tuje poti.
        $k = new Kernel($this->router(), $agent, $staff);
        $this->assertSame(401, $this->get($k, '/s')->status);
        $this->assertSame(401, $this->get($k, '/a')->status);
    }

    public function testManagerRouteNeedsManagerRole(): void
    {
        $k = new Kernel($this->router(), new FixedAuth(TenantContext::staff(7, 1, 'staff')), new DenyAllAuthenticator());
        $this->assertSame(403, $this->get($k, '/m')->status);
        $k2 = new Kernel($this->router(), new FixedAuth(TenantContext::staff(7, 1, 'manager')), new DenyAllAuthenticator());
        $this->assertSame(200, $this->get($k2, '/m')->status);
    }

    public function testHttpsRequired(): void
    {
        $k = new Kernel($this->router(), new DenyAllAuthenticator(), new DenyAllAuthenticator());
        $this->assertSame(403, $this->get($k, '/rule', false)->status);
    }

    public function testErrorsAreMappedWithoutLeakingDetails(): void
    {
        $k = new Kernel($this->router(), new DenyAllAuthenticator(), new DenyAllAuthenticator());
        $res = $this->get($k, '/boom');
        $this->assertSame(500, $res->status);
        $this->assertFalse(str_contains(json_encode($res->body), 'skrivnost'), 'sporočilo izjeme ne sme v odgovor');

        $rule = $this->get($k, '/rule');
        $this->assertSame(422, $rule->status);
        $this->assertSame('closed', $rule->body['error']['code']);

        $this->assertSame(404, $this->get($k, '/nic')->status);
        $this->assertSame(405, $k->handle(new Request('POST', '/s', https: true))->status);
    }
}
