<?php

namespace App\Http\Controllers;

use App\Domains\Acl\Actions\AddUserToGroupAction;
use App\Domains\Acl\Actions\AssignRuleToGroupAction;
use App\Domains\Acl\Actions\CreateGroupAction;
use App\Domains\Acl\Actions\DeleteAclRuleAction;
use App\Domains\Acl\Actions\DeleteGroupAction;
use App\Domains\Acl\Actions\RemoveUserFromGroupAction;
use App\Domains\Acl\Actions\SaveAclRuleAction;
use App\Domains\Acl\Actions\SetGroupMfaRequiredAction;
use App\Domains\Acl\Actions\UnassignRuleFromGroupAction;
use App\Domains\Acl\Actions\UpdateGroupAction;
use App\Domains\Acl\Exceptions\GroupNotDeletable;
use App\Domains\Acl\Queries\GroupCardQuery;
use App\Domains\Acl\Repositories\AclRuleRepository;
use App\Domains\Acl\Repositories\AroGroupRepository;
use App\Domains\Acl\Support\GroupTree;
use App\Http\Requests\Acl\AddGroupUserRequest;
use App\Http\Requests\Acl\AssignGroupRuleRequest;
use App\Http\Requests\Acl\SaveAclRuleRequest;
use App\Http\Requests\Acl\SaveGroupRequest;
use Illuminate\Http\Request;

/**
 * Správa ACL: skupiny (strom), jejich členové a pravidla, MFA povinnost
 * skupiny. Logika v app/Domains/Acl; vyhodnocení práv AclService.
 */
class AroGroupController extends Controller
{
    private const ACL_SECTION = 'Aro_groups_Controller';
    private const ACL_VALUE   = 'aro_group';

    public function __construct(private AroGroupRepository $groups) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    private function toGroup(int $id, string $message)
    {
        return redirect()->route('aro-groups.show', $id)->with('success', $message);
    }

    private function toIndex(string $message)
    {
        return redirect()->route('aro-groups.index')->with('success', $message);
    }

    // ── skupiny ──────────────────────────────────────────────────────────────

    public function index()
    {
        $groups = $this->groups->all();
        return view('aro_groups.index', ['groups' => $groups, 'tree' => GroupTree::build($groups)]);
    }

    public function show(int $id, GroupCardQuery $card)
    {
        abort_unless($this->can('view_all'), 403);
        return view('aro_groups.show', $card->find($id) + ['canEdit' => $this->can('edit_all')]);
    }

    /** MFA fáze B: přepnout, zda skupina vyžaduje dvoufázové přihlášení. */
    public function toggleMfaRequired(Request $request, int $id, SetGroupMfaRequiredAction $setMfa)
    {
        abort_unless($this->can('edit_all'), 403);
        $this->groups->findOrFail($id);

        $required = $request->boolean('required');
        $setMfa->handle($id, $required);

        return $this->toGroup($id, $required
            ? 'Skupina teď vyžaduje dvoufázové přihlášení. Členové budou při dalším přihlášení vyzváni k nastavení.'
            : 'Povinné dvoufázové přihlášení pro skupinu vypnuto.');
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);
        return view('aro_groups.create', ['groups' => $this->groups->forSelect()]);
    }

    public function store(SaveGroupRequest $request, CreateGroupAction $create)
    {
        $create->handle($request->validated('name'), (int) $request->validated('parent_id'));
        return $this->toIndex('Skupina byla vytvořena.');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        return view('aro_groups.edit', ['group' => $this->groups->findOrFail($id), 'groups' => $this->groups->forSelect($id)]);
    }

    public function update(SaveGroupRequest $request, int $id, UpdateGroupAction $update)
    {
        $update->handle($this->groups->findOrFail($id), $request->validated('name'), (int) $request->validated('parent_id'));
        return $this->toGroup($id, 'Skupina byla upravena.');
    }

    public function destroy(int $id, DeleteGroupAction $delete)
    {
        abort_unless($this->can('delete_all'), 403);

        try {
            $delete->handle($this->groups->findOrFail($id));
        } catch (GroupNotDeletable $e) {
            return back()->with('error', $e->getMessage());
        }
        return $this->toIndex('Skupina byla smazána.');
    }

    // ── členové a pravidla skupiny ───────────────────────────────────────────

    public function addUser(AddGroupUserRequest $request, int $id, AddUserToGroupAction $add)
    {
        $add->handle($id, (int) $request->validated('user_id'));
        return $this->toGroup($id, 'Uživatel byl přidán do skupiny.');
    }

    public function removeUser(int $id, int $userId, RemoveUserFromGroupAction $remove)
    {
        abort_unless($this->can('edit_all'), 403);
        $remove->handle($id, $userId);
        return $this->toGroup($id, 'Uživatel byl odebrán ze skupiny.');
    }

    public function addAcl(AssignGroupRuleRequest $request, int $id, AssignRuleToGroupAction $assign)
    {
        $assign->handle($id, (int) $request->validated('acl_id'));
        return $this->toGroup($id, 'ACL pravidlo bylo přiřazeno skupině.');
    }

    public function removeAcl(int $id, int $aclId, UnassignRuleFromGroupAction $unassign)
    {
        abort_unless($this->can('edit_all'), 403);
        $unassign->handle($id, $aclId);
        return $this->toGroup($id, 'ACL pravidlo bylo odebráno skupině.');
    }

    // ── pravidla ─────────────────────────────────────────────────────────────

    private function aclFormData(AclRuleRepository $rules): array
    {
        return [
            'actions' => AclRuleRepository::ACTIONS,
            'axo'     => $rules->objects(),
            'groups'  => $this->groups->forSelect(null, ['id', 'name']),
        ];
    }

    public function aclCreate(AclRuleRepository $rules)
    {
        abort_unless($this->can('new_all'), 403);
        return view('aro_groups.acl_form', $this->aclFormData($rules) + ['acl' => null, 'selected' => []]);
    }

    public function aclStore(SaveAclRuleRequest $request, SaveAclRuleAction $save)
    {
        $v = $request->validated();
        $save->handle(null, $v['note'], $v['actions'], $v['axo_ids'], $v['group_ids'] ?? []);
        return $this->toIndex('ACL pravidlo bylo vytvořeno.');
    }

    public function aclEdit(int $id, AclRuleRepository $rules)
    {
        abort_unless($this->can('edit_all'), 403);

        $acl = $rules->find($id);
        abort_if($acl === null, 404);

        return view('aro_groups.acl_form', $this->aclFormData($rules) + ['acl' => $acl, 'selected' => $rules->selection($id)]);
    }

    public function aclUpdate(SaveAclRuleRequest $request, int $id, SaveAclRuleAction $save)
    {
        $v = $request->validated();
        $save->handle($id, $v['note'], $v['actions'], $v['axo_ids'], $v['group_ids'] ?? []);
        return $this->toIndex('ACL pravidlo bylo upraveno.');
    }

    public function aclDestroy(int $id, DeleteAclRuleAction $delete)
    {
        abort_unless($this->can('delete_all'), 403);
        $delete->handle($id);
        return $this->toIndex('ACL pravidlo bylo smazáno.');
    }
}
