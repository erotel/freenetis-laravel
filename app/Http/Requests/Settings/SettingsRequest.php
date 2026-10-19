<?php

namespace App\Http\Requests\Settings;

use App\Services\AclService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Zápis do Nastavení: ACL edit_all na Settings_Controller/finance_settings
 * (authorize() běží před validací → 403 dřív než chyby formuláře).
 * Záložky bez validace používají přímo tuto třídu.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->canEditSettings();
    }

    protected function canEditSettings(): bool
    {
        return app(AclService::class)->hasAccess((int) $this->user()?->id, 'edit_all', 'Settings_Controller', 'finance_settings');
    }

    public function rules(): array
    {
        return [];
    }
}
