<?php

namespace App\Http\Requests\Members;

use App\Services\AclService;

/** Sdílená pravidla formulářů člena (založení i úprava). */
trait MemberRules
{
    /** Hrubé ACL sekce členů (Members_Controller / members) pro akci. */
    protected function canMembers(string $action): bool
    {
        return app(AclService::class)->hasAccess($this->user()?->id ?? 0, $action, 'Members_Controller', 'members');
    }

    /** @return array<string, mixed> */
    protected static function identifierRules(): array
    {
        return [
            'organization_identifier'     => ['nullable', 'string', 'max:20', 'regex:/^[^@\s]*$/u'],
            'vat_organization_identifier' => ['nullable', 'string', 'max:30', 'regex:/^[^@\s]*$/u'],
        ];
    }

    /** Číslo popisné/orientační, případně „ev. č." */
    protected const STREET_NUMBER_REGEX = 'regex:/^(ev\.?\s*č\.?\s*)?\d[\dA-Za-z\/\- ]*$/iu';

    public function messages(): array
    {
        return [
            'organization_identifier.regex'     => 'IČO nesmí obsahovat email ani mezery — zadejte pouze číslo IČO.',
            'vat_organization_identifier.regex' => 'DIČ nesmí obsahovat email ani mezery — zadejte pouze DIČ (např. CZ12345678).',
        ];
    }
}
