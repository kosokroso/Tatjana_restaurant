<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Ročni router. Vzorci poti so fiksni nizi z mesti {ime}; vsako mesto
 * sprejme samo števke. Regularni izraz se sestavi iz kode, nikoli iz vnosa.
 */
final class Router
{
    /** @var list<array{method:string, pattern:string, regex:string, handler:callable, auth:string}> */
    private array $routes = [];

    /**
     * @param string   $auth    'none' | 'staff' | 'manager' | 'agent'
     * @param callable $handler fn(Request, ?\App\Domain\TenantContext): Response
     */
    public function add(string $method, string $pattern, callable $handler, string $auth): void
    {
        $regex = '#^' . preg_replace('#\\\\\{[a-z_]+\\\\\}#', '(\d{1,18})', preg_quote($pattern, '#')) . '$#';
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $pattern,
            'regex'   => $regex,
            'handler' => $handler,
            'auth'    => $auth,
        ];
    }

    /**
     * @return array{status:int, route?:array{method:string, pattern:string, regex:string, handler:callable, auth:string}, params?:array<string,string>, allow?:list<string>}
     */
    public function match(string $method, string $path): array
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== strtoupper($method)) {
                $allowed[] = $route['method'];
                continue;
            }
            preg_match_all('#\{([a-z_]+)\}#', $route['pattern'], $names);
            $params = [];
            foreach ($names[1] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            return ['status' => 200, 'route' => $route, 'params' => $params];
        }
        if ($allowed !== []) {
            return ['status' => 405, 'allow' => array_values(array_unique($allowed))];
        }
        return ['status' => 404];
    }

    /**
     * Seznam poti za test izolacije: nova pot brez testa se pokaže.
     *
     * @return list<array{method:string, pattern:string, auth:string}>
     */
    public function routes(): array
    {
        return array_map(
            static fn(array $r): array => ['method' => $r['method'], 'pattern' => $r['pattern'], 'auth' => $r['auth']],
            $this->routes
        );
    }
}
