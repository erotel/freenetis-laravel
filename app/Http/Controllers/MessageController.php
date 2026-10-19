<?php

namespace App\Http\Controllers;

use App\Domains\Notifications\Repositories\MessageRepository;
use App\Domains\Notifications\Repositories\RedirectionRepository;
use App\Models\Message;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** Šablony zpráv (přesměrování, e-mail, SMS); data v doméně Notifications. */
class MessageController extends Controller
{
    private const RULES = [
        'name'             => 'required|string|max:100',
        'text'             => 'nullable|string',
        'email_text'       => 'nullable|string',
        'sms_text'         => 'nullable|string|max:760',
        'self_cancel'      => 'required|integer|in:0,1,2',
        'ignore_whitelist' => 'boolean',
    ];

    public function __construct(private MessageRepository $messages, private RedirectionRepository $redirects) {}

    private const ACL_SECTION = 'Messages_Controller';
    private const ACL_VALUE   = 'message';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        return view('messages.index', ['systemMessages' => $this->messages->systemMessages(), 'userMessages' => $this->messages->userMessages()]);
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        return view('messages.show', ['message' => $this->messages->findOrFail($id), 'activeIps' => $this->messages->activeIps($id)]);
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);
        return view('messages.create');
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $validated = $request->validate(self::RULES);

        $validated['type']             = Message::USER_MESSAGE;
        $validated['ignore_whitelist'] = $request->boolean('ignore_whitelist') ? 1 : 0;

        $this->messages->create($validated);

        return redirect()->route('messages.index')
            ->with('success', 'Zpráva byla vytvořena.');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        return view('messages.edit', ['message' => $this->messages->findOrFail($id)]);
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $message = $this->messages->findOrFail($id);

        $validated = $request->validate(self::RULES);

        $validated['ignore_whitelist'] = $request->boolean('ignore_whitelist') ? 1 : 0;

        $this->messages->update($message, $validated);

        return redirect()->route('messages.show', $id)
            ->with('success', 'Zpráva byla upravena.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $message = $this->messages->findOrFail($id);
        if ($message->isSystem()) {
            return back()->with('error', 'Systémové zprávy nelze smazat.');
        }
        $this->messages->delete($message);

        return redirect()->route('messages.index')
            ->with('success', 'Zpráva byla smazána.');
    }

    public function activate(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $this->messages->findOrFail($id);

        $validated = $request->validate([
            'ip_address_ids'   => 'required|array|min:1',
            'ip_address_ids.*' => 'integer|exists:ip_addresses,id',
            'comment'          => 'nullable|string|max:255',
        ]);

        $this->redirects->addAuto($id, $validated['ip_address_ids'], (int) auth()->id(), $validated['comment'] ?? '');

        AuditLogger::log('created', 'messages_ip_addresses', $id, null, [
            'message_id' => $id,
            'ip_count'   => count($validated['ip_address_ids']),
        ]);

        return back()->with('success', 'Zpráva byla aktivována pro ' . count($validated['ip_address_ids']) . ' IP adres.');
    }

    public function deactivate(int $id, int $ipAddressId)
    {
        abort_unless($this->can('edit_all'), 403);

        $this->redirects->remove($id, [$ipAddressId]);

        AuditLogger::log('deleted', 'messages_ip_addresses', $ipAddressId, [
            'message_id' => $id,
        ], null);

        return back()->with('success', 'Zpráva byla deaktivována.');
    }
}
