<?php

namespace App\Http\Requests\Acl;

class AddGroupUserRequest extends AclRequest
{
    public function authorize(): bool
    {
        return $this->canAcl('edit_all');
    }

    public function rules(): array
    {
        return ['user_id' => 'required|integer|exists:users,id'];
    }
}
