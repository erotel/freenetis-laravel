<?php

namespace Tests\Characterization\Billing;

use App\Models\Setting;
use App\Services\PohodaExportService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace exportu do Pohody (PohodaExportService) před přesunem do
 * domény Billing — „zlatý" výstup XML faktur (vydané, DPH high/none, firma
 * vs. osoba) a vratek (opravný daňový doklad, záporné částky, adresa s
 * částí obce), výběr do konce měsíce a překlopení na exported.
 * Rok 2099, aby se nepřepsal skutečný soubor exportu; soubory se mažou.
 */
class PohodaExportTest extends DatabaseTestCase
{
    private array $files = [];
    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('ico', '12345678');
        Setting::set('pohoda_application', 'CharApp');
        DB::table('invoices')->where('pohoda_status', 'new')->update(['pohoda_status' => 'exported']);
        DB::table('pohoda_refund_queue')->where('status', 'new')->update(['status' => 'exported']);

        $town   = (int) DB::table('towns')->insertGetId(['town' => 'Prostějov', 'quarter' => 'Krasice', 'zip_code' => '79604']);
        $street = (int) DB::table('streets')->insertGetId(['street' => 'Charová']);
        $ap     = (int) DB::table('address_points')->insertGetId(['town_id' => $town, 'street_id' => $street, 'street_number' => '12', 'country_id' => 1]);
        $this->memberId = (int) DB::table('members')->insertGetId(['name' => 'Char Pohoda s.r.o.', 'type' => 2, 'registration' => 1, 'locked' => 0,
            'address_point_id' => $ap, 'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31',
            'organization_identifier' => '87 654 321', 'vat_organization_identifier' => 'cz 87654321']);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $f) @unlink($f);
        parent::tearDown();
    }

    private function invoice(int $nr, string $date, array $h, array $items): int
    {
        $id = (int) DB::table('invoices')->insertGetId($h + [
            'member_id' => $this->memberId, 'invoice_nr' => $nr, 'invoice_type' => 0, 'var_sym' => 4242, 'date_inv' => $date, 'date_due' => $date,
            'date_vat' => $date, 'vat' => 1, 'currency' => 'CZK', 'note' => 'pozn', 'pohoda_status' => 'new',
            'partner_name' => 'Jan Novák s dlouhým jménem přesahujícím limit', 'partner_street' => 'Hlavní', 'partner_street_number' => '5',
            'partner_town' => 'Brno', 'partner_zip_code' => '60200',
        ]);
        foreach ($items as [$name, $code, $qty, $price, $vat]) {
            DB::table('invoice_items')->insert(['invoice_id' => $id, 'name' => $name, 'code' => $code, 'quantity' => $qty, 'price' => $price, 'vat' => $vat,
                'author_fee' => 0, 'contractual_increase' => 0, 'service' => 1]);
        }
        return $id;
    }

    private function normalize(string $xml): string
    {
        return preg_replace('~id="inv_[0-9_-]+"~', 'id="inv_X"', $xml);
    }

    public function test_export_faktur_xml_a_preklopeni(): void
    {
        $a = $this->invoice(209900001, '2099-01-10', [], [['Platba', 'BANK-2099-01', 1, 82.64, 0.21], ['Zaokrouhlení', 'ROUND', 1, 0.01, 0]]);
        $b = $this->invoice(209900002, '2099-01-31', ['organization_identifier' => '11223344', 'vat_organization_identifier' => 'CZ11223344', 'partner_name' => 'Firma a.s.'],
            [['Služba', 'S1', 2, 100, 21]]);
        $later = $this->invoice(209900003, '2099-02-01', [], [['Pozdě', 'X', 1, 10, 0.21]]);

        $file = app(PohodaExportService::class)->exportInvoices(2099, 1);
        $this->files[] = $file;
        $this->assertSame(storage_path('app/private/pohoda-exports/pohoda_2099_01.xml'), $file);

        $expected = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<dat:dataPack id="inv_X" ico="12345678" application="CharApp" version="2.0" note="Imported from Freenetis" xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd">
 <dat:dataPackItem id="DP209900001" version="2.0">
  <inv:invoice version="2.0">
   <inv:invoiceHeader>
    <inv:invoiceType>issuedInvoice</inv:invoiceType>
    <inv:number>
     <typ:numberRequested>209900001</typ:numberRequested>
    </inv:number>
    <inv:symVar>4242</inv:symVar>
    <inv:date>2099-01-10</inv:date>
    <inv:dateTax>2099-01-10</inv:dateTax>
    <inv:dateDue>2099-01-10</inv:dateDue>
    <inv:partnerIdentity>
     <typ:address>
      <typ:company></typ:company>
      <typ:name>Jan Novák s dlouhým jménem přesa</typ:name>
      <typ:city>Brno</typ:city>
      <typ:street>Hlavní 5</typ:street>
      <typ:zip>60200</typ:zip>
      <typ:country>
       <typ:ids>Czech Republic</typ:ids>
      </typ:country>
      <typ:phone></typ:phone>
      <typ:email></typ:email>
     </typ:address>
    </inv:partnerIdentity>
    <inv:numberOrder></inv:numberOrder>
    <inv:symConst></inv:symConst>
    <inv:note>pozn</inv:note>
   </inv:invoiceHeader>
   <inv:invoiceDetail>
    <inv:invoiceItem>
     <inv:text>Platba</inv:text>
     <inv:quantity>1</inv:quantity>
     <inv:rateVAT>high</inv:rateVAT>
     <inv:homeCurrency>
      <typ:unitPrice>82.64</typ:unitPrice>
      <typ:price>82.64</typ:price>
      <typ:priceVAT>17.35</typ:priceVAT>
      <typ:priceSum>100</typ:priceSum>
     </inv:homeCurrency>
     <inv:code>BANK-2099-01</inv:code>
    </inv:invoiceItem>
    <inv:invoiceItem>
     <inv:text>Zaokrouhlení</inv:text>
     <inv:quantity>1</inv:quantity>
     <inv:rateVAT>none</inv:rateVAT>
     <inv:homeCurrency>
      <typ:unitPrice>0.01</typ:unitPrice>
      <typ:price>0.01</typ:price>
      <typ:priceVAT>0</typ:priceVAT>
      <typ:priceSum>0</typ:priceSum>
     </inv:homeCurrency>
     <inv:code>ROUND</inv:code>
    </inv:invoiceItem>
   </inv:invoiceDetail>
   <inv:invoiceSummary>
    <inv:roundingDocument>math2one</inv:roundingDocument>
    <inv:homeCurrency>
     <typ:priceNone>0</typ:priceNone>
     <typ:priceLow>0</typ:priceLow>
     <typ:priceLowVAT>0</typ:priceLowVAT>
     <typ:priceLowSum>0</typ:priceLowSum>
     <typ:priceHigh>82.64</typ:priceHigh>
     <typ:priceHighVAT>17.35</typ:priceHighVAT>
     <typ:priceHighSum>100</typ:priceHighSum>
     <typ:round>
      <typ:priceRound>0</typ:priceRound>
     </typ:round>
    </inv:homeCurrency>
   </inv:invoiceSummary>
  </inv:invoice>
 </dat:dataPackItem>
 <dat:dataPackItem id="DP209900002" version="2.0">
  <inv:invoice version="2.0">
   <inv:invoiceHeader>
    <inv:invoiceType>issuedInvoice</inv:invoiceType>
    <inv:number>
     <typ:numberRequested>209900002</typ:numberRequested>
    </inv:number>
    <inv:symVar>4242</inv:symVar>
    <inv:date>2099-01-31</inv:date>
    <inv:dateTax>2099-01-31</inv:dateTax>
    <inv:dateDue>2099-01-31</inv:dateDue>
    <inv:partnerIdentity>
     <typ:address>
      <typ:company>Firma a.s.</typ:company>
      <typ:name></typ:name>
      <typ:ico>11223344</typ:ico>
      <typ:dic>CZ11223344</typ:dic>
      <typ:city>Brno</typ:city>
      <typ:street>Hlavní 5</typ:street>
      <typ:zip>60200</typ:zip>
      <typ:country>
       <typ:ids>Czech Republic</typ:ids>
      </typ:country>
      <typ:phone></typ:phone>
      <typ:email></typ:email>
     </typ:address>
    </inv:partnerIdentity>
    <inv:numberOrder></inv:numberOrder>
    <inv:symConst></inv:symConst>
    <inv:note>pozn</inv:note>
   </inv:invoiceHeader>
   <inv:invoiceDetail>
    <inv:invoiceItem>
     <inv:text>Služba</inv:text>
     <inv:quantity>2</inv:quantity>
     <inv:rateVAT>high</inv:rateVAT>
     <inv:homeCurrency>
      <typ:unitPrice>100</typ:unitPrice>
      <typ:price>200</typ:price>
      <typ:priceVAT>42</typ:priceVAT>
      <typ:priceSum>242</typ:priceSum>
     </inv:homeCurrency>
     <inv:code>S1</inv:code>
    </inv:invoiceItem>
   </inv:invoiceDetail>
   <inv:invoiceSummary>
    <inv:roundingDocument>math2one</inv:roundingDocument>
    <inv:homeCurrency>
     <typ:priceNone>0</typ:priceNone>
     <typ:priceLow>0</typ:priceLow>
     <typ:priceLowVAT>0</typ:priceLowVAT>
     <typ:priceLowSum>0</typ:priceLowSum>
     <typ:priceHigh>200</typ:priceHigh>
     <typ:priceHighVAT>42</typ:priceHighVAT>
     <typ:priceHighSum>242</typ:priceHighSum>
     <typ:round>
      <typ:priceRound>0</typ:priceRound>
     </typ:round>
    </inv:homeCurrency>
   </inv:invoiceSummary>
  </inv:invoice>
 </dat:dataPackItem>
</dat:dataPack>

XML;
        $this->assertSame($expected, $this->normalize((string) file_get_contents($file)));

        $this->assertSame(['exported', 'exported', 'new'], DB::table('invoices')->whereIn('id', [$a, $b, $later])->orderBy('id')->pluck('pohoda_status')->all());
        $this->assertNotNull(DB::table('invoices')->where('id', $a)->value('pohoda_exported_at'));
        $this->assertNull(app(PohodaExportService::class)->exportInvoices(2099, 1), 'nic nového');
    }

    public function test_export_vratek_xml_a_preklopeni(): void
    {
        $q = fn (array $r) => (int) DB::table('pohoda_refund_queue')->insertGetId($r + ['member_id' => $this->memberId, 'member_type' => 2, 'doc_number' => '99011',
            'refund_account' => '1/0100', 'amount' => 121, 'currency' => 'CZK', 'reason' => 'termination_refund', 'note' => 'n', 'created_at' => '2099-01-20 10:00:00', 'status' => 'new']);
        $first  = $q([]);
        $zero   = $q(['amount' => 0, 'doc_number' => '99012']);
        $later  = $q(['created_at' => '2099-02-01 00:00:00', 'doc_number' => '99013']);

        $file = app(PohodaExportService::class)->exportRefunds(2099, 1);
        $this->files[] = $file;
        $this->assertMatchesRegularExpression('~/pohoda-exports/pohoda_refunds_2099_01_\d{6}\.xml$~', $file);

        $expected = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd" xmlns:inv="http://www.stormware.cz/schema/version_2/invoice.xsd" xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd" version="2.0" id="FreenetISRefunds" application="FreenetIS" note="Refund export (Dobropisy)" ico="12345678">
  <dat:dataPackItem version="2.0" id="RFQ_{$first}">
    <inv:invoice version="2.0">
      <inv:invoiceHeader>
        <inv:invoiceType>issuedCorrectiveTax</inv:invoiceType>
        <inv:number>
          <typ:numberRequested>99011</typ:numberRequested>
        </inv:number>
        <inv:date>2099-01-20</inv:date>
        <inv:dateTax>2099-01-20</inv:dateTax>
        <inv:dateAccounting>2099-01-20</inv:dateAccounting>
        <inv:symVar>99011</inv:symVar>
        <inv:partnerIdentity>
          <typ:address>
            <typ:company>Char Pohoda s.r.o.</typ:company>
            <typ:ico>87654321</typ:ico>
            <typ:dic>CZ87654321</typ:dic>
            <typ:city>Prostějov - Krasice</typ:city>
            <typ:street>Charová 12</typ:street>
            <typ:zip>79604</typ:zip>
            <typ:country>
              <typ:ids>{$this->country()}</typ:ids>
            </typ:country>
          </typ:address>
        </inv:partnerIdentity>
        <inv:text>Dobropis (vratka)</inv:text>
      </inv:invoiceHeader>
      <inv:invoiceDetail>
        <inv:invoiceItem>
          <inv:text>Vratka přeplatku</inv:text>
          <inv:quantity>1</inv:quantity>
          <inv:rateVAT>high</inv:rateVAT>
          <inv:homeCurrency>
            <typ:unitPrice>-100.00</typ:unitPrice>
          </inv:homeCurrency>
        </inv:invoiceItem>
      </inv:invoiceDetail>
      <inv:invoiceSummary>
        <inv:roundingDocument>none</inv:roundingDocument>
        <inv:roundingVAT>none</inv:roundingVAT>
        <inv:homeCurrency>
          <typ:priceHigh>-100.00</typ:priceHigh>
          <typ:priceHighVAT>-21.00</typ:priceHighVAT>
          <typ:priceHighSum>-121.00</typ:priceHighSum>
        </inv:homeCurrency>
      </inv:invoiceSummary>
    </inv:invoice>
  </dat:dataPackItem>
</dat:dataPack>

XML;
        $this->assertSame($expected, (string) file_get_contents($file), 'nulová vratka se do XML nedostane');
        $this->assertSame(['exported', 'exported', 'new'], DB::table('pohoda_refund_queue')->whereIn('id', [$first, $zero, $later])->orderBy('id')->pluck('status')->all(),
            'nulová se přesto označí exportovaná');
        $this->assertNull(app(PohodaExportService::class)->exportRefunds(2099, 1));

        Setting::set('ico', '');
        $this->expectExceptionMessage('POHODA export: chybí povinné IČO sdružení v config.ico.');
        app(PohodaExportService::class)->buildRefundXml(collect());
    }

    private function country(): string
    {
        return (string) (DB::table('countries')->where('id', 1)->value('country_name') ?: 'Czech Republic');
    }
}
