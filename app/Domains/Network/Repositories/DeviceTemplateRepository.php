<?php

namespace App\Domains\Network\Repositories;

use App\Models\DeviceTemplate;
use App\Models\EnumType;
use Illuminate\Support\Collection;

/** Šablony zařízení (device_templates): definice rozhraní, výchozí šablona pro typ zařízení. */
class DeviceTemplateRepository
{
    public function all(): Collection
    {
        return DeviceTemplate::with('enumType')->orderBy('name')->get();
    }

    public function findOrFail(int $id): DeviceTemplate
    {
        return DeviceTemplate::findOrFail($id);
    }

    public function findWithTypeOrFail(int $id): DeviceTemplate
    {
        return DeviceTemplate::with('enumType')->findOrFail($id);
    }

    /** Typy zařízení (číselník) id => název. */
    public function deviceTypes(): Collection
    {
        return EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)->orderBy('value')->pluck('value', 'id');
    }

    public function create(array $values): DeviceTemplate
    {
        return DeviceTemplate::create($values);
    }

    public function update(DeviceTemplate $template, array $values): void
    {
        $template->update($values);
    }

    public function delete(DeviceTemplate $template): void
    {
        $template->delete();
    }

    /** Výchozí může být jen jedna šablona na typ zařízení. */
    public function clearOtherDefaults(DeviceTemplate $template): void
    {
        DeviceTemplate::where('enum_type_id', $template->enum_type_id)->where('id', '!=', $template->id)->where('default', 1)->update(['default' => 0]);
    }

    public function exportAll(): Collection
    {
        return DeviceTemplate::all()->map(fn ($t) => ['name' => $t->name, 'enum_type_id' => $t->enum_type_id, 'values' => $t->values, 'default' => $t->default]);
    }
}
