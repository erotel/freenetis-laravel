<?php

namespace App\Http\Controllers;

use App\Domains\Members\Actions\CreateMembershipInterruptAction;
use App\Domains\Members\Actions\DeleteMembershipInterruptAction;
use App\Domains\Members\Actions\UpdateMembershipInterruptAction;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Repositories\MembershipInterruptRepository;
use App\Http\Requests\Members\SaveMembershipInterruptRequest;

/** Přerušení členství (poplatek special_type 1 na interval); logika v doméně Members. */
class MembershipInterruptController extends Controller
{
    public function __construct(
        private MemberRepository $members,
        private MembershipInterruptRepository $interrupts,
    ) {
    }

    private function can(string $action): bool
    {
        return $this->aclCheck($action, 'Members_Controller', 'membership_interrupts');
    }

    private function memberOrFail(int $memberId): object
    {
        $member = $this->members->row($memberId);
        abort_if(!$member, 404);
        return $member;
    }

    private function interruptOrFail(int $id): object
    {
        $interrupt = $this->interrupts->find($id);
        abort_if(!$interrupt, 404);
        return $interrupt;
    }

    public function create(int $memberId)
    {
        abort_unless($this->can('new_all'), 403);
        return view('membership_interrupts.form', ['action' => 'create', 'member' => $this->memberOrFail($memberId), 'record' => null]);
    }

    public function store(SaveMembershipInterruptRequest $request, int $memberId, CreateMembershipInterruptAction $create)
    {
        $create->handle($memberId, $request->from(), $request->to(), $request->comment(), $request->boolean('end_after_interrupt_end'));

        return redirect()->route('members.show', $memberId)->with('success', 'Přerušení členství bylo přidáno.');
    }

    public function edit(int $id)
    {
        $interrupt = $this->interruptOrFail($id);
        abort_unless($this->can('edit_all'), 403);

        return view('membership_interrupts.form', ['action' => 'edit', 'member' => $this->memberOrFail((int) $interrupt->member_id), 'record' => $interrupt]);
    }

    public function update(SaveMembershipInterruptRequest $request, int $id, UpdateMembershipInterruptAction $update)
    {
        $interrupt = $request->interrupt();
        $update->handle($interrupt, $request->from(), $request->to(), $request->comment(), $request->boolean('end_after_interrupt_end'));

        return redirect()->route('members.show', $interrupt->member_id)->with('success', 'Přerušení členství bylo upraveno.');
    }

    public function destroy(int $id, DeleteMembershipInterruptAction $delete)
    {
        $interrupt = $this->interruptOrFail($id);
        abort_unless($this->can('delete_all'), 403);

        $delete->handle($interrupt);

        return redirect()->route('members.show', $interrupt->member_id)->with('success', 'Přerušení členství bylo smazáno.');
    }
}
