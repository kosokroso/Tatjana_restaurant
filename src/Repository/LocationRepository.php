<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Location;
use App\Domain\TenantContext;
use App\Support\Database;

final class LocationRepository
{
    private const COLUMNS = 'id, tenant_id, name, timezone, phone_public, slot_minutes, default_duration_min,
        max_party_size, min_lead_minutes, max_days_ahead, allow_table_merge, phone_country_code';

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $ctx,
    ) {
    }

    public function find(int $id): ?Location
    {
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM locations WHERE tenant_id = ? AND id = ?',
            [$this->ctx->tenantId, $id]
        );
        return $row ? Location::fromRow($row) : null;
    }

    /**
     * Zaklene vrstico lokacije do konca transakcije.
     *
     * To je mutex za vse, ki pišejo rezervacije te lokacije. Kliče se kot
     * prvi stavek transakcije. Glej sql/schema.sql, razdelek ZAKLEPANJE.
     *
     * $lock = false obstaja samo za test, ki dokaže, da UNIQUE na rezinah
     * prepreči dvojno zasedbo tudi brez zaklepa.
     */
    public function lockForWrite(int $id, bool $lock = true): ?Location
    {
        $row = $this->db->one(
            'SELECT ' . self::COLUMNS . ' FROM locations WHERE tenant_id = ? AND id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$this->ctx->tenantId, $id]
        );
        return $row ? Location::fromRow($row) : null;
    }

    /** @return list<Location> */
    public function all(): array
    {
        return array_map(
            [Location::class, 'fromRow'],
            $this->db->all('SELECT ' . self::COLUMNS . ' FROM locations WHERE tenant_id = ? ORDER BY id', [$this->ctx->tenantId])
        );
    }
}
