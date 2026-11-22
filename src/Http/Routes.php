<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\TenantContext;
use App\Http\Controllers\StaffReservationController;
use App\Service\ReservationService;
use Closure;

/**
 * Vse poti API na enem mestu. Test izolacije bere ta seznam, zato nova pot
 * brez testa ne ostane neopažena.
 */
final class Routes
{
    /** @param Closure(TenantContext): ReservationService $services */
    public static function build(Closure $services): Router
    {
        $r = new Router();
        $res = new StaffReservationController($services);
        $p = '/api/v1/staff';

        $r->add('GET',   "{$p}/day",                       $res->day(...),          'staff');
        $r->add('GET',   "{$p}/availability",              $res->availability(...), 'staff');
        $r->add('GET',   "{$p}/reservations",              $res->search(...),       'staff');
        $r->add('POST',  "{$p}/reservations",              $res->create(...),       'staff');
        $r->add('GET',   "{$p}/reservations/{id}",         $res->show(...),         'staff');
        $r->add('PATCH', "{$p}/reservations/{id}",         $res->update(...),       'staff');
        $r->add('POST',  "{$p}/reservations/{id}/status",  $res->status(...),       'staff');

        return $r;
    }
}
