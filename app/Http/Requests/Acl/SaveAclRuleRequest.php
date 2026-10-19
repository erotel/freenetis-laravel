<?php

namespace App\Http\Requests\Acl;

class SaveAclRuleRequest extends AclRequest
{
    public function authorize(): bool
    {
        return $this->canAclSave();
    }

    public function rules(): array
    {
        return [
            'note'        => 'required|string|max:255',
            'actions'     => 'required|array|min:1',
            'actions.*'   => 'string',
            'axo_ids'     => 'required|array|min:1',
            'axo_ids.*'   => 'integer|exists:axo,id',
            'group_ids'   => 'nullable|array',
            'group_ids.*' => 'integer|exists:aro_groups,id',
        ];
    }
}
