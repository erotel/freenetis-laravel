<?php

namespace App\Http\Requests\Members;

use App\Domains\Members\Support\MemberChanges;
use App\Helpers\MemberType;
use Illuminate\Foundation\Http\FormRequest;

/** Úprava člena (ACL edit_all ověří authorize() ještě před validací). */
class UpdateMemberRequest extends FormRequest
{
    use MemberRules;

    public function authorize(): bool
    {
        return $this->canMembers('edit_all');
    }

    public function rules(): array
    {
        return [
            'name'           => 'required|string|max:100',
            'type'           => 'required|integer|in:' . implode(',', array_keys(MemberType::labels())),
            'entrance_date'  => 'nullable|date',
            'leaving_date'   => 'nullable|date_format:Y-m-d',
            'comment'        => 'nullable|string|max:250',
            'town_id'        => 'nullable|integer|exists:towns,id',
            'street_id'      => 'nullable|integer|exists:streets,id',
            'street_number'  => ['nullable', 'string', 'max:15', self::STREET_NUMBER_REGEX],
            'speed_class_id' => 'nullable|integer|exists:speed_classes,id',
        ] + self::identifierRules();
    }

    public function toChanges(): MemberChanges
    {
        $data = $this->validated();

        return new MemberChanges(
            name: $data['name'],
            type: (int) $data['type'],
            typeInput: $data['type'],
            entranceDate: ($data['entrance_date'] ?? null) ?: null,
            leavingDate: $this->input('leaving_date') ?: null,
            comment: $data['comment'] ?? null,
            organizationIdentifier: $data['organization_identifier'] ?? null,
            vatOrganizationIdentifier: $data['vat_organization_identifier'] ?? null,
            locked: $this->boolean('locked'),
            registration: $this->boolean('registration'),
            speedClassId: $data['speed_class_id'] ?? null,
            canVote: $this->boolean('can_vote'),
            notificationByRedirection: $this->boolean('notification_by_redirection'),
            notificationByEmail: $this->boolean('notification_by_email'),
            notificationBySms: $this->boolean('notification_by_sms'),
            townId: $this->filled('town_id') ? (int) $data['town_id'] : null,
            streetId: $data['street_id'] ?? null,
            streetNumber: $data['street_number'] ?? null,
        );
    }
}
