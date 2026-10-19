<?php

namespace App\Http\Controllers;

use App\Domains\Notifications\Actions\QueueSmsAction;
use App\Domains\Notifications\Repositories\SmsRepository;
use App\Models\Setting;
use Illuminate\Http\Request;

class SmsMessageController extends Controller
{
    const ACL_SECTION = 'Sms_Controller';
    const ACL_KEY     = 'sms';

    public function __construct(private SmsRepository $sms)
    {
    }

    public function index(Request $request)
    {
        abort_unless($this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY), 403);

        // Hledání dle tel. čísla: normalizuj na číslice (uživatel může zadat +420…, mezery…)
        $phone = preg_replace('/[^0-9]/', '', (string) $request->input('phone', ''));

        $items = $this->sms->search(
            $phone,
            $request->filled('type') && $request->type !== '' ? (int) $request->type : null,
            $request->filled('state') && $request->state !== '' ? (int) $request->state : null,
            50
        );

        return view('sms_messages.index', [
            'items'       => $items,
            'filterType'  => $request->input('type', ''),
            'filterState' => $request->input('state', ''),
            'filterPhone' => $phone,
            'canSend'     => $this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY),
            'canDelete'   => $this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY),
        ]);
    }

    public function show(int $id)
    {
        abort_unless($this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $sms = $this->sms->findWithUserOrFail($id);
        $this->sms->markRead($sms);

        return view('sms_messages.show', ['sms' => $sms]);
    }

    public function create()
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY), 403);

        return view('sms_messages.create', [
            'senderNumber' => Setting::get('sms_sender_number', ''),
        ]);
    }

    public function store(Request $request, QueueSmsAction $queue)
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $data = $request->validate([
            'receiver'  => ['required', 'string', 'min:9', 'max:13', 'regex:/^\+?[0-9]+$/'],
            'text'      => ['required', 'string', 'max:760'],
            'send_date' => ['required', 'date'],
        ]);

        $queue->handle((int) auth()->id(), $data['receiver'], $data['text'], $data['send_date']);

        return redirect()->route('sms_messages.index')
            ->with('success', 'SMS zpráva byla přidána do fronty.');
    }

    public function destroyUnsent()
    {
        abort_unless($this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $this->sms->deleteUnsent();

        return redirect()->route('sms_messages.index')
            ->with('success', 'Neodeslané SMS byly smazány.');
    }
}
