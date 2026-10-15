<?php

namespace App\Domains\Members\Documents;

use App\Models\Setting;
use App\Models\Member;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * PDF dokumenty člena z jeho údajů: přihláška ('registration'), ukončení
 * členství ('end') a výpověď smlouvy ('contract_end'). Sdílí inline export
 * i odeslání e-mailem.
 */
class RegistrationDocument
{
    public const TYPES = ['registration', 'end', 'contract_end'];

    public const SUBJECTS = [
        'registration' => 'Přihláška za člena',
        'end'          => 'Ukončení členství',
        'contract_end' => 'Výpověď smlouvy',
    ];

    /**
     * @return array{0: string, 1: string} [PDF bytes, název souboru]
     * @throws ModelNotFoundException člen neexistuje / nemá adresu
     */
    public function render(int $id, string $type): array
    {
        $member = DB::table('members as m')
            ->join('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->join('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->where('m.id', $id)
            ->select('m.*', 't.town', 't.zip_code', 's.street', 'ap.street_number')
            ->first();
        if (!$member) {
            throw (new ModelNotFoundException())->setModel(Member::class, [$id]);
        }

        // Sdružení (člen id=1)
        $assoc = DB::table('members as m')
            ->join('address_points as ap', 'ap.id', '=', 'm.address_point_id')
            ->join('towns as t', 't.id', '=', 'ap.town_id')
            ->leftJoin('streets as s', 's.id', '=', 'ap.street_id')
            ->where('m.id', 1)
            ->select('m.*', 't.town', 't.zip_code', 's.street', 'ap.street_number')
            ->first();

        // Hlavní uživatel
        $mainUser = DB::table('users')
            ->where('member_id', $id)
            ->where('type', 1) // MAIN_USER
            ->first();

        // Kontakty (email=20, telefon=21) hlavního uživatele
        $contacts = $mainUser
            ? DB::table('contacts as c')
                ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                ->where('uc.user_id', $mainUser->id)
                ->whereIn('c.type', [20, 21])
                ->select('c.type', 'c.value')
                ->get()
            : collect();

        $email = $contacts->firstWhere('type', 20)?->value ?? '';
        $phone = $contacts->firstWhere('type', 21)?->value ?? '';

        // ICQ / Jabber / Skype / MSN kontakty (typy 18,19,22,23)
        $otherContacts = $mainUser
            ? DB::table('contacts as c')
                ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
                ->join('enum_types as et', 'et.id', '=', 'c.type')
                ->where('uc.user_id', $mainUser->id)
                ->whereIn('c.type', [18, 19, 22, 23])
                ->selectRaw('et.value as type_name, c.value')
                ->get()
            : collect();

        // Variabilní symboly
        $variableSymbols = DB::table('variable_symbols as vs')
            ->join('accounts as a', 'a.id', '=', 'vs.account_id')
            ->where('a.member_id', $id)
            ->pluck('vs.variable_symbol');

        // Kredit (zůstatek kreditního účtu)
        $creditBalance = DB::table('accounts')
            ->where('member_id', $id)
            ->where('account_attribute_id', 221100)
            ->value('balance') ?? 0;

        // Podsíť (první subnet přes zařízení člena)
        $subnetName = DB::table('users as u')
            ->join('devices as d', 'd.user_id', '=', 'u.id')
            ->join('ifaces as i', 'i.device_id', '=', 'd.id')
            ->join('ip_addresses as ip', 'ip.iface_id', '=', 'i.id')
            ->join('subnets as s', 's.id', '=', 'ip.subnet_id')
            ->where('u.member_id', $id)
            ->where('u.type', 1)
            ->value('s.name') ?? '';

        // Technici zařízení člena
        $engineers = DB::table('device_engineers as de')
            ->join('devices as d', 'd.id', '=', 'de.device_id')
            ->join('users as du', 'du.id', '=', 'd.user_id')
            ->join('users as u', 'u.id', '=', 'de.user_id')
            ->where('du.member_id', $id)
            ->selectRaw('CONCAT(u.name, " ", u.surname) as name')
            ->distinct()
            ->pluck('name');

        // Bankovní účet sdružení
        $bankAccountId = (int) Setting::get('export_header_bank_account', 1);
        $bankAccount = DB::table('bank_accounts')->where('id', $bankAccountId)->first();

        // Konfigurace
        $registrationInfo    = Setting::get('registration_info', '');
        $registrationLicense = Setting::get('registration_license', '');

        // Kontaktní údaje sdružení pro contract_end footer
        $assocWww      = Setting::get('association_www', '');
        $assocEmail    = Setting::get('association_email', '');
        $assocPhone    = Setting::get('association_phone', '');
        $assocCourt    = Setting::get('association_court', '');
        $assocCourtRef = Setting::get('association_court_ref', '');

        $viewName = ($type === 'contract_end')
            ? 'members.contract_end_pdf'
            : 'members.registration_pdf';

        $html = view($viewName, compact(
            'type', 'member', 'assoc', 'mainUser',
            'email', 'phone', 'variableSymbols', 'bankAccount',
            'registrationInfo', 'registrationLicense',
            'assocWww', 'assocEmail', 'assocPhone', 'assocCourt', 'assocCourtRef',
            'otherContacts', 'creditBalance', 'subnetName', 'engineers'
        ))->render();

        $tmpDir = storage_path('framework/cache/mpdf');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $mpdf = new \Mpdf\Mpdf([
            'tempDir'           => $tmpDir,
            'mode'              => 'utf-8',
            'format'            => 'A4',
            'margin_top'        => 20,
            'margin_bottom'     => 20,
            'margin_left'       => 15,
            'margin_right'      => 15,
            'default_font'      => 'dejavusans',
            'default_font_size' => 9,
        ]);
        $mpdf->WriteHTML($html);
        $pdfString = $mpdf->Output('', 'S');

        $filePrefix = match($type) {
            'registration'  => 'prihlaska',
            'end'           => 'ukonceni-clenstvi',
            'contract_end'  => 'vypoved-smlouvy',
            default         => 'export',
        };
        $filename = $filePrefix . '-' . $id . '.pdf';

        return [$pdfString, $filename];
    }
}
