<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\DiningTable;
use App\Domain\TableCombo;
use App\Domain\TenantContext;
use App\Support\Database;

final class TableRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $ctx,
    ) {
    }

    /** @return list<DiningTable> tudi neaktivne; izbira jih izloči sama */
    public function forLocation(int $locationId): array
    {
        return array_map([DiningTable::class, 'fromRow'], $this->db->all(
            'SELECT id, label, min_seats, max_seats, active, zone, sort_order
               FROM dining_tables
              WHERE tenant_id = ? AND location_id = ?
              ORDER BY sort_order, id',
            [$this->ctx->tenantId, $locationId]
        ));
    }

    /** @return list<TableCombo> */
    public function combos(int $locationId): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.max_seats, m.table_id
               FROM table_combos c
               JOIN table_combo_members m ON m.combo_id = c.id AND m.tenant_id = c.tenant_id
              WHERE c.tenant_id = ? AND c.location_id = ?
              ORDER BY c.id, m.table_id',
            [$this->ctx->tenantId, $locationId]
        );
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']]['max'] = (int) $r['max_seats'];
            $byId[(int) $r['id']]['tables'][] = (int) $r['table_id'];
        }
        $out = [];
        foreach ($byId as $id => $c) {
            $out[] = new TableCombo($id, $c['max'], $c['tables']);
        }
        return $out;
    }
}
