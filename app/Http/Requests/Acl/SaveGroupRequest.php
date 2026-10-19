<?php

namespace App\Http\Requests\Acl;

use App\Domains\Acl\Repositories\AroGroupRepository;

/**
 * Nová / upravená skupina; úprava neexistující → 404 ještě před validací.
 * Rodičem nesmí být skupina sama ani její podskupina — cyklus v parent_id
 * dřív zacyklil výpočet práv (AclService) pro všechny uživatele.
 */
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
            'parent_id' => ['required', 'integer', 'exists:aro_groups,id', function ($attr, $value, $fail) {
                $id = $this->route('id');
                if ($id === null) {
                    return;
                }
                if ((int) $value === (int) $id || in_array((int) $value, app(AroGroupRepository::class)->descendantIds((int) $id), true)) {
                    $fail('Nadřazená skupina nemůže být tato skupina ani její podskupina.');
                }
            }],
        ];
    }
}
