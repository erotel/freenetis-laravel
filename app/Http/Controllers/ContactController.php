<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\EnumType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ContactController extends Controller
{
    private const ACL_SECTION = 'Users_Controller';
    private const ACL_VALUE   = 'additional_contacts';

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    private function loadUser(int $userId): User
    {
        $user = User::find($userId);
        if (!$user) {
            abort(404);
        }
        return $user;
    }

    public function showByUser(int $userId)
    {
        $user = $this->loadUser($userId);

        $isOwn  = ($userId === auth()->id());
        $canView = $isOwn
            ? ($this->can('view_own') || $this->can('view_all'))
            : $this->can('view_all');

        if (!$canView) {
            abort(403);
        }

        $contacts = $user->contacts()->with('enumType')->get();

        return view('contacts.show_by_user', [
            'user'      => $user,
            'contacts'  => $contacts,
            'canAdd'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function create(int $userId)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $user         = $this->loadUser($userId);
        // Při zakládání nového kontaktu schováme zastaralé protokoly
        // (ICQ/MSN/Jabber/Skype). Existující záznamy s těmito typy zůstávají
        // v UI viditelné — deprecated řeší jen nový vstup.
        $contactTypes = EnumType::where('type_id', EnumType::CONTACT_GROUP_ID)
            ->notDeprecated()
            ->orderBy('id')
            ->pluck('value', 'id');

        return view('contacts.create', [
            'user'         => $user,
            'contactTypes' => $contactTypes,
        ]);
    }

    public function store(Request $request, int $userId)
    {
        if (!$this->can('new_all')) {
            abort(403);
        }

        $user   = $this->loadUser($userId);
        $typeId = (int) $request->input('type');

        $rules = [
            'type'  => 'required|integer|exists:enum_types,id',
            'value' => ['required', 'string', 'max:255'],
        ];

        if ($typeId === Contact::TYPE_EMAIL) {
            $rules['value'][] = 'email';
        } elseif ($typeId === Contact::TYPE_PHONE) {
            $rules['value'][] = 'regex:/^[+0-9\s\-()]+$/';
        }

        $data = $request->validate($rules);

        // Duplicate: same type+value already linked to this user
        $alreadyLinked = Contact::where('type', $data['type'])
            ->where('value', $data['value'])
            ->whereHas('users', fn($q) => $q->where('users.id', $userId))
            ->exists();

        if ($alreadyLinked) {
            return back()->withInput()
                ->withErrors(['value' => 'Tento kontakt je již přiřazen tomuto uživateli.']);
        }

        DB::transaction(function () use ($data, $user) {
            $contact = Contact::firstOrCreate(
                ['type' => $data['type'], 'value' => $data['value']]
            );

            if (!$contact->users()->where('users.id', $user->id)->exists()) {
                $contact->users()->attach($user->id, ['mail_redirection' => 0]);
            }
        });

        session()->flash('success', 'Kontakt byl úspěšně přidán.');
        return redirect()->route('users.show', $userId);
    }

    public function edit(int $userId, int $contactId)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $user = $this->loadUser($userId);

        $contact = Contact::with('enumType')
            ->whereHas('users', fn($q) => $q->where('users.id', $userId))
            ->find($contactId);

        if (!$contact) {
            abort(404);
        }

        return view('contacts.edit', [
            'user'    => $user,
            'contact' => $contact,
        ]);
    }

    public function update(Request $request, int $userId, int $contactId)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $this->loadUser($userId);

        $contact = Contact::whereHas('users', fn($q) => $q->where('users.id', $userId))
            ->find($contactId);

        if (!$contact) {
            abort(404);
        }

        $rules = ['value' => ['required', 'string', 'max:255']];

        if ($contact->type === Contact::TYPE_EMAIL) {
            $rules['value'][] = 'email';
        } elseif ($contact->type === Contact::TYPE_PHONE) {
            $rules['value'][] = 'regex:/^[+0-9\s\-()]+$/';
        }

        $data = $request->validate($rules);

        $contact->value = $data['value'];
        $contact->save();

        session()->flash('success', 'Kontakt byl úspěšně upraven.');
        return redirect()->route('users.show', $userId);
    }

    public function destroy(int $userId, int $contactId)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $user = $this->loadUser($userId);

        $contact = Contact::whereHas('users', fn($q) => $q->where('users.id', $userId))
            ->find($contactId);

        if (!$contact) {
            abort(404);
        }

        // Block deleting the last email/phone unless admin_delete privilege
        if (!$this->can('delete_all', 'additional_contacts_admin_delete')) {
            if (in_array($contact->type, [Contact::TYPE_EMAIL, Contact::TYPE_PHONE], true)) {
                $count = $user->contacts()->where('type', $contact->type)->count();
                if ($count <= 1) {
                    session()->flash('error', 'Nelze smazat poslední kontakt tohoto typu.');
                    return redirect()->route('users.show', $userId);
                }
            }
        }

        DB::transaction(function () use ($contact, $user) {
            $contact->users()->detach($user->id);

            if ($contact->users()->count() === 0) {
                $contact->delete();
            }
        });

        session()->flash('success', 'Kontakt byl úspěšně smazán.');
        return redirect()->route('users.show', $userId);
    }
}
