<?php
namespace App\Http\Controllers;

use App\Domains\Notifications\Repositories\ActivationRuleRepository;
use App\Domains\Notifications\Repositories\MessageRepository;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/** Pravidla automatické aktivace upozornění (dlužník / upomínka); data v doméně Notifications. */
class MessageAutoSettingController extends Controller
{
    /** Automaticky aktivovat jde jen dlužník a upomínka (zákazník 5, 6; člen 25, 26). */
    private const AUTO_TYPES = [5, 6, 25, 26];

    private const RULES = [
        'type'                     => 'required|integer|in:1,2,3,4,5,6',
        'attribute'                => 'nullable|string|max:20',
        'redirection_enabled'      => 'boolean',
        'email_enabled'            => 'boolean',
        'sms_enabled'              => 'boolean',
        'send_activation_to_email' => 'nullable|email|max:255',
    ];

    public function __construct(private ActivationRuleRepository $rules, private MessageRepository $messages) {}

    /** Hodnoty pravidla z validovaného formuláře. */
    private function values(Request $request, array $v): array
    {
        return [
            'type'                     => $v['type'],
            'attribute'                => $v['attribute'] ?? '',
            'redirection_enabled'      => $request->boolean('redirection_enabled') ? 1 : 0,
            'email_enabled'            => $request->boolean('email_enabled') ? 1 : 0,
            'sms_enabled'              => $request->boolean('sms_enabled') ? 1 : 0,
            'send_activation_to_email' => $v['send_activation_to_email'] ?? '',
        ];
    }

    private const ACL_SECTION = 'Messages_Controller';
    private const ACL_VALUE   = 'message';

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
        return view('message_auto_settings.index', [
            'message'    => $this->messages->findOrFail($messageId),
            'rules'      => $this->rules->forMessage($messageId),
            'typeLabels' => self::TYPE_LABELS,
        ]);
    }

    public function create(int $messageId)
    {
        abort_unless($this->can('new_all'), 403);
        $message = $this->messages->findOrFail($messageId);
        abort_unless(in_array($message->type, self::AUTO_TYPES), 403,
            'Tento typ zprávy nelze automaticky aktivovat.');
        return view('message_auto_settings.create', [
            'message'    => $message,
            'typeLabels' => self::TYPE_LABELS,
        ]);
    }

    public function store(Request $request, int $messageId)
    {
        abort_unless($this->can('new_all'), 403);
        $message = $this->messages->findOrFail($messageId);
        abort_unless(in_array($message->type, self::AUTO_TYPES), 403);

        $validated = $request->validate(self::RULES);
        $this->rules->create($messageId, $this->values($request, $validated));

        AuditLogger::log('created', 'messages_automatical_activations', $messageId, null, [
            'message_id' => $messageId,
            'type'       => $validated['type'],
            'attribute'  => $validated['attribute'] ?? '',
        ]);

        return redirect()->route('message-auto-settings.index', $messageId)
            ->with('success', 'Pravidlo automatické aktivace bylo přidáno.');
    }

    public function edit(int $messageId, int $id)
    {
        abort_unless($this->can('edit_all'), 403);
        $message = $this->messages->findOrFail($messageId);
        $rule    = $this->rules->find($messageId, $id);
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

        $validated = $request->validate(self::RULES);
        $this->rules->update($messageId, $id, $this->values($request, $validated));

        AuditLogger::log('updated', 'messages_automatical_activations', $id, null, [
            'message_id' => $messageId,
            'type'       => $validated['type'],
            'attribute'  => $validated['attribute'] ?? '',
        ]);

        return redirect()->route('message-auto-settings.index', $messageId)
            ->with('success', 'Pravidlo bylo upraveno.');
    }

    public function destroy(int $messageId, int $id)
    {
        abort_unless($this->can('delete_all'), 403);
        $this->rules->delete($messageId, $id);
        AuditLogger::log('deleted', 'messages_automatical_activations', $id, [
            'message_id' => $messageId,
        ], null);
        return redirect()->route('messages.show', $messageId)
            ->with('success', 'Pravidlo bylo smazáno.');
    }
}
