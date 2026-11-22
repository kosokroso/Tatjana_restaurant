<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AppException;
use App\Domain\TenantContext;
use App\Http\Request;
use App\Http\Response;
use App\Service\ReservationService;
use Closure;

/**
 * Poti /api/v1/staff za rezervacije. Tanek sloj: prebere vhod, pokliče
 * service, vrne odgovor. Pravil tu ni.
 */
final class StaffReservationController
{
    /** @param Closure(TenantContext): ReservationService $services */
    public function __construct(private readonly Closure $services)
    {
    }

    private function service(TenantContext $ctx): ReservationService
    {
        return ($this->services)($ctx);
    }

    public function day(Request $req, TenantContext $ctx): Response
    {
        $data = $this->service($ctx)->day($this->queryInt($req, 'location'), $this->queryDate($req));
        // ETag nad vsebino: polling vsakih 5 s ne prenaša enakega dneva znova.
        $etag = '"' . substr(hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 0, 32) . '"';
        if ($req->header('if-none-match') === $etag) {
            return Response::notModified($etag);
        }
        return Response::ok($data)->withHeader('ETag', $etag);
    }

    public function availability(Request $req, TenantContext $ctx): Response
    {
        $duration = isset($req->query['duration']) ? $this->queryInt($req, 'duration') : null;
        $starts = $this->service($ctx)->availability(
            $this->queryInt($req, 'location'), $this->queryDate($req), $this->queryInt($req, 'party'), $duration
        );
        return Response::ok(['starts' => $starts]);
    }

    public function create(Request $req, TenantContext $ctx): Response
    {
        $result = $this->service($ctx)->create($req->json(), $req->header('idempotency-key'));
        return Response::ok($result['reservation'], $result['replayed'] ? 200 : 201);
    }

    public function show(Request $req, TenantContext $ctx): Response
    {
        return Response::ok($this->service($ctx)->get($req->intParam('id')));
    }

    public function update(Request $req, TenantContext $ctx): Response
    {
        return Response::ok($this->service($ctx)->update($req->intParam('id'), $req->json()));
    }

    public function status(Request $req, TenantContext $ctx): Response
    {
        $body = $req->json();
        $status = is_string($body['status'] ?? null) ? $body['status'] : '';
        $reason = is_string($body['reason'] ?? null) ? $body['reason'] : null;
        return Response::ok($this->service($ctx)->changeStatus($req->intParam('id'), $status, $reason));
    }

    public function search(Request $req, TenantContext $ctx): Response
    {
        return Response::ok($this->service($ctx)->search($req->query['phone'] ?? null, $req->query['q'] ?? null));
    }

    private function queryInt(Request $req, string $key): int
    {
        $v = $req->query[$key] ?? '';
        if (!preg_match('/^\d{1,9}$/', $v)) {
            throw AppException::invalid([$key => 'Pričakovano celo število.']);
        }
        return (int) $v;
    }

    private function queryDate(Request $req): string
    {
        $v = $req->query['date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw AppException::invalid(['date' => 'Datum mora biti v obliki 2026-12-05.']);
        }
        return $v;
    }
}
