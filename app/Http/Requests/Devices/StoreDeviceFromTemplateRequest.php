<?php

namespace App\Http\Requests\Devices;

use App\Domains\Network\Repositories\DeviceRepository;
use App\Domains\Network\Support\TemplateIfaceInput;
use App\Models\DeviceTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Registrace zařízení ze šablony (formulář devices/add). Pravidla rozhraní
 * (iface_name_N, iface_mac_N, iface_ip_N, iface_subnet_N) se skládají ze šablony;
 * definice s count 0 se přeskakují. Po úspěšné validaci navíc: IP nesmí být
 * v podsíti obsazená a aspoň jedno rozhraní s MAC musí MAC mít.
 *
 * Formulář posílá member_id → před validací se převede na hlavního uživatele
 * (user_id); člen bez hlavního uživatele = chyba u member_id.
 */
class StoreDeviceFromTemplateRequest extends FormRequest
{
    use DeviceRules;

    private ?array $ifaceDefinitions = null;

    public function authorize(): bool
    {
        return $this->canDevices('new_all');
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('member_id') && !$this->filled('user_id')) {
            $userId = app(DeviceRepository::class)->mainUserIdOfMember((int) $this->input('member_id'));
            if ($userId) {
                $this->merge(['user_id' => $userId]);
            }
        }
    }

    public function rules(): array
    {
        $rules = [
            // člen bez hlavního uživatele: user_id chybí → chyba jen u member_id (viz níže)
            'member_id'          => ['nullable', function ($attribute, $value, $fail) {
                if (!$this->filled('user_id')) {
                    $fail('Zvolený člen nemá hlavního uživatele.');
                }
            }],
            'user_id'            => 'required_without:member_id|nullable|integer|exists:users,id',
            'name'               => 'required|string|max:255',
            'type'               => 'required|integer|exists:enum_types,id',
            'device_template_id' => 'nullable|integer|exists:device_templates,id',
            'buy_date'           => 'nullable|date',
            'comment'            => 'nullable|string|max:254',
        ] + self::addressRules();

        foreach ($this->ifaceDefinitions() as $n => $def) {
            if (($def['count'] ?? 1) <= 0) continue;
            $rules["iface_name_{$n}"] = 'required|string|max:254';
            if ($def['has_mac']) {
                $rules["iface_mac_{$n}"] = ['nullable', 'regex:/^([0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}$/', 'unique:ifaces,mac'];
            }
            if ($def['has_ip']) {
                $rules["iface_ip_{$n}"]     = ['nullable', 'ipv4', "required_with:iface_subnet_{$n}", new \App\Rules\IpInSubnet($this->input("iface_subnet_{$n}"))];
                $rules["iface_subnet_{$n}"] = "nullable|integer|exists:subnets,id|required_with:iface_ip_{$n}";
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'iface_mac_*.regex'  => 'MAC adresa musí být ve formátu aa:bb:cc:dd:ee:ff.',
            'iface_mac_*.unique' => 'Tato MAC adresa je již použita.',
        ];
    }

    /** Kontroly nad celým formulářem — jen když základní pravidla prošla (vždy jedna chyba). */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            $devices = app(DeviceRepository::class);
            foreach ($this->activeDefinitions() as $n => $def) {
                if (!($def['has_ip'] ?? false)) continue;
                $ip = $this->input("iface_ip_{$n}");
                $subnetId = $this->input("iface_subnet_{$n}");
                if ($ip && $subnetId && $devices->ipTakenInSubnet($ip, (int) $subnetId)) {
                    $v->errors()->add("iface_ip_{$n}", "IP adresa {$ip} je již použita v tomto subnetu.");
                    return;
                }
            }

            $macDefs = array_filter($this->activeDefinitions(), fn ($d) => $d['has_mac'] ?? false);
            if ($macDefs && !array_filter(array_keys($macDefs), fn ($n) => $this->filled("iface_mac_{$n}"))) {
                $v->errors()->add('iface_mac_0', 'Alespoň jedno rozhraní musí mít vyplněnou MAC adresu.');
            }
        });
    }

    /** Definice rozhraní vybrané šablony (bez šablony prázdné). */
    public function ifaceDefinitions(): array
    {
        if ($this->ifaceDefinitions === null) {
            $templateId = (int) $this->input('device_template_id');
            $this->ifaceDefinitions = $templateId
                ? DeviceTemplate::findOrFail($templateId)->getIfaceDefinitions()
                : [];
        }
        return $this->ifaceDefinitions;
    }

    /** Definice s count > 0 (zachované indexy N). */
    private function activeDefinitions(): array
    {
        return array_filter($this->ifaceDefinitions(), fn ($d) => ($d['count'] ?? 1) > 0);
    }

    /** @return array{user_id:int, name:string, type:int, buy_date?:?string, comment?:?string, town_id?:?int, street_id?:?int, street_number?:?string} */
    public function deviceData(): array
    {
        return $this->validated();
    }

    /** @return TemplateIfaceInput[] */
    public function ifaceInputs(): array
    {
        $out = [];
        foreach ($this->activeDefinitions() as $n => $def) {
            $hasIp = $def['has_ip'] && $this->filled("iface_ip_{$n}");
            $out[] = new TemplateIfaceInput(
                type: (int) $def['type'],
                name: $this->input("iface_name_{$n}"),
                mac: ($def['has_mac'] && $this->filled("iface_mac_{$n}")) ? $this->input("iface_mac_{$n}") : null,
                ip: $hasIp ? $this->input("iface_ip_{$n}") : null,
                subnetId: $hasIp ? (int) $this->input("iface_subnet_{$n}") : null,
            );
        }
        return $out;
    }

    public function connectionRequestId(): ?int
    {
        return ((int) $this->input('connection_request_id')) ?: null;
    }
}
