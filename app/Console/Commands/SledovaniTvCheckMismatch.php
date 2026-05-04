<?php

namespace App\Console\Commands;

use App\Services\SledovaniTvService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot analytický skript:
 * Zákazníci (type=2) s aktivní TV jsou pravděpodobně migrační artefakt — před
 * převodem na zákaznický status to byli plnohodnotní členové (type=90), ale
 * partnerid v SledovaniTV zůstal na starém ID a teď ukazuje na zákaznickou
 * "skořápku".
 *
 * Pro každého takového zákazníka najde kandidáty (type=90) podle:
 *   - emailu (z contacts přes users_contacts)
 *   - jména (members.name, users.name+surname)
 *   - adresy (stejná obec + stejné číslo popisné)
 *
 * Výstup je text soubor v storage/app/.
 */
class SledovaniTvCheckMismatch extends Command
{
    protected $signature = 'sledovanitv:check-mismatch
        {--out= : Cesta k výstupnímu souboru, default storage/app/sledovanitv-mismatch.txt}
        {--apply : Skutečně volat API a měnit partnerid u kandidátů se score >= min-score}
        {--min-score=5 : Práh pro auto-apply (5 = email match)}
        {--interactive : Před každým API voláním zeptat se y/n}';
    protected $description = 'Najde zákazníky (type=2) s aktivní TV a jejich kandidáty z členů (type=90)';

