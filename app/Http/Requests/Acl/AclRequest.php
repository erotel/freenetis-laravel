<?php

namespace App\Http\Requests\Acl;

use App\Services\AclService;
use Illuminate\Foundation\Http\FormRequest;

/** Správa ACL — práva na Aro_groups_Controller/aro_group (authorize() před validací). */
abstract class AclRequest extends FormRequest
{
    protected function canAcl(string $action): bool
    {
        return app(AclService::class)->hasAccess((int) $this->user()?->id, $action, 'Aro_groups_Controller', 'aro_group');
    }

    /** Úprava existujícího záznamu (routa s {id}) vyžaduje edit_all, nový new_all. */
    protected function canAclSave(): bool
    {
        $action = $this->route('id') !== null ? 'edit_all' : 'new_all';
        return app(AclService::class)->hasAccess((int) $this->user()?->id, $action, 'Aro_groups_Controller', 'aro_group');
    }
}
