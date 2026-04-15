<?php

namespace App\Http\Controllers;

use App\Models\SmsMessage;
use Illuminate\Http\Request;

class SmsMessageController extends Controller
{
    const ACL_SECTION = 'Sms_Controller';
    const ACL_KEY     = 'sms';

    public function index(Request $request)
    {
        abort_unless($this->aclCheck('view_all', self::ACL_SECTION, self::ACL_KEY), 403);

        $query = SmsMessage::with('user')->orderByDesc('id');

        if ($request->filled('type') && $request->type !== '') {
            $query->where('type', (int) $request->type);
        }
        if ($request->filled('state') && $request->state !== '') {
            $query->where('state', (int) $request->state);
        }

        $items = $query->paginate(50)->withQueryString();

        return view('sms_messages.index', [
            'items'       => $items,
            'filterType'  => $request->input('type', ''),
            'filterState' => $request->input('state', ''),
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
