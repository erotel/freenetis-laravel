<?php

namespace App\Http\Requests\Settings;

use App\Domains\Access\Repositories\GponOltRepository;

/** Nový / upravený OLT. U úpravy neexistující OLT → 404 ještě před validací. */
class SaveGponOltRequest extends SettingsRequest
{
    public function authorize(): bool
    {
        if (!$this->canEditSettings()) {
            return false;
        }
        $id = $this->route('id');
        abort_if($id !== null && !app(GponOltRepository::class)->exists((int) $id), 404);
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');
        return [
            'name'            => 'required|string|max:100',
            'ip'              => 'required|ip|unique:gpon_olts,ip' . ($id !== null ? ',' . (int) $id : ''),
            'snmp_user'       => 'required|string|max:100',
            // u úpravy prázdné = ponechat uložené heslo (do formuláře se neposílá)
            'snmp_auth_pass'  => ($id !== null ? 'nullable' : 'required') . '|string|max:100',
            'snmp_priv_pass'  => ($id !== null ? 'nullable' : 'required') . '|string|max:100',
            'snmp_auth_proto' => 'required|in:SHA,MD5',
            'snmp_priv_proto' => 'required|in:AES,DES',
            'line_prof'       => 'required|string|max:100',
            'service_prof'    => 'required|string|max:100',
            'traffic_table'   => 'required|string|max:100',
            'gpon_port'       => 'required|string|max:20',
            'base_vlan'       => 'nullable|integer|min:1|max:4094',
            'port_count'      => 'nullable|integer|min:1',
            'vlan_map'        => 'nullable|string',
            'geocode_city'    => 'nullable|string|max:100',
        ];
    }

    /** Validovaná data, vlan_map dekódovaný z JSON (neplatný / prázdný → null). */
    public function oltData(): array
    {
        $data = $this->validated();
        $decoded = !empty($data['vlan_map']) ? json_decode($data['vlan_map'], true) : null;
        $data['vlan_map'] = is_array($decoded) ? $decoded : null;
        foreach (['snmp_auth_pass', 'snmp_priv_pass'] as $key) {
            if (($data[$key] ?? '') === '') {
                unset($data[$key]);
            }
        }
        return $data;
    }
}
