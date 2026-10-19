<?php

namespace Tests\Characterization\Billing;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Characterization\Catalog\CatalogTestCase;

/**
 * Charakterizace přehledu faktur (InvoiceController) před přesunem do domény
 * Billing: seznam (filtry člen / typ / Pohoda, hledání), detail s historií
 * změn, faktury člena a PDF (vlastní člen bez práva), ACL.
 */
class InvoicesControllerTest extends CatalogTestCase
{
    private function member(): int
    {
        return (int) DB::table('members')->insertGetId(['name' => "Char {$this->tag}", 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
    }

    private function invoice(int $member, array $over = []): int
    {
        return (int) DB::table('invoices')->insertGetId($over + ['member_id' => $member, 'invoice_nr' => random_int(900000000, 999999999), 'invoice_type' => 1,
            'var_sym' => 1, 'date_inv' => '2099-01-01', 'date_due' => '2099-01-15', 'date_vat' => '2099-01-01', 'vat' => 0, 'currency' => 'CZK',
            'pohoda_status' => 'new', 'partner_name' => "Partner {$this->tag}", 'note' => '']);
    }

    private function ids($res, string $key = 'invoices'): array
    {
        return collect($res->viewData($key)->items())->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    public function test_seznam_a_detail(): void
    {
        $m = $this->member();
        $a = $this->invoice($m, ['date_inv' => '2099-01-01']);
        $b = $this->invoice($m, ['date_inv' => '2099-02-01', 'invoice_type' => 0, 'pohoda_status' => 'exported']);

        $this->assertSame([$b, $a], $this->ids($this->as()->get(route('invoices.index', ['q' => $this->tag]))->assertOk()));
        $this->assertSame([$a], $this->ids($this->as()->get(route('invoices.index', ['member_id' => $m, 'type' => '1']))));
        $this->assertSame([$b], $this->ids($this->as()->get(route('invoices.index', ['member_id' => $m, 'pohoda' => 'exported']))));
        $nr = (int) DB::table('invoices')->where('id', $a)->value('invoice_nr');
        $this->assertSame([$a], $this->ids($this->as()->get(route('invoices.index', ['q' => (string) $nr]))));

        DB::table('audit_logs')->insert(['auditable_type' => 'invoices', 'auditable_id' => $a, 'action' => 'updated', 'user_id' => $this->adminId, 'occurred_at' => now()]);
        $show = $this->as()->get(route('invoices.show', $a))->assertOk()->assertViewIs('invoices.show');
        $this->assertSame(['updated'], $show->viewData('auditHistory')->pluck('action')->all());
        $this->as()->get(route('invoices.show', 999999999))->assertNotFound();

        $by = $this->as()->get(route('invoices.by_member', $m))->assertOk();
        $this->assertSame([$b, $a], $this->ids($by));
        $this->assertSame($m, (int) $by->viewData('member')->id);
        $this->as()->get(route('invoices.by_member', 999999999))->assertNotFound();

        $this->as()->from(route('invoices.show', $a))->get(route('invoices.pdf', $a))->assertRedirect(route('invoices.show', $a))->assertSessionHas('error', 'PDF soubor není k dispozici.');
        $pdf = tempnam(sys_get_temp_dir(), 'inv');
        file_put_contents($pdf, '%PDF-1.4 char');
        DB::table('invoices')->where('id', $a)->update(['pdf_filename' => $pdf]);
        $res = $this->as()->get(route('invoices.pdf', $a))->assertOk();
        $this->assertStringContainsString("faktura_{$nr}.pdf", $res->headers->get('Content-Disposition'));
        @unlink($pdf);
    }

    public function test_prava_a_vlastni(): void
    {
        $m = $this->member();
        $own = $this->invoice($m);
        $foreign = $this->invoice($this->member());
        $u = (int) DB::table('users')->insertGetId(['member_id' => $m, 'login' => 'charinv_' . uniqid(), 'password' => Hash::make('x'), 'type' => 1,
            'application_password' => 'x', 'settings' => '', 'name' => 'C', 'surname' => 'I', 'comment' => '']);

        // CatalogTestCase: ostatní uživatelé nemají žádné právo
        $this->as($u)->get(route('invoices.by_member', $m))->assertOk();
        $this->as($u)->from(route('invoices.by_member', $m))->get(route('invoices.pdf', $own))->assertRedirect();
        $this->as($u)->get(route('invoices.pdf', $foreign))->assertForbidden();
        $this->as($u)->get(route('invoices.show', $own))->assertForbidden();
        $this->as($u)->get(route('invoices.index'))->assertForbidden();
        $this->as($u)->get(route('invoices.by_member', (int) DB::table('invoices')->where('id', $foreign)->value('member_id')))->assertForbidden();
    }
}
