<?php

namespace App\Domains\Access\Settings;

use App\Domains\Audit\AuditLogger;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * M2M tokeny: dhcp_api_token (DHCP export pro MikroTiky) a
 * web_interface_api_token (exporty pro brány igw1/igw2). Uložené šifrovaně.
 */
class ApiTokens
{
    /** Chybějící tokeny vygeneruje. @return array{dhcp:string, web_interface:string} */
    public function ensure(): array
    {
        foreach (['dhcp_api_token', 'web_interface_api_token'] as $key) {
            if (!Setting::get($key)) {
                Setting::set($key, Str::random(32));
            }
        }
        return ['dhcp' => Setting::get('dhcp_api_token'), 'web_interface' => Setting::get('web_interface_api_token')];
    }

    public function regenerateDhcp(): void
    {
        Setting::set('dhcp_api_token', Str::random(32));
    }

    /** Audit bez hodnoty tokenu. */
    public function regenerateWebInterface(): void
    {
        Setting::set('web_interface_api_token', Str::random(32));
        AuditLogger::log('updated', 'config', null, null, ['web_interface_api_token' => '***', 'action' => 'regenerated']);
    }
}
