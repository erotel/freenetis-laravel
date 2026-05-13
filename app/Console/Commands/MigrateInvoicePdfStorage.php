<?php

namespace App\Console\Commands;

use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Přesun PDF faktur z legacy Kohana umístění do storage/app/private/invoices/<rok>/
 * a synchronizace cest v `invoices.pdf_filename` a `email_queue_attachments.path`.
 *
 * Bezpečnost: kopíruje (copy + verify), nemaže originál. Po ověření zdroj smaž ručně.
 * Idempotentní — opakované spuštění přeskočí už přesunutá PDF (target_exists + DB
 * řádek už ukazuje na cílový tvar). DB update běží po každém souboru jednotlivě,
 * takže přerušení uprostřed neztratí pokrok.
 */
class MigrateInvoicePdfStorage extends Command
{
    protected $signature = 'invoices:migrate-pdf-storage
        {--legacy-path=* : Legacy kořeny (default autodetekce: /usr/share/freenetis/data/invoices, /var/www/html/freenetis-kohana/data/invoices, /var/www/html/freenetis/data/invoices)}
        {--dry-run : Jen vypiš co by se přesunulo, neměň ani soubory ani DB}
        {--no-files : Přeskoč kopírování souborů, jen updatuj DB (užitečné když už soubory existují na cílovém místě)}';

    protected $description = 'Migrace PDF faktur z legacy umístění do storage/app/private/invoices/.';

    private const DEFAULT_LEGACY_ROOTS = [
        '/usr/share/freenetis/data/invoices',
        '/var/www/html/freenetis-kohana/data/invoices',
        '/var/www/html/freenetis/data/invoices',
    ];

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $noFiles = (bool) $this->option('no-files');
        $roots   = $this->option('legacy-path') ?: self::DEFAULT_LEGACY_ROOTS;
        $target  = InvoiceService::pdfBasePath();

        $this->info('Cíl: ' . $target);
        $this->info('Legacy kořeny: ' . implode(', ', $roots));
        if ($dryRun)  $this->warn('[DRY-RUN] žádné změny');
        if ($noFiles) $this->warn('[NO-FILES] jen DB');

        if (!$dryRun && !is_dir($target)) {
            mkdir($target, 0775, true);
        }

        $stats = [
            'invoices'    => ['copied' => 0, 'skipped' => 0, 'missing' => 0, 'updated' => 0],
            'attachments' => ['copied' => 0, 'skipped' => 0, 'missing' => 0, 'updated' => 0],
        ];

        foreach ($roots as $root) {
            $stats['invoices']    = $this->processTable('invoices',                  'pdf_filename', $root, $target, $dryRun, $noFiles, $stats['invoices']);
            $stats['attachments'] = $this->processTable('email_queue_attachments',   'path',         $root, $target, $dryRun, $noFiles, $stats['attachments']);
        }

        $this->newLine();
        $this->info('— Souhrn —');
        foreach ($stats as $table => $s) {
            $this->line(sprintf(
                '  %-12s  zkopírováno: %4d   přeskočeno: %4d   chybí_zdroj: %4d   DB_updated: %4d',
                $table, $s['copied'], $s['skipped'], $s['missing'], $s['updated']
            ));
        }

        $this->newLine();
        $this->line('<fg=gray>Originální soubory v legacy umístění zůstaly. Po ověření smaž ručně:</>');
        foreach ($roots as $root) {
            if (is_dir($root)) $this->line('  rm -rf ' . $root);
        }

        return self::SUCCESS;
    }

    private function processTable(
        string $table,
        string $col,
        string $legacyRoot,
        string $target,
        bool $dryRun,
        bool $noFiles,
        array $stats
    ): array {
        $likePattern = rtrim($legacyRoot, '/') . '/%';
        $rows = DB::table($table)
            ->select('id', $col)
            ->where($col, 'like', $likePattern)
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            $oldPath = $row->$col;
            // Cesta tvaru <legacy_root>/<rok>/<file>.pdf
            $relative = ltrim(substr($oldPath, strlen($legacyRoot)), '/');
            $newPath  = $target . '/' . $relative;

            if (!$noFiles) {
                $newDir = dirname($newPath);
                if (!is_dir($newDir)) {
                    if (!$dryRun) mkdir($newDir, 0775, true);
                }

                if (file_exists($newPath)) {
                    $stats['skipped']++;
                } elseif (file_exists($oldPath)) {
                    if ($dryRun) {
                        $this->line("  [would copy] $oldPath → $newPath");
                    } else {
                        if (!@copy($oldPath, $newPath)) {
                            $this->error("FAIL copy: $oldPath → $newPath");
                            continue;
                        }
                        @chmod($newPath, 0664);
                    }
                    $stats['copied']++;
                } else {
                    // Soubor neexistuje na disku, ale DB cestu přesto opravíme
                    // (např. soubor se ztratil, ale chceme uniformní záznamy).
                    $stats['missing']++;
                }
            }

            if (!$dryRun) {
                DB::table($table)->where('id', $row->id)->update([$col => $newPath]);
            }
            $stats['updated']++;
        }

        return $stats;
    }
}
