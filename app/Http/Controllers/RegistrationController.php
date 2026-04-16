<?php
namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SpeedClass;
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

        $validated = $request->validate([
            'registration_type'           => 'required|in:17,18',
            'name'                        => ['required', 'string', 'max:100', 'regex:/^[\p{L}\s\-\.]+$/u'],
            'surname'                     => ['nullable', 'string', 'max:100', 'regex:/^[\p{L}\s\-\.]+$/u'],
            'birthday'                    => 'required|date',
            'login'                       => 'required|string|min:5|max:20|unique:users,login',
            'password'                    => 'required|string|min:6|confirmed',
            'email'                       => 'required|email|max:255',
            'phone'                       => 'required|string|max:30',
            'town_id'                     => 'required|integer|exists:towns,id',
            'street_id'                   => 'required|integer|exists:streets,id',
            'street_number'               => 'required|string|max:50',
            'organization_identifier'     => 'nullable|string|max:20',
            'vat_organization_identifier' => 'nullable|string|max:20',
            'comment'                     => 'nullable|string|max:250',
        ]);

        $memberId = null;

        DB::transaction(function () use ($validated, &$memberId) {
            // 1. Adresní bod
            $addressPointId = DB::table('address_points')->insertGetId([
                'town_id'       => $validated['town_id'],
                'street_id'     => $validated['street_id'],
                'country_id'    => 1,
                'street_number' => $validated['street_number'],
            ]);

            // 2. Celé jméno — ořez na 100 znaků (DB varchar limit)
            $fullName = mb_substr(
                trim($validated['name'] . ($validated['surname'] ? ' ' . $validated['surname'] : '')),
                0, 100
            );

            // 3. Člen — typ 17 (čekající člen) nebo 18 (čekající zákazník)
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
                'name'                 => mb_substr($validated['name'], 0, 100),
                'surname'              => mb_substr($validated['surname'] ?? '', 0, 100),
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

            // 7. Výchozí třída rychlosti
            $defaultSpeedClassId = SpeedClass::where('regular_member_default', 1)->value('id') ?? 1;
            DB::table('members')->where('id', $memberId)->update([
                'speed_class_id' => $defaultSpeedClassId,
            ]);

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
                'type'  => 5,
                'value' => $validated['phone'],
            ]);
            DB::table('users_contacts')->insert([
                'user_id'          => $userId,
                'contact_id'       => $phoneContactId,
                'mail_redirection' => 0,
            ]);
        });

        return redirect()->route('registration.success');
    }

    public function success()
    {
        return view('registration.success');
    }
}
