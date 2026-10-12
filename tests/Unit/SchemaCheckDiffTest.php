<?php

namespace Tests\Unit;

use App\Console\Commands\SchemaCheck;
use Tests\TestCase;

/** Logika porovnání otisků schématu (schema:check) — bez DB. */
class SchemaCheckDiffTest extends TestCase
{
    private function fp(array $tables = [], array $views = []): array
    {
        return ['tables' => $tables, 'views' => $views];
    }

    private function table(array $columns, array $indexes = ['PRIMARY' => 'unique (id)'], string $collation = 'utf8mb4_unicode_ci'): array
    {
        return ['engine' => 'InnoDB', 'collation' => $collation, 'columns' => $columns, 'indexes' => $indexes];
    }

    public function test_shodne_otisky_bez_rozdilu(): void
    {
        $fp = $this->fp(['t' => $this->table(['id' => 'int(11) | not null | default ∅ | auto_increment | -'])], ['v' => 'select 1']);
        $this->assertSame([], (new SchemaCheck())->diff($fp, $fp));
    }

    /** Přesně případ remote_id na .54: ruční změna typu sloupce. */
    public function test_zmena_typu_sloupce(): void
    {
        $repo = $this->fp(['line_ids' => $this->table(['remote_id' => 'varchar(255) | null | default ∅ | - | utf8mb4_unicode_ci'])]);
        $live = $this->fp(['line_ids' => $this->table(['remote_id' => "varbinary(255) | not null | default '' | - | -"])]);

        $this->assertSame(
            ["line_ids.remote_id: varchar(255) | null | default ∅ | - | utf8mb4_unicode_ci  →  varbinary(255) | not null | default '' | - | -"],
            (new SchemaCheck())->diff($repo, $live)
        );
    }

    public function test_chybejici_a_navic_objekty(): void
    {
        $repo = $this->fp(
            ['a' => $this->table(['id' => 'x', 'old' => 'y'], ['PRIMARY' => 'unique (id)', 'ix' => 'index (old)'])],
            ['v1' => 'select 1', 'v2' => 'select 2']
        );
        $live = $this->fp(
            ['a' => $this->table(['id' => 'x', 'new' => 'z'], ['PRIMARY' => 'unique (id)']), 'b' => $this->table([])],
            ['v1' => 'select 1 + 1', 'v3' => 'select 3']
        );

        $diff = (new SchemaCheck())->diff($repo, $live);
        foreach ([
            'chybí view v2', 'navíc tabulka b (není v repu)', 'navíc view v3 (není v repu)', 'view v1: jiná definice',
            'a: chybí sloupec old', 'a: navíc sloupec new (z)', 'a: chybí index ix',
        ] as $line) {
            $this->assertContains($line, $diff);
        }
        $this->assertCount(7, $diff);
    }

    public function test_zmena_collation_tabulky(): void
    {
        $repo = $this->fp(['t' => $this->table([], [], 'utf8mb4_unicode_ci')]);
        $live = $this->fp(['t' => $this->table([], [], 'utf8mb3_czech_ci')]);
        $this->assertSame(['t: collation utf8mb4_unicode_ci → utf8mb3_czech_ci'], (new SchemaCheck())->diff($repo, $live));
    }
}
