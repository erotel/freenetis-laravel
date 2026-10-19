<?php

namespace App\Http\Controllers;

use App\Domains\Network\Repositories\DeviceTemplateRepository;
use App\Domains\Network\Support\TemplateIfaceValues;
use App\Models\Iface;
use Illuminate\Http\Request;

/** Šablony zařízení (definice rozhraní); data v doméně Network. */
class DeviceTemplateController extends Controller
{
    const ACL_SECTION = 'Device_templates_Controller';
    const ACL_KEY     = 'device_template';

    private const RULES = [
        'name'         => 'required|string|max:80',
        'enum_type_id' => 'required|integer|exists:enum_types,id',
        'default'      => 'boolean',
    ];

    public function __construct(private DeviceTemplateRepository $templates) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_KEY);
    }

    private function values(Request $request, array $data): array
    {
        return [
            'name'         => $data['name'],
            'enum_type_id' => $data['enum_type_id'],
            'default'      => $request->boolean('default'),
            'values'       => TemplateIfaceValues::fromInput($request->all()),
        ];
    }

    public function index()
    {
        abort_unless($this->can('view_all'), 403);
        return view('device_templates.index', [
            'templates' => $this->templates->all(),
            'canNew'    => $this->can('new_all'),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);
        $template = $this->templates->findWithTypeOrFail($id);
        return view('device_templates.show', [
            'template'  => $template,
            'ifaceDefs' => $template->getIfaceDefinitions(),
            'canEdit'   => $this->can('edit_all'),
            'canDelete' => $this->can('delete_all'),
        ]);
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);
        return view('device_templates.form', ['template' => null, 'deviceTypes' => $this->templates->deviceTypes(), 'ifaceTypes' => Iface::typeLabels()]);
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);
        $template = $this->templates->create($this->values($request, $request->validate(self::RULES)));
        if ($template->default) {
            $this->templates->clearOtherDefaults($template);
        }
        return redirect()->route('device_templates.show', $template->id)->with('success', 'Šablona zařízení byla úspěšně přidána.');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        return view('device_templates.form', [
            'template'    => $this->templates->findWithTypeOrFail($id),
            'deviceTypes' => $this->templates->deviceTypes(),
            'ifaceTypes'  => Iface::typeLabels(),
        ]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $template = $this->templates->findOrFail($id);
        $this->templates->update($template, $this->values($request, $request->validate(self::RULES)));
        if ($template->default) {
            $this->templates->clearOtherDefaults($template);
        }
        return redirect()->route('device_templates.show', $template->id)->with('success', 'Šablona zařízení byla úspěšně upravena.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);
        $this->templates->delete($this->templates->findOrFail($id));
        return redirect()->route('device_templates.index')->with('success', 'Šablona zařízení byla smazána.');
    }

    public function exportJson()
    {
        abort_unless($this->can('view_all'), 403);
        return response()->json($this->templates->exportAll())->header('Content-Disposition', 'attachment; filename="device_templates.json"');
    }
}
