<?php

namespace App\Http\Controllers;

use App\Models\DeviceTemplate;
use App\Models\EnumType;
use App\Models\Iface;
use Illuminate\Http\Request;

class DeviceTemplateController extends Controller
{
    const ACL_SECTION = 'Device_templates_Controller';
    const ACL_KEY     = 'device_template';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_KEY);
    }

    // ── Index ─────────────────────────────────────────────────────────────────

    public function index()
    {
        abort_unless($this->can('view_all'), 403);

        $templates = DeviceTemplate::with('enumType')
            ->orderBy('name')
            ->get();

        return view('device_templates.index', [
            'templates' => $templates,
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    // ── Show ──────────────────────────────────────────────────────────────────

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        $template = DeviceTemplate::with('enumType')->findOrFail($id);

        return view('device_templates.show', [
            'template'   => $template,
            'ifaceDefs'  => $template->getIfaceDefinitions(),
            'canEdit'    => $this->can('edit_all'),
            'canDelete'  => $this->can('delete_all'),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────────────────

    public function create()
    {
        abort_unless($this->can('new_all'), 403);

        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)
            ->orderBy('value')->pluck('value', 'id');

        return view('device_templates.form', [
            'template'    => null,
            'deviceTypes' => $deviceTypes,
            'ifaceTypes'  => Iface::typeLabels(),
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $data = $request->validate([
            'name'        => 'required|string|max:80',
            'enum_type_id' => 'required|integer|exists:enum_types,id',
            'default'     => 'boolean',
        ]);

        $values = $this->buildValues($request);

        $template = DeviceTemplate::create([
            'name'         => $data['name'],
            'enum_type_id' => $data['enum_type_id'],
            'default'      => $request->boolean('default'),
            'values'       => $values,
        ]);

        if ($template->default) {
            $this->clearOtherDefaults($template);
        }

        return redirect()->route('device_templates.show', $template->id)
            ->with('success', 'Šablona zařízení byla úspěšně přidána.');
    }

    // ── Edit ──────────────────────────────────────────────────────────────────

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $template = DeviceTemplate::with('enumType')->findOrFail($id);
        $deviceTypes = EnumType::where('type_id', EnumType::DEVICE_GROUP_ID)
            ->orderBy('value')->pluck('value', 'id');

        return view('device_templates.form', [
            'template'    => $template,
            'deviceTypes' => $deviceTypes,
            'ifaceTypes'  => Iface::typeLabels(),
        ]);
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $template = DeviceTemplate::findOrFail($id);

        $data = $request->validate([
            'name'         => 'required|string|max:80',
            'enum_type_id' => 'required|integer|exists:enum_types,id',
            'default'      => 'boolean',
        ]);

        $values = $this->buildValues($request);

        $template->update([
            'name'         => $data['name'],
            'enum_type_id' => $data['enum_type_id'],
            'default'      => $request->boolean('default'),
            'values'       => $values,
        ]);

        if ($template->default) {
            $this->clearOtherDefaults($template);
        }

        return redirect()->route('device_templates.show', $template->id)
            ->with('success', 'Šablona zařízení byla úspěšně upravena.');
    }

    // ── Destroy ───────────────────────────────────────────────────────────────

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $template = DeviceTemplate::findOrFail($id);
        $template->delete();

        return redirect()->route('device_templates.index')
            ->with('success', 'Šablona zařízení byla smazána.');
    }

    // ── Export JSON ───────────────────────────────────────────────────────────

    public function exportJson()
    {
        abort_unless($this->can('view_all'), 403);

        $templates = DeviceTemplate::all()->map(fn($t) => [
            'name'         => $t->name,
            'enum_type_id' => $t->enum_type_id,
            'values'       => $t->values,
            'default'      => $t->default,
        ]);

        return response()->json($templates)
            ->header('Content-Disposition', 'attachment; filename="device_templates.json"');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildValues(Request $request): array
    {
        $values = [];
        $typeLabels = Iface::typeLabels();

        foreach (array_keys($typeLabels) as $type) {
            $prefix = "iface_{$type}";

            if ($type === Iface::WIRELESS) {
                $minCount = (int) $request->input("{$prefix}_min_count", 0);
                $maxCount = (int) $request->input("{$prefix}_max_count", 0);
                if ($minCount > $maxCount) $maxCount = $minCount;
                $values[$type] = [
                    'type'      => $type,
                    'min_count' => $minCount,
                    'max_count' => $maxCount,
                    'has_ip'    => true,
                    'has_mac'   => true,
                    'has_link'  => true,
                    'items'     => $this->buildItems($request, $prefix, $maxCount),
                ];
            } else {
                $count = (int) $request->input("{$prefix}_count", 0);
                $hasIp  = in_array($type, [Iface::ETHERNET, Iface::WIRELESS, Iface::INTERNAL]);
                $hasMac = in_array($type, [Iface::ETHERNET, Iface::WIRELESS]);
                $values[$type] = [
                    'type'     => $type,
                    'count'    => $count,
                    'has_ip'   => $hasIp,
                    'has_mac'  => $hasMac,
                    'has_link' => true,
                    'items'    => $this->buildItems($request, $prefix, $count),
                ];
            }
        }

        return $values;
    }

    private function buildItems(Request $request, string $prefix, int $count): array
    {
        $items = [];
        $names = $request->input("{$prefix}_names", []);
        for ($i = 0; $i < $count; $i++) {
            $items[] = ['name' => $names[$i] ?? ''];
        }
        return $items;
    }

    private function clearOtherDefaults(DeviceTemplate $template): void
    {
        DeviceTemplate::where('enum_type_id', $template->enum_type_id)
            ->where('id', '!=', $template->id)
            ->where('default', 1)
            ->update(['default' => 0]);
    }
}
