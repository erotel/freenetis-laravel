<?php
namespace App\Http\Controllers;

use App\Domains\Members\Actions\SelfRegisterAction;
use App\Domains\Members\Repositories\AddressBookRepository;
use App\Models\Setting;
use Illuminate\Http\Request;

/** Veřejná samoregistrace (Setting self_registration); logika v doméně Members. */
class RegistrationController extends Controller
{
    public function create()
    {
        if (!Setting::get('self_registration', 0)) {
            abort(404);
        }

        $towns = app(AddressBookRepository::class)->townsForRegistration();
        return view('registration.create', compact('towns'));
    }

    public function store(Request $request, SelfRegisterAction $register)
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
            'name'                        => ['required', 'string', 'max:100', 'regex:/^[^<>{};"\[\]$\\\\\p{Cc}]+$/u'],
            'surname'                     => ['nullable', 'string', 'max:100', 'regex:/^[^<>{};"\[\]$\\\\\p{Cc}]+$/u'],
            'birthday'                    => $birthdayRules,
            'login'                       => ['required', 'string', 'min:5', 'max:20', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,login'],
            'password'                    => \App\Rules\StrongPassword::rules($request->input('login')),
            'email'                       => 'required|email|max:255',
            'phone'                       => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ]{9,20}$/'],
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
            'login.regex'                       => 'Login může obsahovat jen písmena bez diakritiky, číslice a znaky . _ -',
            'phone.regex'                       => 'Telefon zadejte jen číslicemi (mezery a úvodní + jsou povolené).',
        ]);

        $register->handle($validated);

        return redirect()->route('registration.success');
    }

    public function success()
    {
        return view('registration.success');
    }
}
