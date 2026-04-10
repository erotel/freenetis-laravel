<?php
namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MessageAutoSettingController extends Controller
{
    private const ACL_SECTION = 'Messages_Controller';
    private const ACL_VALUE   = 'messages';

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public const TYPE_LABELS = [
        1 => 'Měsíčně (DD/H)',
        2 => 'Týdně (D/H)',
        3 => 'Denně (/H)',
        4 => 'Denně pracovní (/H)',
        5 => 'Každou hodinu',
        6 => 'Po stržení (/H)',
    ];

    public function index(int $messageId)
    {
        abort_unless($this->can('view_all'), 403);
        $message    = Message::findOrFail($messageId);
        $rules      = DB::table('messages_automatical_activations')
            ->where('message_id', $messageId)->get();
        $typeLabels = self::TYPE_LABELS;
        return view('message_auto_settings.index', compact('message', 'rules', 'typeLabels'));
    }

    public function create(int $messageId)
    {
        abort_unless($this->can('new_all'), 403);
        $message = Message::findOrFail($messageId);
        abort_unless(in_array($message->type, [5, 6, 25, 26]), 403,
            'Tento typ zprávy nelze automaticky aktivovat.');
        return view('message_auto_settings.create', [
            'message'    => $message,
            'typeLabels' => self::TYPE_LABELS,
        ]);
    }

    public function store(Request $request, int $messageId)
    {
        abort_unless($this->can('new_all'), 403);
        $message = Message::findOrFail($messageId);
        abort_unless(in_array($message->type, [5, 6, 25, 26]), 403);

        $validated = $request->validate([
            'type'                     => 'required|integer|in:1,2,3,4,5,6',
            'attribute'                => 'nullable|string|max:20',
            'redirection_enabled'      => 'boolean',
            'email_enabled'            => 'boolean',
            'sms_enabled'              => 'boolean',
            'send_activation_to_email' => 'nullable|email|max:255',
        ]);

        DB::table('messages_automatical_activations')->insert([
            'message_id'               => $messageId,
            'type'                     => $validated['type'],
            'attribute'                => $validated['attribute'] ?? '',
            'redirection_enabled'      => $request->boolean('redirection_enabled') ? 1 : 0,
            'email_enabled'            => $request->boolean('email_enabled') ? 1 : 0,
            'sms_enabled'              => $request->boolean('sms_enabled') ? 1 : 0,
            'send_activation_to_email' => $validated['send_activation_to_email'] ?? '',
        ]);

        return redirect()->route('message-auto-settings.index', $messageId)
            ->with('success', 'Pravidlo automatické aktivace bylo přidáno.');
    }

    public function edit(int $messageId, int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $message = Message::findOrFail($messageId);
        $rule    = DB::table('messages_automatical_activations')
            ->where('id', $id)->where('message_id', $messageId)
            ->first();
        abort_if(!$rule, 404);
        return view('message_auto_settings.edit', [
            'message'    => $message,
            'rule'       => $rule,
            'typeLabels' => self::TYPE_LABELS,
        ]);
    }

    public function update(Request $request, int $messageId, int $id)
    {
        abort_unless($this->can('edit_all'), 403);

        $validated = $request->validate([
            'type'                     => 'required|integer|in:1,2,3,4,5,6',
            'attribute'                => 'nullable|string|max:20',
            'redirection_enabled'      => 'boolean',
            'email_enabled'            => 'boolean',
            'sms_enabled'              => 'boolean',
            'send_activation_to_email' => 'nullable|email|max:255',
        ]);

        DB::table('messages_automatical_activations')
            ->where('id', $id)->where('message_id', $messageId)
            ->update([
                'type'                     => $validated['type'],
                'attribute'                => $validated['attribute'] ?? '',
                'redirection_enabled'      => $request->boolean('redirection_enabled') ? 1 : 0,
                'email_enabled'            => $request->boolean('email_enabled') ? 1 : 0,
                'sms_enabled'              => $request->boolean('sms_enabled') ? 1 : 0,
                'send_activation_to_email' => $validated['send_activation_to_email'] ?? '',
            ]);

        return redirect()->route('message-auto-settings.index', $messageId)
            ->with('success', 'Pravidlo bylo upraveno.');
    }

    public function destroy(int $messageId, int $id)
    {
        abort_unless($this->can('delete_all'), 403);
        DB::table('messages_automatical_activations')
            ->where('id', $id)->where('message_id', $messageId)
            ->delete();
        return redirect()->route('messages.show', $messageId)
            ->with('success', 'Pravidlo bylo smazáno.');
    }
}
