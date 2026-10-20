<?php

namespace App\Http\Controllers\Members;

use App\Domains\Members\Repositories\MemberRepository;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Samoobsluha: kterými kanály chce člen dostávat oznámení. */
class MyNotificationsController extends Controller
{
    /**
     * Samoobslužná stránka — uživatel si sám spravuje, kterými kanály chce
     * dostávat oznámení. Vyžaduje jen auth, žádné ACL.
     */
    public function edit()
    {
        $memberId = (int) (auth()->user()->member_id ?? 0);
        $member   = $memberId ? app(MemberRepository::class)->find($memberId) : null;
        abort_if(!$member, 404, 'Účet není navázán na člena.');

        return view('account.notifications', ['member' => $member]);
    }

    public function update(Request $request)
    {
        $memberId = (int) (auth()->user()->member_id ?? 0);
        $member   = $memberId ? app(MemberRepository::class)->find($memberId) : null;
        abort_if(!$member, 404, 'Účet není navázán na člena.');

        app(MemberRepository::class)->setNotificationChannels($member,
            $request->boolean('notification_by_redirection'),
            $request->boolean('notification_by_email'),
            $request->boolean('notification_by_sms'),
        );

        return redirect()->route('me.notifications')
            ->with('success', 'Nastavení oznámení bylo uloženo.');
    }
}
