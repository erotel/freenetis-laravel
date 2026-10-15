<?php

namespace App\Http\Requests\Members;

use App\Domains\Members\Support\NewMember;
use App\Helpers\MemberType;
use Illuminate\Foundation\Http\FormRequest;

/** Založení člena (ACL new_all ověří authorize() ještě před validací). */
class StoreMemberRequest extends FormRequest
{
    use MemberRules;

    public function authorize(): bool
    {
        return $this->canMembers('new_all');
    }

    public function rules(): array
    {
        return [
            'name'          => 'required|string|max:30',
            'surname'       => 'nullable|string|max:60',
            'type'          => 'required|integer|in:' . implode(',', array_keys(MemberType::labels())),
            'entrance_date' => 'required|date',
            'login'         => 'required|string|min:5|max:50|unique:users,login',
            'password'      => 'required|string|min:6|confirmed',
            'email'         => 'required|email|max:255',
            'phone'         => 'required|string|max:40',
            'town_id'       => 'required|integer|exists:towns,id',
            'street_id'     => 'required|integer|exists:streets,id',
            'street_number' => ['required', 'string', 'max:15', self::STREET_NUMBER_REGEX],
            'birthday'      => 'required|date',
            'comment'       => 'nullable|string|max:250',
        ] + self::identifierRules();
    }

    public function toNewMember(): NewMember
    {
        return new NewMember(
            name: (string) $this->input('name'),
            surname: $this->input('surname'),
            type: $this->input('type'),
            entranceDate: (string) $this->input('entrance_date'),
            login: (string) $this->input('login'),
            password: (string) $this->input('password'),
            email: $this->filled('email') ? (string) $this->input('email') : null,
            phone: $this->filled('phone') ? (string) $this->input('phone') : null,
            townId: (int) $this->input('town_id'),
            streetId: (int) $this->input('street_id'),
            streetNumber: (string) $this->input('street_number'),
            organizationIdentifier: $this->input('organization_identifier'),
            vatOrganizationIdentifier: $this->input('vat_organization_identifier'),
            birthday: $this->input('birthday'),
            comment: $this->input('comment'),
        );
    }
}
