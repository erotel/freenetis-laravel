<?php

namespace App\Services;

class FioCsvParser
{
    // Column header aliases (Kohana compatibility)
    private const COLUMN_ALIASES = [
        'ID pohybu'                => 'id_pohybu',
        'ID operace'               => 'id_pohybu',
        'Datum'                    => 'datum',
        'Objem'                    => 'castka',
        'Měna'                     => 'mena',
        'Protiúčet'                => 'protiucet',
        'Název protiúčtu'          => 'nazev_protiuctu',
        'Kód banky'                => 'kod_banky',
        'Název banky'              => 'nazev_banky',
        'KS'                       => 'ks',
        'VS'                       => 'vs',
        'SS'                       => 'ss',
        'Uživatelská identifikace' => 'identifikace',
        'Poznámka'                 => 'identifikace',
        'Komentář'                 => 'identifikace',
        'Zpráva pro příjemce'      => 'zprava',
        'Typ'                      => 'typ',
        'Provedl'                  => 'provedl',
        'Upřesnění'                => 'upresneni',
        'Identifikace2'            => 'identifikace2',
        'BIC'                      => 'bic',
        'ID pokynu'                => 'id_pokynu',
    ];

    /**
     * Parse FIO CSV content.
     *
     * @param  string  $content  Raw UTF-8 CSV content
     * @return array{header: array, rows: array, errors: array}
     * @throws \RuntimeException on parse failure
     */
    public function parse(string $content): array
    {
        $lines = preg_split('/\r?\n/', $content);
        $errors = [];

        $headerKeys = [
            'accountId'      => 'accountId',
            'bankId'         => 'bankId',
            'currency'       => 'currency',
            'iban'           => 'iban',
            'bic'            => 'bic',
            'openingBalance' => 'openingBalance',
            'closingBalance' => 'closingBalance',
            'dateStart'      => 'dateStart',
            'dateEnd'        => 'dateEnd',
            'idFrom'         => 'idFrom',
            'idTo'           => 'idTo',
        ];

        // Najdi řádek se záhlavím sloupců podle obsahu (ne podle blank separátoru).
        // FIO někdy vrací CSV s prázdným řádkem mezi metadaty a sloupci (fetchPeriod),
        // jindy bez něj (fetchLast po setLastId) — viz reálné odpovědi z produkce.
        // Sloupce v každém případě začínají na "ID pohybu" (nebo "ID operace").
        $columnHeaderIdx = null;
        $totalLines      = count($lines);
        for ($i = 0; $i < $totalLines; $i++) {
            $first = strtolower(trim(explode(';', $lines[$i], 2)[0] ?? ''));
            if ($first === 'id pohybu' || $first === 'id operace') {
                $columnHeaderIdx = $i;
                break;
            }
        }

        // --- Parse header key-value pairs (řádky 0..columnHeaderIdx-1, nebo všechny pokud sloupce chybí) ---
        $header = [];
        $metadataEnd = $columnHeaderIdx ?? $totalLines;
        for ($lineIdx = 0; $lineIdx < $metadataEnd; $lineIdx++) {
            $line = $lines[$lineIdx];
            if (trim($line) === '') continue;

            $parts = str_getcsv($line, ';', '"', '');
            if (count($parts) >= 2) {
                $key   = trim($parts[0]);
                $value = trim($parts[1]);

                foreach ($headerKeys as $csvKey => $mapKey) {
                    if (strcasecmp($key, $csvKey) === 0) {
                        $header[$mapKey] = $value;
                        break;
                    }
                }
            }
        }

        // Parse numeric header values
        if (isset($header['openingBalance'])) {
            $header['openingBalance'] = $this->parseAmount($header['openingBalance']);
        }
        if (isset($header['closingBalance'])) {
            $header['closingBalance'] = $this->parseAmount($header['closingBalance']);
        }
        if (isset($header['dateStart'])) {
            $header['dateStart'] = $this->parseDate($header['dateStart']) ?? $header['dateStart'];
        }
        if (isset($header['dateEnd'])) {
            $header['dateEnd'] = $this->parseDate($header['dateEnd']) ?? $header['dateEnd'];
        }

        // Když FIO API nemá žádné nové pohyby od posledního bookmarku (setLastId),
        // vrátí CSV jen s metadata hlavičkou bez sloupců — legitimní stav, ne chyba.
        if ($columnHeaderIdx === null) {
            return ['header' => $header, 'rows' => [], 'errors' => $errors];
        }

        $columnLine = $lines[$columnHeaderIdx];
        $lineIdx    = $columnHeaderIdx + 1;

        $rawColumns = str_getcsv($columnLine, ';', '"', '');
        $columnMap  = []; // position => internal key

        $usedKeys = []; // internal key => first position found (first-match-wins for aliases)
        foreach ($rawColumns as $pos => $rawCol) {
            $col = trim($rawCol);
            if (isset(self::COLUMN_ALIASES[$col])) {
                $internalKey = self::COLUMN_ALIASES[$col];
                if (!isset($usedKeys[$internalKey])) {
                    $columnMap[$pos]         = $internalKey;
                    $usedKeys[$internalKey]  = $pos;
                }
            }
        }

        // --- Parse data rows ---
        $rows   = [];
        $sumCastka = 0.0;

        while ($lineIdx < count($lines)) {
            $line = trim($lines[$lineIdx]);
            $lineIdx++;

            if ($line === '') {
                continue;
            }

            $parts = str_getcsv($line, ';', '"', '');
            $row   = [];

            foreach ($columnMap as $pos => $key) {
                $row[$key] = isset($parts[$pos]) ? trim($parts[$pos]) : '';
            }

            // Skip empty rows (no transaction id)
            if (empty($row['id_pohybu'])) {
                continue;
            }

            // Parse and normalise values
            if (isset($row['castka'])) {
                $row['castka'] = $this->parseAmount($row['castka']);
                $sumCastka    += $row['castka'];
            }

            if (isset($row['datum'])) {
                $row['datum'] = $this->parseDate($row['datum']) ?? $row['datum'];
            }

            if (isset($row['vs'])) {
                $row['vs'] = ltrim($row['vs'], '0') ?: '0';
                if ($row['vs'] === '0') $row['vs'] = '';
            }

            if (isset($row['ks'])) {
                $row['ks'] = $row['ks'] !== '' ? (int) $row['ks'] : null;
            }

            if (isset($row['ss'])) {
                $row['ss'] = $row['ss'] !== '' ? (int) $row['ss'] : null;
            }

            $rows[] = $row;
        }

        // --- Integrity check ---
        if (isset($header['openingBalance'], $header['closingBalance'])) {
            $expected = $header['closingBalance'] - $header['openingBalance'];
            if (abs($expected - $sumCastka) > 0.0001) {
                $errors[] = sprintf(
                    'Kontrolní součet neodpovídá: očekávaný rozdíl %.2f Kč, součet řádků %.2f Kč.',
                    $expected,
                    $sumCastka
                );
            }
        }

        return compact('header', 'rows', 'errors');
    }

    private function parseAmount(string $raw): float
    {
        $clean = preg_replace('/\s+/u', '', $raw);
        $clean = str_replace(',', '.', $clean);
        return (float) $clean;
    }

    private function parseDate(string $raw): ?string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($raw), $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }
}
