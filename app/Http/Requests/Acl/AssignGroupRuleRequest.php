<?php

namespace App\Http\Requests\Acl;

class AssignGroupRuleRequest extends AclRequest
{
    public function authorize(): bool
    {
        return $this->canAcl('edit_all');
    }

    public function rules(): array
    {
        return ['acl_id' => 'required|integer|exists:acl,id'];
    }
}
