<?php

namespace App\Console\Commands;

use App\Support\AclRouteCoverage;
use Illuminate\Console\Command;

/**
 * Bezpečnostní audit pokrytí autorizací (NIS2/ZoKB) — report.
 * Vypíše, kolik přihlášených rout je chráněno deklarativním `acl:` middleware /
 * controller checkem, a které nemají viditelnou autorizaci (k posouzení).
 */
class AclCoverage extends Command
{
    protected $signature = 'acl:coverage {--gaps : Vypsat jen mezery}';

    protected $description = 'Audit pokrytí rout autorizací (acl middleware / aclCheck)';

    public function handle(AclRouteCoverage $coverage): int
    {
        $r = $coverage->scan();

        if (!$this->option('gaps')) {
            $this->info('Pokrytí přihlášených rout autorizací:');
            $this->line("  • deklarativní acl middleware: {$r['byMiddleware']}");
            $this->line("  • aclCheck/abort v controlleru: {$r['byController']}");
            $this->line('  • MEZERY (k posouzení): ' . count($r['gaps']));
            $this->newLine();
        }

        if (!empty($r['gaps'])) {
            $this->warn('Routy bez viditelné autorizace (posoudit — self-service/ownership může být OK):');
            foreach ($r['gaps'] as $g) {
                $this->line('  ' . str_pad($g['methods'], 10) . ' ' . str_pad($g['uri'], 40) . ' → ' . $g['action']);
            }
        } else {
            $this->info('Žádné mezery — všechny přihlášené routy mají autorizaci.');
        }

        return self::SUCCESS;
    }
}