    public function handle(SledovaniTvService $svc): int
    {
        $outPath     = $this->option('out') ?: storage_path('app/sledovanitv-mismatch.txt');
        $apply       = (bool) $this->option('apply');
        $minScore    = (int)  $this->option('min-score');
        $interactive = (bool) $this->option('interactive');

        $this->info('Stahuji JSON z API...');
        try {
            $users = $svc->fetchUsers();
        } catch (\Throwable $e) {
            $this->error('API selhalo: ' . $e->getMessage());
            return 1;
        }
        $this->info('OK, ' . count($users) . ' záznamů.');

        // Index JSON podle partnerid (= members.id)
        $jsonByPartner = [];
        foreach ($users as $u) {
            $pid = $u['partnerid'] ?? null;
            if ($pid === null || $pid === '') continue;
            $jsonByPartner[(int) $pid] = $u;
        }

        // Email enum type ID
        $emailTypeId = (int) DB::table('enum_types')->where('value', 'E-mail')->value('id');
        if (!$emailTypeId) {
            $this->warn('Nenalezen enum_type pro "E-mail" — emaily se nebudou matchovat.');
        }

        // Zákazníci s aktivní TV
        $customers = DB::select("
            SELECT m.id, m.name, m.tv_valid_until,
                   ap.street_number, s.street AS street_name, t.town AS town_name,
                   u.id AS user_id, u.name AS user_name, u.middle_name AS user_middle, u.surname AS user_surname
            FROM members m
            LEFT JOIN address_points ap ON ap.id = m.address_point_id
            LEFT JOIN streets s ON s.id = ap.street_id
            LEFT JOIN towns   t ON t.id = ap.town_id
            LEFT JOIN users   u ON u.member_id = m.id AND u.id = m.user_id
            WHERE m.type = 2 AND m.tv_active = 1
            ORDER BY m.id
        ");

        $this->info('Zákazníků s aktivní TV: ' . count($customers));

        if (empty($customers)) {
            file_put_contents($outPath, "Nenalezeni žádní zákazníci (type=2) s aktivní TV.\n");
            $this->info("Výstup: $outPath");
            return 0;
        }

        // Pre-fetch všechny type=90 členy do paměti pro matching
        $candidates = DB::select("
            SELECT m.id, m.name AS m_name, m.tv_active, m.tv_valid_until,
                   ap.street_number, s.street AS street_name, t.town AS town_name,
                   ap.town_id, ap.street_id,
                   u.id AS user_id, u.name AS u_name, u.middle_name AS u_middle, u.surname AS u_surname
            FROM members m
            LEFT JOIN address_points ap ON ap.id = m.address_point_id
            LEFT JOIN streets s ON s.id = ap.street_id
            LEFT JOIN towns   t ON t.id = ap.town_id
            LEFT JOIN users   u ON u.member_id = m.id AND u.id = m.user_id
            WHERE m.type = 90
        ");
        $this->info('Členů (type=90) k prohledání: ' . count($candidates));

        // Email lookup pro všechny členy (jeden dotaz)
        $emailsByMember = [];
        if ($emailTypeId) {
            $rows = DB::select("
                SELECT u.member_id, c.value AS email
                FROM users u
                JOIN users_contacts uc ON uc.user_id = u.id
                JOIN contacts c ON c.id = uc.contact_id
                WHERE c.type = ? AND c.value IS NOT NULL AND c.value != ''
            ", [$emailTypeId]);
            foreach ($rows as $r) {
                $emailsByMember[(int) $r->member_id][] = strtolower(trim($r->email));
            }
        }

        // Spustit matching
        $report     = [];
        $applyQueue = []; // [['cust' => …, 'jsonRec' => …, 'best' => …]]
        foreach ($customers as $cust) {
            $jsonRec = $jsonByPartner[(int) $cust->id] ?? null;
            if (!$jsonRec) {
                // member je tv_active=1, ale v JSON není — nemělo by se stát po sync, log a skip
                $report[] = $this->renderCustomer($cust, null, []);
                continue;
            }

            $svcEmail = strtolower(trim($jsonRec['email'] ?? ''));
            $svcLogin = strtolower(trim($jsonRec['login'] ?? ''));
            $svcName  = trim($jsonRec['fullName'] ?? '');

            // Skóre kandidáta
            $scored = [];
            foreach ($candidates as $cand) {
                $score = 0;
                $matched = [];

                // 1. Email
                $candEmails = $emailsByMember[(int) $cand->id] ?? [];
                foreach ($candEmails as $e) {
                    if ($e === '') continue;
                    if ($svcEmail !== '' && $e === $svcEmail) { $score += 5; $matched[] = "email=$e"; break; }
                    if ($svcLogin !== '' && $e === $svcLogin) { $score += 5; $matched[] = "email=$e (login)"; break; }
                }

                // 2. Jméno
                $candFull = trim(implode(' ', array_filter([$cand->u_name, $cand->u_middle, $cand->u_surname])));
                $candMember = trim($cand->m_name ?? '');
                if ($svcName !== '') {
                    if ($candFull !== '' && $this->namesSimilar($svcName, $candFull)) {
                        $score += 3; $matched[] = "jméno=$candFull";
                    } elseif ($candMember !== '' && $this->namesSimilar($svcName, $candMember)) {
                        $score += 2; $matched[] = "název=$candMember";
                    }
                }

                // 3. Adresa (stejné město + stejné číslo popisné)
                if ($cust->street_number && $cust->town_name &&
                    $cand->street_number === $cust->street_number &&
                    $cand->town_name === $cust->town_name) {
                    $score += 2; $matched[] = "adresa";
                }

                if ($score > 0) {
                    $scored[] = [
                        'cand'    => $cand,
                        'score'   => $score,
                        'matched' => $matched,
                        'emails'  => $candEmails,
                    ];
                }
            }

            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $top = array_slice($scored, 0, 5);

            $report[] = $this->renderCustomer($cust, $jsonRec, $top);

            // Pro apply: best kandidát musí mít score >= minScore A nesmí už mít TV
            if (!empty($top)) {
                $best = $top[0];
                if ($best['score'] >= $minScore && !$best['cand']->tv_active) {
                    $applyQueue[] = ['cust' => $cust, 'jsonRec' => $jsonRec, 'best' => $best];
                }
            }
        }

        $header  = "SledovaniTV — kontrola nesouladu (zákazník type=2 s aktivní TV)\n";
        $header .= "Datum: " . date('Y-m-d H:i:s') . "\n";
        $header .= "Zákazníků s aktivní TV: " . count($customers) . "\n";
        $header .= "Kandidátů auto-apply (score >= $minScore, kandidát bez TV): " . count($applyQueue) . "\n";
        $header .= "Mód: " . ($apply ? 'APPLY (volá API!)' : 'dry-run') . "\n";
        $header .= str_repeat('=', 80) . "\n\n";

        file_put_contents($outPath, $header . implode("\n", $report));
        $this->info("Výstup: $outPath");
        $this->info('Záznamů vhodných k auto-apply (score >= ' . $minScore . '): ' . count($applyQueue));

        if (!$apply) {
            $this->info('Dry-run mód. Pro skutečné volání API přidej --apply.');
            return 0;
        }

        // ── APPLY MÓD ─────────────────────────────────────────────────────────
        if (empty($applyQueue)) {
            $this->info('Nic k aplikaci.');
            return 0;
        }

        if (!$interactive) {
            if (!$this->confirm('Skutečně zavolat API pro ' . count($applyQueue) . ' záznamů?', false)) {
                $this->warn('Aborted.');
                return 0;
            }
        }

        $applied = 0; $skipped = 0; $failed = 0;
        $logLines = [];
        foreach ($applyQueue as $item) {
            $custId       = (int) $item['cust']->id;
            $custName     = $item['cust']->name;
            $svcUserId    = (int) $item['jsonRec']['id'];
            $newPartnerId = (int) $item['best']['cand']->id;
            $newName      = $item['best']['cand']->m_name;
            $score        = $item['best']['score'];

            $line = "customer #$custId ($custName) → member #$newPartnerId ($newName) [score=$score, sledovanitv userId=$svcUserId]";

            if ($interactive) {
                if (!$this->confirm("Změnit partnerid na $newPartnerId — $line?", false)) {
                    $this->line("  SKIP: $line");
                    $logLines[] = "[SKIP] $line";
                    $skipped++;
                    continue;
                }
            }

            try {
                $svc->modifyUserPartnerId($svcUserId, $newPartnerId);
                $this->info("  OK: $line");
                $logLines[] = "[OK] $line";
                $applied++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $this->error("  FAIL: $line — $msg");
                $logLines[] = "[FAIL] $line — $msg";
                $failed++;
            }
        }

        $this->info("Hotovo: applied=$applied, skipped=$skipped, failed=$failed");
        $this->info('Spusť `php artisan sledovanitv:sync` pro aktualizaci stavu v DB.');

        // Append apply log do output souboru
        $applyFooter  = "\n" . str_repeat('=', 80) . "\n";
        $applyFooter .= "APPLY výsledek (" . date('Y-m-d H:i:s') . "):\n";
        $applyFooter .= "  applied=$applied, skipped=$skipped, failed=$failed\n\n";
        $applyFooter .= implode("\n", $logLines) . "\n";
        file_put_contents($outPath, $applyFooter, FILE_APPEND);

        return $failed > 0 ? 1 : 0;
    }

    private function renderCustomer($cust, ?array $jsonRec, array $top): string
    {
        $out  = "─── Zákazník id={$cust->id} ─────────────────────────────────\n";
        $out .= "FreenetIS:\n";
        $out .= "  name           : " . ($cust->name ?? '') . "\n";
        $userFull = trim(implode(' ', array_filter([$cust->user_name, $cust->user_middle, $cust->user_surname])));
        $out .= "  uživatel       : " . ($userFull ?: '—') . "\n";
        $out .= "  adresa         : " . trim(($cust->street_name ?? '') . ' ' . ($cust->street_number ?? '')) . ', ' . ($cust->town_name ?? '') . "\n";
        $out .= "  TV platí do    : " . ($cust->tv_valid_until ?? '—') . "\n";

        if ($jsonRec) {
            $out .= "SledovaniTV API:\n";
            $out .= "  fullName       : " . ($jsonRec['fullName'] ?? '') . "\n";
            $out .= "  login          : " . ($jsonRec['login'] ?? '') . "\n";
            $out .= "  email          : " . ($jsonRec['email'] ?? '') . "\n";
            if (!empty($jsonRec['phone'])) $out .= "  phone          : " . $jsonRec['phone'] . "\n";
        } else {
            $out .= "SledovaniTV API: ZÁZNAM NENALEZEN (partnerid neodpovídá)\n";
        }

        if (empty($top)) {
            $out .= "Kandidáti (type=90): žádní\n";
        } else {
            $out .= "Kandidáti (type=90), seřazeno podle skóre:\n";
            foreach ($top as $entry) {
                $c = $entry['cand'];
                $candAddr = trim(($c->street_name ?? '') . ' ' . ($c->street_number ?? '')) . ', ' . ($c->town_name ?? '');
                $candFull = trim(implode(' ', array_filter([$c->u_name, $c->u_middle, $c->u_surname])));
                $emailsCsv = implode(', ', $entry['emails']);
                $out .= sprintf("  [skóre %d] member id=%d  %s  (%s)\n",
                    $entry['score'], $c->id,
                    $c->m_name ?? $candFull,
                    implode(', ', $entry['matched']));
                $out .= sprintf("            uživatel=%s  adresa=%s\n", $candFull ?: '—', $candAddr);
                if ($emailsCsv) $out .= sprintf("            emaily=%s\n", $emailsCsv);
            }
        }
        return $out;
    }

    /**
     * Volné jmenné porovnání: rozloží oba na slova, počítá kolik se jich
     * shoduje (case-insensitive, bez háčků). Match když ≥ 2 slova shodná
     * NEBO 1 dlouhé (>4 znaky) slovo shodné.
     */
    private function namesSimilar(string $a, string $b): bool
    {
        $aw = $this->nameWords($a);
        $bw = $this->nameWords($b);
        if (empty($aw) || empty($bw)) return false;

        $common = array_intersect($aw, $bw);
        if (count($common) >= 2) return true;
        foreach ($common as $w) {
            if (mb_strlen($w) >= 5) return true;
        }
        return false;
    }

    private function nameWords(string $s): array
    {
        $s = mb_strtolower($s);
        // Odstranit diakritiku
        if (function_exists('iconv')) {
            $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        }
        $words = preg_split('/[\s\.,]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        // Odfiltruj titly a krátká slova
        return array_values(array_filter($words, fn($w) => mb_strlen($w) >= 2 && !in_array($w, ['ing', 'mgr', 'bc', 'ph', 'd', 'csc', 'mudr'])));
    }
}
