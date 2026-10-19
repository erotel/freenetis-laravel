<?php

namespace App\Http\Requests\Acl;

use App\Domains\Acl\Repositories\AroGroupRepository;

/** Nová / upravená skupina; úprava neexistující → 404 ještě před validací. */
class SaveGroupRequest extends AclRequest
{
    public function authorize(): bool
    {
        if (!$this->canAclSave()) {
            return false;
        }
        $id = $this->route('id');
        abort_if($id !== null && !app(AroGroupRepository::class)->find((int) $id), 404);
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:50',
            'parent_id' => 'required|integer|exists:aro_groups,id',
        ];
    }
}
