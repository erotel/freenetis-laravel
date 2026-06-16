<?php
namespace App\Http\Controllers;

use App\Models\EmailQueue;
use App\Models\EmailQueueAttachment;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrationController extends Controller
{
    public function create()
    {
        if (!Setting::get('self_registration', 0)) {
            abort(404);
        }

        $towns = DB::table('towns')->orderBy('town')->get(['id', 'town', 'zip_code', 'quarter']);
        return view('registration.create', compact('towns'));
    }

    public function store(Request $request)
    {
        if (!Setting::get('self_registration', 0)) {
            abort(404);
        }

        $isOrg = (int) $request->input('registration_type') === 3;

        // 18+ kontrola pouze pro fyzické osoby (typ 17/18); organizace
        // mají jako "datum narození" reálně datum vzniku, omezení neplatí.
        $birthdayRules = ['required', 'date'];
        if (!$isOrg) {
            $birthdayRules[] = 'before_or_equal:' . now()->subYears(18)->toDateString();
        }

        $validated = $request->validate([
            'registration_type'           => 'required|in:3,17,18',
            'name'                        => ['required', 'string', 'max:100', 'regex:/^[^<>{};]+$/u'],
            'surname'                     => ['nullable', 'string', 'max:100', 'regex:/^[^<>{};]+$/u'],
            'birthday'                    => $birthdayRules,
            'login'                       => 'required|string|min:5|max:20|unique:users,login',
            'password'                    => ['required', 'string', 'min:' . (int) Setting::get('security_password_length', 8), 'confirmed'],
            'email'                       => 'required|email|max:255',
            'phone'                       => 'required|string|max:30',
            'town_id'                     => 'required|integer|exists:towns,id',
            'street_id'                   => 'required|integer|exists:streets,id',
            'street_number'               => ['required', 'string', 'max:15', 'regex:/^(ev\.?\s*č\.?\s*)?\d[\dA-Za-z\/\- ]*$/iu'],
            'organization_identifier'     => ['nullable', 'string', 'max:20', 'regex:/^[^@\s]*$/u'],
            'vat_organization_identifier' => ['nullable', 'string', 'max:20', 'regex:/^[^@\s]*$/u'],
            'comment'                     => 'nullable|string|max:250',
        ], [
            'organization_identifier.regex'     => 'IČO nesmí obsahovat email ani mezery — zadejte pouze číslo IČO.',
            'vat_organization_identifier.regex' => 'DIČ nesmí obsahovat email ani mezery — zadejte pouze DIČ (např. CZ12345678).',
            'birthday.before_or_equal'          => 'Registrace je možná až od 18 let.',
        ]);

        $memberId = null;
        $userId   = null;

        DB::transaction(function () use ($validated, &$memberId, &$userId) {
            // 1. Adresní bod
            $addressPointId = DB::table('address_points')->insertGetId([
                'town_id'       => $validated['town_id'],
                'street_id'     => $validated['street_id'],
                'country_id'    => 1,
                'street_number' => $validated['street_number'],
            ]);

            // 2. Celé jméno — ořez na 100 znaků (DB varchar limit)
            $isOrg = (int) $validated['registration_type'] === 3;
            $fullName = mb_substr(
                $isOrg
                    ? $validated['name']
                    : trim($validated['name'] . ($validated['surname'] ? ' ' . $validated['surname'] : '')),
                0, 100
            );

            // 3. Člen — typ 3 (org.), 17 (čekající člen) nebo 18 (čekající zákazník)
            $memberId = DB::table('members')->insertGetId([
                'name'                        => $fullName,
                'type'                        => (int) $validated['registration_type'],
                'entrance_date'               => now()->format('Y-m-d'),
                'leaving_date'                => '9999-12-31',
                'comment'                     => $validated['comment'] ?? '',
                'organization_identifier'     => $validated['organization_identifier'] ?? '',
                'vat_organization_identifier' => $validated['vat_organization_identifier'] ?? '',
                'address_point_id'            => $addressPointId,
                'locked'                      => 0,
                'registration'                => 0,
            ]);

            // 4. Uživatelský účet
            $userId = DB::table('users')->insertGetId([
                'member_id'            => $memberId,
                'name'                 => mb_substr($validated['name'], 0, 30),
                'surname'              => $isOrg
                    ? mb_substr(mb_substr($validated['name'], 30), 0, 60)
                    : mb_substr($validated['surname'] ?? '', 0, 60),
                'login'                => $validated['login'],
                'password'             => bcrypt($validated['password']),
                'application_password' => substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 8),
                'type'                 => 1,
                'birthday'             => $validated['birthday'],
                'comment'              => '',
                'settings'             => '',
            ]);

            // 5. Kreditní účet
            $accountId = DB::table('accounts')->insertGetId([
                'member_id'            => $memberId,
                'account_attribute_id' => 221100,
                'balance'              => 0,
                'comment'              => '',
            ]);

            // 6. Variabilní symbol
            $vs = (string)$memberId . str_pad(rand(0, 99), 2, '0', STR_PAD_LEFT);
            DB::table('variable_symbols')->insert([
                'account_id'      => $accountId,
                'variable_symbol' => $vs,
            ]);

            // 7. Třída rychlosti — nenastavuje se automaticky, admin ji vyplní ručně
            //    před vytvořením smlouvy (kontroluje ContractController::create).

            // 8. Kontakty (přes users_contacts M:N, contacts nemá user_id)
            $emailContactId = DB::table('contacts')->insertGetId([
                'type'  => 20,
                'value' => $validated['email'],
            ]);
            DB::table('users_contacts')->insert([
                'user_id'          => $userId,
                'contact_id'       => $emailContactId,
                'mail_redirection' => 0,
            ]);

            $phoneContactId = DB::table('contacts')->insertGetId([
                'type'  => 21, // CONTACT_PHONE (enum_types.id=21 = "Telefon")
                'value' => $validated['phone'],
            ]);
            DB::table('users_contacts')->insert([
                'user_id'          => $userId,
                'contact_id'       => $phoneContactId,
                'mail_redirection' => 0,
            ]);
        });

        $this->sendRegistrationSummary($userId);

        return redirect()->route('registration.success');
    }

    /**
     * Queue a registration-summary email with a PDF attachment to the new member.
     * Silently no-op if disabled, no email on file, or attachment missing.
     */
    private function sendRegistrationSummary(?int $userId): void
    {
        if (!$userId || !Setting::get('registration_summary_enabled', 0)) {
            return;
        }

        $pdfSetting = trim((string) Setting::get('registration_summary_pdf', ''));
        if ($pdfSetting === '') {
            return;
        }

        $pdfPath = str_starts_with($pdfSetting, '/')
            ? $pdfSetting
            : storage_path('app/private/' . ltrim($pdfSetting, '/'));

        if (!is_file($pdfPath)) {
            return;
        }

        $email = DB::table('contacts as c')
            ->join('users_contacts as uc', 'uc.contact_id', '=', 'c.id')
            ->where('uc.user_id', $userId)
            ->where('c.type', 20)
            ->orderBy('c.id')
            ->value('c.value');

        if (!$email) {
            return;
        }

        try {
            $emailQueue = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => 'Shrnutí smlouvy - PVfree.net',
                'body'        => "<p>Dobrý den,</p>"
                    . "<p>děkujeme za registraci. V příloze posíláme shrnutí Vaší smlouvy ve formátu PDF.</p>"
                    . "<p>S pozdravem,<br>PVfree.net, z.s.</p>",
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);

            EmailQueueAttachment::create([
                'email_queue_id' => $emailQueue->id,
                'path'           => $pdfPath,
                'name'           => 'smlouva_shrnuti.pdf',
                'mime'           => 'application/pdf',
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // Silent: registration must succeed even if email queueing fails
        }
    }

    public function success()
    {
        return view('registration.success');
    }
}
