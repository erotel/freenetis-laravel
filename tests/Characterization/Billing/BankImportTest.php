<?php

namespace Tests\Characterization\Billing;

use App\Domains\Acl\AclService;
use App\Domains\Billing\Bank\FioApiService;
use App\Http\Controllers\ImportController;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\DatabaseTestCase;

/**
 * Charakterizace importu bankovních výpisů FIO (ImportController::persistParsed
 * a cesty CSV / API / plánovač) před přesunem do domény Billing:
 *
 *   příchozí  684000 → banka (+ banka → kredit člena „Přiřazení platby" při
 *             spárování VS); vklad pokladnou z 211000; úrok 644000 → banka
 *   odchozí   banka → dodavatelé 321000, member_id=1, párování „OP #id"
 *   nespárováno: neznámý VS, VS „+U", čekatel 17/18, platba na špatný účet
 *   po importu: potvrzení / faktura + dohnání blokovaných poplatků; audit dávky
 *
 * Spárovaný vklad pokladnou bez účtu pokladny (211000) nesmí přepsat
 * bank_transfer_id u potvrzení předchozí platby (do 2026-10-16 přepisoval).
 */
class BankImportTest extends DatabaseTestCase
{
    private int $adminId;
    private int $bankAccountId;
    private int $bankLedgerId;
    private int $memberId;
    private int $creditId;
    private string $vs;
    private int $code;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(true)->byDefault();
        $acl->shouldReceive('flushUserCache')->andReturnNull();

        $this->bankAccountId = (int) DB::table('bank_accounts')->insertGetId(['name' => 'Char Banka', 'member_id' => 1, 'account_nr' => '2525' . random_int(100000, 999999), 'bank_nr' => '2010', 'payment_purpose' => 0]);
        $this->bankLedgerId  = (int) DB::table('accounts')->insertGetId(['member_id' => 1, 'account_attribute_id' => 221000, 'balance' => 0, 'name' => 'char banka']);
        DB::table('accounts_bank_accounts')->insert(['account_id' => $this->bankLedgerId, 'bank_account_id' => $this->bankAccountId]);
        $interest = (int) DB::table('accounts')->insertGetId(['member_id' => 1, 'account_attribute_id' => 644000, 'balance' => 0, 'name' => 'char úroky']);
        DB::table('accounts_bank_accounts')->insert(['account_id' => $interest, 'bank_account_id' => $this->bankAccountId]);

        foreach ([2, 90, 17, 18] as $t) {
            Setting::set('bank_account_member_type_' . $t, 0);
        }
        DB::table('messages')->where('id', 9)->update(['email_text' => 'PID={payment_id};AMT={payment_amount}']);

