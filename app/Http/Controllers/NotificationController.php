<?php

namespace App\Http\Controllers;

use App\Domains\Notifications\Actions\NotifyMemberAction;
use App\Domains\Notifications\Actions\NotifyMembersAction;
use App\Domains\Notifications\Queries\NotificationMembersQuery;
use App\Domains\Members\Repositories\MemberRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Upozornění členů (jednotlivě z karty člena, hromadně). Logika v app/Domains/Notifications. */
class NotificationController extends Controller
{
    const ACTIVATE   = NotifyMemberAction::ACTIVATE;
    const KEEP       = NotifyMemberAction::KEEP;
    const DEACTIVATE = NotifyMemberAction::DEACTIVATE;

    private function can(string $action = 'new_all'): bool
    {
        return $this->aclCheck($action, 'Notifications_Controller', 'member');
    }

    /** Krok 1 hromadného upozornění: výběr zprávy. */
    public function membersSelect(NotificationMembersQuery $query)
    {
        abort_unless($this->can(), 403);

        return view('notifications.members_select', ['messages' => $query->messages()]);
    }

    /** Formulář hromadného upozornění pro všechny členy (kromě bývalých). */
    public function members(int $messageId, NotificationMembersQuery $query)
    {
        abort_unless($this->can(), 403);

        $message = $query->message($messageId);
        abort_if(!$message, 404);

        return view('notifications.members', [
            'message'    => $message,
            'members'    => $query->forMessage($messageId),
            'ACTIVATE'   => self::ACTIVATE,
            'KEEP'       => self::KEEP,
            'DEACTIVATE' => self::DEACTIVATE,
        ]);
    }

    /**
     * Hromadné upozornění. Akce chodí v jediném JSON inputu `bulk_payload` —
     * kvůli PHP limitu max_input_vars=1000 (2000 členů × 3 pole by se oříznulo).
     */
    public function membersNotify(Request $request, int $messageId, NotificationMembersQuery $query, NotifyMembersAction $notify)
    {
        abort_unless($this->can(), 403);

        $message = $query->message($messageId);
        abort_if(!$message, 404);

        $payload = json_decode((string) $request->input('bulk_payload', '{}'), true) ?: [];

        $result = $notify->handle(
            $message,
            trim((string) $request->input('comment', '')),
            $payload['redirection'] ?? [],
            array_keys(array_fill_keys($payload['email'] ?? [], true)),
            array_keys(array_fill_keys($payload['sms'] ?? [], true)),
            Auth::id(),
        );

        return redirect()->route('notifications.members', $messageId)->with('success', $result->summary());
    }

    /** Formulář upozornění jednoho člena (zprávy typu 0 — uživatelské). */
    public function member(int $id, NotificationMembersQuery $query)
    {
        abort_unless($this->can(), 403);

        $member = app(MemberRepository::class)->row($id);
        abort_if(!$member, 404);

        return view('notifications.member', [
            'member'     => $member,
            'messages'   => $query->messages(0),
            'ACTIVATE'   => self::ACTIVATE,
            'KEEP'       => self::KEEP,
            'DEACTIVATE' => self::DEACTIVATE,
        ]);
    }

    public function notify(Request $request, int $id, NotificationMembersQuery $query, NotifyMemberAction $notify)
    {
        abort_unless($this->can(), 403);

        abort_if(!app(MemberRepository::class)->row($id), 404);

        $message = $query->message((int) $request->input('message_id', 0));
        if (!$message) {
            return back()->withInput()->withErrors(['message_id' => 'Vyberte zprávu.']);
        }

        $result = $notify->handle(
            $id,
            $message,
            trim((string) $request->input('comment', '')),
            (int) $request->input('redirection', self::KEEP),
            (int) $request->input('email', self::KEEP),
            (int) $request->input('sms', self::KEEP),
            Auth::id(),
        );

        return redirect()->route('members.show', $id)->with('success', $result->summary());
    }
}
