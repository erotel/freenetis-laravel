<?php

namespace App\Http\Controllers;

use App\Domains\Members\Repositories\ContactRepository;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Http\Request;

/** Kontakty uživatele (e-mail, telefon, …); data v doméně Members. */
class ContactController extends Controller
{
    public function __construct(private ContactRepository $contacts) {}

    private const ACL_SECTION = 'Users_Controller';
    private const ACL_VALUE   = 'additional_contacts';

    private function can(string $action, string $value = self::ACL_VALUE): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, $value);
    }

    private function loadUser(int $userId): User
    {
        $user = $this->contacts->user($userId);
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

        $contacts = $this->contacts->forUser($user);

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
        $contactTypes = $this->contacts->newContactTypes();

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
        if ($this->contacts->linked($userId, (int) $data['type'], $data['value'])) {
            return back()->withInput()
                ->withErrors(['value' => 'Tento kontakt je již přiřazen tomuto uživateli.']);
        }

        $this->contacts->attach((int) $user->id, (int) $data['type'], $data['value']);

        session()->flash('success', 'Kontakt byl úspěšně přidán.');
        return redirect()->route('users.show', $userId);
    }

    public function edit(int $userId, int $contactId)
    {
        if (!$this->can('edit_all')) {
            abort(403);
        }

        $user = $this->loadUser($userId);

        $contact = $this->contacts->ofUser($userId, $contactId, true);

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

        $contact = $this->contacts->ofUser($userId, $contactId);

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

        $this->contacts->change($contact, $userId, $data['value']);

        session()->flash('success', 'Kontakt byl úspěšně upraven.');
        return redirect()->route('users.show', $userId);
    }

    public function destroy(int $userId, int $contactId)
    {
        if (!$this->can('delete_all')) {
            abort(403);
        }

        $user = $this->loadUser($userId);

        $contact = $this->contacts->ofUser($userId, $contactId);

        if (!$contact) {
            abort(404);
        }

        // Block deleting the last email/phone unless admin_delete privilege
        if (!$this->can('delete_all', 'additional_contacts_admin_delete')) {
            if (in_array($contact->type, [Contact::TYPE_EMAIL, Contact::TYPE_PHONE], true)) {
                $count = $this->contacts->countOfType($user, (int) $contact->type);
                if ($count <= 1) {
                    session()->flash('error', 'Nelze smazat poslední kontakt tohoto typu.');
                    return redirect()->route('users.show', $userId);
                }
            }
        }

        $this->contacts->detach($contact, (int) $user->id);

        session()->flash('success', 'Kontakt byl úspěšně smazán.');
        return redirect()->route('users.show', $userId);
    }
}