        [$this->memberId, $this->creditId, $this->vs] = $this->member(2);
        $this->code = random_int(70000000000, 79000000000);
    }

    /** @return array{0:int, 1:int, 2:string} [member, kreditní účet, VS] */
    private function member(int $type, float $balance = 0, array $m = []): array
    {
        $apId = (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id');
        $id = (int) DB::table('members')->insertGetId($m + ['name' => 'Char Platce', 'type' => $type, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $apId, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
        $acc = (int) DB::table('accounts')->insertGetId(['member_id' => $id, 'account_attribute_id' => 221100, 'balance' => $balance, 'name' => 'c']);
        do {   // '9' . id občas kolidoval s reálným VS v kopii dat → platba jinému členovi
            $vs = (string) random_int(90000000, 99999999);
        } while (DB::table('variable_symbols')->where('variable_symbol', $vs)->orWhere('variable_symbol', $vs . '+U')->exists());
        DB::table('variable_symbols')->insert(['account_id' => $acc, 'variable_symbol' => $vs]);
        $uid = (int) DB::table('users')->insertGetId(['member_id' => $id, 'login' => 'charbank_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'P', 'comment' => '']);
        $cid = (int) DB::table('contacts')->insertGetId(['type' => 20, 'value' => "charbank{$id}@example.invalid"]);
        DB::table('users_contacts')->insert(['user_id' => $uid, 'contact_id' => $cid]);
        return [$id, $acc, $vs];
    }

    /**
     * Výpis ve formátu FIO API (hlavička sloupců bez uvozovek — parser ji hledá
     * podle textu „ID pohybu"); konečný zůstatek = počáteční + součet (kontrolní součet).
     * Řádek: castka, vs, typ, zprava, ident, protiucet, kod, code.
     */
    private function csv(array $rows): string
    {
        $lines = [];
        $sum   = 0.0;
        foreach ($rows as $r) {
            $r += ['vs' => '', 'typ' => 'Příjem převodem uvnitř banky', 'zprava' => 'zprava', 'ident' => '', 'protiucet' => '123456', 'kod' => '0800', 'code' => ++$this->code];
            $sum += $r['castka'];
            $lines[] = sprintf('"%d";"02.10.2026";"%s";"CZK";"%s";"Plátce X";"%s";"0308";"%s";"7";"%s";"%s";"%s"',
                $r['code'], number_format($r['castka'], 2, ',', ''), $r['protiucet'], $r['kod'], $r['vs'], $r['ident'], $r['zprava'], $r['typ']);
        }
        $amount = fn (float $v) => '"' . number_format($v, 2, ',', ' ') . '"';
        return "accountId;2525\nbankId;2010\ncurrency;CZK\nopeningBalance;" . $amount(1000) . "\nclosingBalance;" . $amount(1000 + $sum)
            . "\ndateStart;01.10.2026\ndateEnd;02.10.2026\n\n"
            . "ID pohybu;Datum;Objem;Měna;Protiúčet;Název protiúčtu;Kód banky;KS;VS;SS;Uživatelská identifikace;Zpráva pro příjemce;Typ\n"
            . implode("\n", $lines) . "\n";
    }

    private function upload(array $rows)
    {
        return $this->actingAs(User::find($this->adminId))->from(route('import.upload_bank_file', $this->bankAccountId))
            ->post(route('import.bank_file', $this->bankAccountId), ['csv_file' => UploadedFile::fake()->createWithContent('vypis.csv', $this->csv($rows))]);
    }

    private function bt(int $code): object
    {
        return DB::table('bank_transfers')->where('transaction_code', $code)->first();
    }

    private function balance(int $acc): float
    {
        return (float) DB::table('accounts')->where('id', $acc)->value('balance');
    }

    // ── příchozí ─────────────────────────────────────────────────────────────

    public function test_prichozi_sparovana_platba_ucetnictvi_potvrzeni_audit(): void
    {
        $memberFees = (int) DB::table('accounts')->where('account_attribute_id', 684000)->value('id');
        $mfBefore   = $this->balance($memberFees);
        $code = $this->code + 1;

        $this->upload([['castka' => 500.5, 'vs' => $this->vs, 'zprava' => 'Platba za internet', 'ident' => 'Novák']])
            ->assertRedirect(route('bank_accounts.show', $this->bankAccountId))
            ->assertSessionHas('success', 'Import dokončen: importováno 1 převodů, přeskočeno 0 duplicit.');

        $st = DB::table('bank_statements')->where('bank_account_id', $this->bankAccountId)->first();
        $this->assertSame(['FIO CSV importer', '2026-10-01', '2026-10-02', 1000.0, 1500.5, $this->adminId],
            [$st->type, substr((string) $st->from, 0, 10), substr((string) $st->to, 0, 10), (float) $st->opening_balance, (float) $st->closing_balance, (int) $st->user_id]);

        $bt = $this->bt($code);
        $cp = DB::table('bank_accounts')->where('id', $bt->origin_id)->first();
        $this->assertSame([(int) $this->vs, '308', '7', 'Novák', $this->bankAccountId, '123456', '0800', 'Plátce X'],
            [(int) $bt->variable_symbol, (string) $bt->constant_symbol, (string) $bt->specific_symbol, $bt->comment, (int) $bt->destination_id, $cp->account_nr, $cp->bank_nr, $cp->name]);

        $primary = DB::table('transfers')->where('id', $bt->transfer_id)->first();
        $this->assertSame([$memberFees, $this->bankLedgerId, $this->memberId, $this->adminId, null, 'Platba za internet', 500.5, '2026-10-02 00:00:00'],
            [(int) $primary->origin_id, (int) $primary->destination_id, (int) $primary->member_id, (int) $primary->user_id, $primary->type, $primary->text, (float) $primary->amount, $primary->datetime]);
        $assign = DB::table('transfers')->where('previous_transfer_id', $primary->id)->first();
        $this->assertSame([$this->bankLedgerId, $this->creditId, $this->memberId, 'Přiřazení platby', 500.5],
            [(int) $assign->origin_id, (int) $assign->destination_id, (int) $assign->member_id, $assign->text, (float) $assign->amount]);
        $this->assertEqualsWithDelta($mfBefore - 500.5, $this->balance($memberFees), 0.001);
        $this->assertEqualsWithDelta(0, $this->balance($this->bankLedgerId), 0.001, 'banka +500,5 −500,5');
        $this->assertEqualsWithDelta(500.5, $this->balance($this->creditId), 0.001);

        $mail = DB::table('email_queues')->where('to', "charbank{$this->memberId}@example.invalid")->first();
        $this->assertSame("PID={$bt->id};AMT=500,50", $mail->body);

        $audit = DB::table('audit_logs')->where('auditable_type', 'bank_transfers')->where('auditable_id', $this->bankAccountId)->where('action', 'created')->first();
        $this->assertSame(['bank_account_id' => $this->bankAccountId, 'imported' => 1, 'skipped' => 0, 'source' => 'FIO CSV importer'], json_decode($audit->new_values, true));

        // stejný výpis znovu → duplicity
        $this->code = $code - 1;
        $this->upload([['castka' => 500.5, 'vs' => $this->vs]])->assertSessionHas('success', 'Import dokončen: importováno 0 převodů, přeskočeno 1 duplicit.');
    }

    public function test_platba_dohoni_blokovany_poplatek_a_odblokuje(): void
    {
        Setting::set('default_fee_member_type_2', (int) DB::table('fees')->insertGetId(['readonly' => 0, 'fee' => 300, 'from' => '2000-01-01', 'to' => '9999-12-31', 'type_id' => 35, 'name' => 'c', 'special_type_id' => null]));
        [$m, $credit, $vs] = $this->member(2, 0, ['payment_blocked' => 1, 'payment_blocked_since' => now()->toDateString()]);
        $this->upload([['castka' => 400, 'vs' => $vs]]);
        $this->assertSame(0, (int) DB::table('members')->where('id', $m)->value('payment_blocked'));
        $this->assertEqualsWithDelta(300, (float) DB::table('transfers')->where('origin_id', $credit)->where('type', 1)->sum('amount'), 0.001);
    }

    public function test_nesparovane_platby(): void
    {
        [$pending, , $pendingVs] = $this->member(18);
        [$left, $leftCredit, $leftVs] = $this->member(16);
        DB::table('variable_symbols')->where('variable_symbol', $leftVs)->update(['variable_symbol' => $leftVs . '+U']);
        [$wrong, , $wrongVs] = $this->member(90, 0, ['name' => 'Char Spatny']);
        Setting::set('bank_account_member_type_90', 999999);

        $c = $this->code;
        $this->upload([
            ['castka' => 10, 'vs' => '424242424', 'ident' => '', 'zprava' => ''],
            ['castka' => 11, 'vs' => '424242425', 'ident' => 'má komentář'],
            ['castka' => 12, 'vs' => $leftVs],
            ['castka' => 13, 'vs' => $pendingVs],
            ['castka' => 14, 'vs' => $wrongVs],
            ['castka' => 15, 'vs' => '', 'protiucet' => '', 'kod' => ''],
        ])->assertSessionHas('success', 'Import dokončen: importováno 6 převodů, přeskočeno 0 duplicit.');

        $this->assertSame('Neidentifikovaný převod: VS=424242424', $this->bt($c + 1)->comment);
        $this->assertSame('má komentář', $this->bt($c + 2)->comment);
        $this->assertSame('zprava', $this->bt($c + 3)->comment, 'VS s +U se nespáruje (string match)');
        $this->assertEqualsWithDelta(0, $this->balance($leftCredit), 0.001);
        $this->assertSame('Čekající člen/zákazník — smlouva/přihláška nepodepsána, platba nespárována.', $this->bt($c + 4)->comment);
        $this->assertStringStartsWith("Platba patří Char Spatny (member_id={$wrong}, typ=90), ale přišla na špatný účet (", $this->bt($c + 5)->comment);
        $this->assertStringEndsWith('Očekáván účet id=999999.', $this->bt($c + 5)->comment);
        $this->assertNull($this->bt($c + 6)->origin_id, 'bez protiúčtu');
        foreach (range(1, 6) as $i) {
            $this->assertNull(DB::table('transfers')->where('id', $this->bt($c + $i)->transfer_id)->value('member_id'), "řádek {$i} bez člena");
            $this->assertFalse(DB::table('transfers')->where('previous_transfer_id', $this->bt($c + $i)->transfer_id)->exists());
        }
    }

    public function test_vklad_pokladnou_a_urok(): void
    {
        $cash = (int) DB::table('accounts')->where('account_attribute_id', 211000)->value('id');
        $interest = (int) DB::table('accounts_bank_accounts as aba')->join('accounts as a', 'a.id', '=', 'aba.account_id')
            ->where('aba.bank_account_id', $this->bankAccountId)->where('a.account_attribute_id', 644000)->value('a.id');
        $c = $this->code;
        $this->upload([
            ['castka' => 200, 'vs' => $this->vs, 'typ' => 'Vklad pokladnou'],
            ['castka' => 3.5, 'typ' => 'Připsaný úrok'],
        ]);
        $t1 = DB::table('transfers')->where('id', $this->bt($c + 1)->transfer_id)->first();
        $this->assertSame([$cash, $this->bankLedgerId, $this->memberId], [(int) $t1->origin_id, (int) $t1->destination_id, (int) $t1->member_id]);
        $t2 = DB::table('transfers')->where('id', $this->bt($c + 2)->transfer_id)->first();
        $this->assertSame([$interest, $this->bankLedgerId, 'Připsaný úrok', null], [(int) $t2->origin_id, (int) $t2->destination_id, $t2->text, $t2->member_id]);
        $this->assertSame([null, $this->bankAccountId], [$this->bt($c + 2)->origin_id, (int) $this->bt($c + 2)->destination_id]);
    }

    public function test_vklad_pokladnou_bez_uctu_pokladny_neprepise_id_predchozi_platby(): void
    {
        DB::table('accounts')->where('account_attribute_id', 211000)->update(['account_attribute_id' => 221000]);  // „bez účtu pokladny" (rollback testu)
        [$b, $bCredit, $bVs] = $this->member(2);
        $c = $this->code;
        $this->upload([
            ['castka' => 100, 'vs' => $this->vs],
            ['castka' => 50, 'vs' => $bVs, 'typ' => 'Vklad pokladnou'],
        ]);
        $this->assertNull($this->bt($c + 2)->transfer_id, 'vklad bez účtu pokladny: bez převodu');
        $this->assertEqualsWithDelta(0, $this->balance($bCredit), 0.001);
        $mail = DB::table('email_queues')->where('to', "charbank{$this->memberId}@example.invalid")->first();
        $this->assertSame('PID=' . $this->bt($c + 1)->id . ';AMT=100,00', $mail->body, 'potvrzení platby A nese id SVÉHO převodu');
    }

    // ── odchozí ──────────────────────────────────────────────────────────────

    public function test_odchozi_dodavatele_a_parovani_op(): void
    {
        $suppliers = (int) DB::table('accounts')->where('account_attribute_id', 321000)->value('id');
        $refundTransfer = (int) DB::table('transfers')->insertGetId(['origin_id' => null, 'destination_id' => $this->bankLedgerId, 'type' => 0, 'amount' => 99,
            'datetime' => now(), 'creation_datetime' => now(), 'text' => 'neidentifikovaná', 'member_id' => null]);
        DB::table('bank_transfers')->insert(['transfer_id' => $refundTransfer, 'comment' => 'puvodni']);
        $op = (int) DB::table('outgoing_payments')->insertGetId(['bank_account_id' => $this->bankAccountId, 'transfer_id' => $refundTransfer, 'target_account' => '1/0100',
            'amount' => 99, 'message' => 'm', 'status' => 'exported', 'created_by' => $this->adminId, 'created_at' => now(), 'updated_at' => now()]);
        $opWrongAmount = (int) DB::table('outgoing_payments')->insertGetId(['bank_account_id' => $this->bankAccountId, 'target_account' => '1/0100',
            'amount' => 50, 'message' => 'm', 'status' => 'approved', 'created_by' => $this->adminId, 'created_at' => now(), 'updated_at' => now()]);

        $c = $this->code;
        $this->upload([
            ['castka' => -99, 'ident' => "Vratka OP #{$op}"],
            ['castka' => -40, 'ident' => "op#{$opWrongAmount}"],
        ]);

        $bt = $this->bt($c + 1);
        $t = DB::table('transfers')->where('id', $bt->transfer_id)->first();
        $this->assertSame([$this->bankLedgerId, $suppliers, 1, 99.0], [(int) $t->origin_id, (int) $t->destination_id, (int) $t->member_id, (float) $t->amount]);
        $this->assertSame([$this->bankAccountId, null], [(int) $bt->origin_id, $bt->destination_id]);

        $p = DB::table('outgoing_payments')->where('id', $op)->first();
        $this->assertSame(['paid', (int) $bt->id, 'op_id'], [$p->status, (int) $p->paid_transfer_id, $p->match_method]);
        $this->assertSame('puvodni | Vráceno – platba potvrzena', DB::table('bank_transfers')->where('transfer_id', $refundTransfer)->value('comment'));
        $this->assertSame(1, (int) DB::table('transfers')->where('id', $refundTransfer)->value('member_id'));
        $this->assertSame('approved', DB::table('outgoing_payments')->where('id', $opWrongAmount)->value('status'), 'jiná částka se nespáruje');
    }

    // ── chyby a ostatní cesty ────────────────────────────────────────────────

    /** Souběžný import téhož účtu (zámek drží jiný) nic nezapíše — jinak by se platby připsaly dvakrát. */
    public function test_soubezny_import_uctu(): void
    {
        $action = app(\App\Domains\Billing\Actions\ImportBankStatementAction::class);
        $action->lockWaitSeconds = 0;
        $this->app->instance(\App\Domains\Billing\Actions\ImportBankStatementAction::class, $action);
        $held = \Illuminate\Support\Facades\Cache::lock('bank-import:' . $this->bankAccountId, 60);
        $this->assertTrue($held->get());
        $before = DB::table('bank_statements')->where('bank_account_id', $this->bankAccountId)->count();

        $this->upload([['castka' => 10]])->assertSessionHas('error', 'Na tomto účtu právě běží jiný import výpisu — zkuste to za chvíli znovu.');
        $this->assertSame($before, DB::table('bank_statements')->where('bank_account_id', $this->bankAccountId)->count());
        $held->release();
        $this->upload([['castka' => 10]])->assertSessionHas('success');
    }

    public function test_chyby_a_prava(): void
    {
        $as = $this->actingAs(User::find($this->adminId))->from(route('import.upload_bank_file', $this->bankAccountId));
        $as->post(route('import.bank_file', $this->bankAccountId), ['csv_file' => UploadedFile::fake()->createWithContent('v.csv', "accountId;1\n")])
            ->assertSessionHas('error', 'CSV neobsahuje žádné záznamy.');
        $as->post(route('import.bank_file', $this->bankAccountId), [])->assertSessionHasErrors('csv_file');
        $as->post(route('import.fio_last', $this->bankAccountId))->assertSessionHasErrors(['token' => 'Není nastaven FIO API token pro tento účet. Nastavte ho v editaci bankovního účtu.']);

        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturn(false);
        $acl->shouldReceive('flushUserCache')->andReturnNull();
        $this->upload([['castka' => 1]])->assertForbidden();
        $this->actingAs(User::find($this->adminId))->get(route('import.upload_bank_file', $this->bankAccountId))->assertForbidden();
    }

    public function test_fio_api_a_planovac_zalozka_posledniho_kodu(): void
    {
        Setting::set('fio_api_token_bank_account_' . $this->bankAccountId, 'tok-' . uniqid());
        $this->upload([['castka' => 10]]);
        $last = $this->code;
        $csv = $this->csv([['castka' => 20, 'vs' => $this->vs]]);

        $fio = $this->mock(FioApiService::class);
        // záložka = nejvyšší kód pohybu účtu: před API importem $last, v plánovači už i nový řádek
        $fio->shouldReceive('setLastId')->once()->withArgs(fn ($t, $id) => $id === $last)->ordered();
        $fio->shouldReceive('setLastId')->once()->withArgs(fn ($t, $id) => $id === $last + 1)->ordered();
        $fio->shouldReceive('fetchLast')->once()->andReturn($csv);
        $fio->shouldReceive('fetchLast')->once()->andReturn("accountId;1\n");

        $this->actingAs(User::find($this->adminId))->post(route('import.fio_last', $this->bankAccountId))
            ->assertRedirect(route('bank_transfers.by_account', $this->bankAccountId))
            ->assertSessionHas('success', 'FIO API: importováno 1 převodů, přeskočeno 0 duplicit.');
        $this->assertSame('FIO API importer', DB::table('bank_statements')->where('bank_account_id', $this->bankAccountId)->orderByDesc('id')->value('type'));

        $this->assertSame(['imported' => 0, 'skipped' => 0], app(ImportController::class)->runFioImportForScheduler($this->bankAccountId));
    }
}
