<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\AclService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    private const ACL_SECTION = 'Messages_Controller';
    private const ACL_VALUE   = 'messages';

    public function __construct(private AclService $acl) {}

    private function can(string $action): bool
    {
        return $this->acl->hasAccess(auth()->id(), $action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        abort_unless($this->can('view_all'), 403);

        $systemMessages = Message::where('type', '>', 0)->orderBy('type')->get();
        $userMessages   = Message::where('type', 0)->orderBy('name')->get();

        return view('messages.index', compact('systemMessages', 'userMessages'));
    }

    public function show(int $id)
    {
        abort_unless($this->can('view_all'), 403);

        $message = Message::findOrFail($id);

        $activeIps = DB::table('messages_ip_addresses as mip')
            ->join('ip_addresses as ip', 'ip.id', '=', 'mip.ip_address_id')
            ->leftJoin('users as u', 'u.id', '=', 'mip.user_id')
            ->where('mip.message_id', $id)
            ->select('mip.ip_address_id', 'ip.ip_address', 'u.login as activated_by', 'mip.comment', 'mip.datetime')
            ->orderByDesc('mip.datetime')
            ->get();

        return view('messages.show', compact('message', 'activeIps'));
    }

    public function create()
    {
        abort_unless($this->can('new_all'), 403);
        return view('messages.create');
    }

    public function store(Request $request)
    {
        abort_unless($this->can('new_all'), 403);

        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'text'             => 'nullable|string',
            'email_text'       => 'nullable|string',
            'sms_text'         => 'nullable|string|max:760',
            'self_cancel'      => 'required|integer|in:0,1,2',
            'ignore_whitelist' => 'boolean',
        ]);

        $validated['type']             = Message::USER_MESSAGE;
        $validated['ignore_whitelist'] = $request->boolean('ignore_whitelist') ? 1 : 0;

        Message::create($validated);

        return redirect()->route('messages.index')
            ->with('success', 'Zpráva byla vytvořena.');
    }

    public function edit(int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $message = Message::findOrFail($id);
        return view('messages.edit', compact('message'));
    }

    public function update(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $message = Message::findOrFail($id);

        $validated = $request->validate([
            'name'             => 'required|string|max:100',
            'text'             => 'nullable|string',
            'email_text'       => 'nullable|string',
            'sms_text'         => 'nullable|string|max:760',
            'self_cancel'      => 'required|integer|in:0,1,2',
            'ignore_whitelist' => 'boolean',
        ]);

        $validated['ignore_whitelist'] = $request->boolean('ignore_whitelist') ? 1 : 0;

        $message->update($validated);

        return redirect()->route('messages.show', $id)
            ->with('success', 'Zpráva byla upravena.');
    }

    public function destroy(int $id)
    {
        abort_unless($this->can('delete_all'), 403);

        $message = Message::findOrFail($id);

        if ($message->isSystem()) {
            return back()->with('error', 'Systémové zprávy nelze smazat.');
        }

        DB::table('messages_ip_addresses')->where('message_id', $id)->delete();
        $message->delete();

        return redirect()->route('messages.index')
            ->with('success', 'Zpráva byla smazána.');
    }

    public function activate(Request $request, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        Message::findOrFail($id);

        $validated = $request->validate([
            'ip_address_ids'   => 'required|array|min:1',
            'ip_address_ids.*' => 'integer|exists:ip_addresses,id',
            'comment'          => 'nullable|string|max:255',
        ]);

        foreach ($validated['ip_address_ids'] as $ipId) {
            DB::table('messages_ip_addresses')->insertOrIgnore([
                'message_id'    => $id,
                'ip_address_id' => $ipId,
                'user_id'       => auth()->id(),
                'comment'       => $validated['comment'] ?? '',
                'datetime'      => now(),
            ]);
        }

        return back()->with('success', 'Zpráva byla aktivována pro ' . count($validated['ip_address_ids']) . ' IP adres.');
    }

    public function deactivate(int $id, int $ipAddressId)
    {
        abort_unless($this->can('edit_all'), 403);

        DB::table('messages_ip_addresses')
            ->where('message_id', $id)
            ->where('ip_address_id', $ipAddressId)
            ->delete();

        return back()->with('success', 'Zpráva byla deaktivována.');
    }
}
