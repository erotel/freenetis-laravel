<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SmsMessage;
use Illuminate\Http\Request;

class SmsMessageController extends Controller
{
    const ACL_SECTION = 'Sms_Controller';
    const ACL_KEY     = 'sms';

    public function index(Request $request)
    {
        abort_unless($this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY), 403);

        // Hledání dle tel. čísla: normalizuj na číslice (uživatel může zadat +420…, mezery…)
        $phone = preg_replace('/[^0-9]/', '', (string) $request->input('phone', ''));

        $query = SmsMessage::with('user')->select('sms_messages.*');

        if ($phone !== '') {
            // Prohledej CELOU historii (ne jen posledních 200) v odesílateli i příjemci.
            $query->where(function ($q) use ($phone) {
                $q->where('sms_messages.receiver', 'like', "%{$phone}%")
                  ->orWhere('sms_messages.sender', 'like', "%{$phone}%");
            });
        } else {
            // Výchozí výpis: jen posledních 200 zpráv.
            $last200 = SmsMessage::select('id')->orderByDesc('id')->limit(200);
            $query->joinSub($last200, 'last200', fn($j) => $j->on('sms_messages.id', '=', 'last200.id'));
        }

        $query->orderByDesc('sms_messages.id');

        if ($request->filled('type') && $request->type !== '') {
            $query->where('sms_messages.type', (int) $request->type);
        }
        if ($request->filled('state') && $request->state !== '') {
            $query->where('sms_messages.state', (int) $request->state);
        }

        $items = $query->paginate(50)->withQueryString();

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

        $sms = SmsMessage::with('user')->findOrFail($id);

        // Mark received unread as read
        if ((int) $sms->type === SmsMessage::TYPE_RECEIVED
            && (int) $sms->state === SmsMessage::STATE_RECEIVED_UNREAD) {
            $sms->state = SmsMessage::STATE_RECEIVED_READ;
            $sms->save();
        }

        return view('sms_messages.show', ['sms' => $sms]);
    }

    public function create()
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY), 403);

        return view('sms_messages.create', [
            'senderNumber' => Setting::get('sms_sender_number', ''),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($this->aclCheck('new_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $data = $request->validate([
            'receiver'  => ['required', 'string', 'min:9', 'max:13', 'regex:/^\+?[0-9]+$/'],
            'text'      => ['required', 'string', 'max:760'],
            'send_date' => ['required', 'date'],
        ]);

        $receiver = preg_replace('/\s+/', '', $data['receiver']);
        if (strlen($receiver) === 9) {
            $receiver = '420' . $receiver;
        }

        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $data['text']);

        SmsMessage::create([
            'user_id'        => auth()->id(),
            'sms_message_id' => null,
            'stamp'          => now(),
            'send_date'      => $data['send_date'],
            'text'           => $text,
            'sender'         => Setting::get('sms_sender_number', ''),
            'receiver'       => $receiver,
            'driver'         => (int) Setting::get('sms_driver', 3),
            'type'           => SmsMessage::TYPE_SENT,
            'state'          => SmsMessage::STATE_SENT_UNSENT,
            'message'        => null,
        ]);

        return redirect()->route('sms_messages.index')
            ->with('success', 'SMS zpráva byla přidána do fronty.');
    }

    public function destroyUnsent()
    {
        abort_unless($this->aclCheck('delete_all', self::ACL_SECTION, self::ACL_KEY), 403);

        SmsMessage::where('type', SmsMessage::TYPE_SENT)
            ->where('state', SmsMessage::STATE_SENT_UNSENT)
            ->delete();

        return redirect()->route('sms_messages.index')
            ->with('success', 'Neodeslané SMS byly smazány.');
    }

}
