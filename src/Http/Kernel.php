<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\AppException;
use App\Support\Logger;
use PDOException;
use Throwable;

/**
 * Pot zahtevka: router -> HTTPS -> avtentikacija -> vloga -> kontroler.
 * Vsaka izjema se tu prevede v obliko iz docs/API.md. Notranje podrobnosti
 * gredo v dnevnik, nikoli v odgovor.
 */
final class Kernel
{
    public function __construct(
        private readonly Router $router,
        private readonly Authenticator $staffAuth,
        private readonly Authenticator $agentAuth,
        private readonly ?Logger $logger = null,
        private readonly bool $requireHttps = true,
    ) {
    }

    public function handle(Request $request): Response
    {
        $started   = microtime(true);
        $requestId = bin2hex(random_bytes(6));
        $tenantId  = null;
        $pattern   = '-';

        try {
            $match = $this->router->match($request->method, $request->path);
            if ($match['status'] === 404) {
                $response = Response::error(404, 'not_found', 'Ne obstaja.');
            } elseif ($match['status'] === 405) {
                $response = Response::error(405, 'method_not_allowed', 'Metoda ni dovoljena.')
                    ->withHeader('Allow', implode(', ', $match['allow']));
            } elseif ($this->requireHttps && !$request->https) {
                $response = Response::error(403, 'https_required', 'Zahtevana je povezava HTTPS.');
            } else {
                $route   = $match['route'];
                $pattern = $route['pattern'];
                $ctx     = null;

                if ($route['auth'] !== 'none') {
                    $ctx = ($route['auth'] === 'agent' ? $this->agentAuth : $this->staffAuth)->authenticate($request);
                    if ($ctx === null) {
                        throw new AppException('unauthorized', 'Prijava je potrebna.', 401);
                    }
                    // Seja osebja ne odpre poti za agenta in obratno.
                    $expected = $route['auth'] === 'agent' ? 'agent' : 'staff';
                    if ($ctx->actorType !== $expected) {
                        throw new AppException('unauthorized', 'Prijava je potrebna.', 401);
                    }
                    if ($route['auth'] === 'manager' && !$ctx->isManager()) {
                        throw new AppException('forbidden', 'Za to je potrebna vloga vodje.', 403);
                    }
                    $tenantId = $ctx->tenantId;
                }

                $response = ($route['handler'])($request->withParams($match['params']), $ctx);
            }
        } catch (AppException $e) {
            $response = Response::error($e->httpStatus, $e->errorCode, $e->getMessage(), $e->fields, $e->extra);
        } catch (PDOException $e) {
            $this->logger?->error(['request_id' => $requestId, 'type' => 'db', 'message' => $e->getMessage()]);
            error_log("[{$requestId}] baza: " . $e->getMessage());
            $response = Response::error(503, 'unavailable', 'Sistem trenutno ni dosegljiv.');
        } catch (Throwable $e) {
            $this->logger?->error([
                'request_id' => $requestId, 'type' => get_class($e), 'message' => $e->getMessage(),
                'at' => basename($e->getFile()) . ':' . $e->getLine(),
            ]);
            error_log("[{$requestId}] " . get_class($e) . ': ' . $e->getMessage());
            $response = Response::error(500, 'internal_error', 'Pri obdelavi zahteve je prišlo do napake.');
        }

        $this->logger?->request([
            'request_id'  => $requestId,
            'method'      => $request->method,
            'route'       => $pattern,
            'status'      => $response->status,
            'error'       => $response->body['error']['code'] ?? null,
            'tenant_id'   => $tenantId,
            'ip'          => $request->ip,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $response->withRequestId($requestId);
    }
}
