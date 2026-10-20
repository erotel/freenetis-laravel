<?php

namespace Tests\Characterization\Meetings;

use App\Domains\Acl\AclService;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * Charakterizace členských schůzí (MeetingController) před přesunem do
 * domény: založení, karta (senioři, přítomnost, plné moci, kvórum, série
 * absencí), zahájení, check-in, plná moc, uzavření (snapshot absencí),
 * degradace (3× po sobě nepřítomen → can_vote 0 + log), prezenční CSV, ACL.
 * Pravidlo série absencí samo: Feature/Meetings/MeetingPresenceTest.
 */
class MeetingsTest extends DatabaseTestCase
{
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = (int) DB::table('users')->where('type', 1)->value('id');
        $this->acl();
    }

    private function acl(array $deny = []): void
    {
        $acl = $this->mock(AclService::class);
        $acl->shouldReceive('hasAccess')->andReturnUsing(fn ($u, $aco, $section, $value) => !in_array("{$aco}/{$section}/{$value}", $deny, true));
        $acl->shouldReceive('flushUserCache')->andReturnNull();
    }

    private function as()
    {
        return $this->actingAs(User::find($this->adminId));
    }

    private function senior(string $name, int $canVote = 1): int
    {
        return (int) DB::table('members')->insertGetId(['name' => $name, 'type' => 90, 'registration' => 1, 'locked' => 0, 'can_vote' => $canVote,
            'address_point_id' => (int) DB::table('members')->whereNotNull('address_point_id')->value('address_point_id'),
            'entrance_date' => '2020-01-01', 'leaving_date' => '9999-12-31']);
    }

    private function meeting(string $status = Meeting::STATUS_OPEN, string $date = '2099-06-01', string $type = 'clenska_schuze'): Meeting
    {
        return Meeting::create(['title' => 'Char schůze', 'held_on' => $date, 'type' => $type, 'status' => $status, 'quorum_percent' => 50]);
    }

    private function att(int $meeting, int $member): ?MeetingAttendance
    {
        return MeetingAttendance::where('meeting_id', $meeting)->where('member_id', $member)->first();
    }

    /** @return array<int, array> řádky karty podle member_id */
    private function rows(int $meeting): array
    {
        return collect($this->as()->get(route('meetings.show', $meeting))->assertOk()->viewData('rows'))
            ->keyBy(fn ($r) => (int) $r['member']->id)->all();
    }

    // ── seznam, založení ─────────────────────────────────────────────────────

    public function test_seznam_a_zalozeni(): void
    {
        $res = $this->as()->get(route('meetings.index'))->assertOk()->assertViewIs('meetings.index');
        $this->assertSame(DB::table('members')->where('can_vote', 1)->count(), $res->viewData('seniorCount'));
        $this->assertTrue($res->viewData('canNew'));
        $this->as()->get(route('meetings.create'))->assertOk()->assertViewHas('typeLabels', Meeting::typeLabels());

        $this->as()->post(route('meetings.store'), ['title' => 'Char VH', 'held_on' => '2099-05-01', 'type' => 'mimoradna', 'quorum_percent' => 60, 'comment' => 'c'])
            ->assertSessionHas('success', 'Schůze byla založena.');
        $m = Meeting::where('title', 'Char VH')->firstOrFail();
        $this->assertSame(['planned', 'mimoradna', 60, '2099-05-01', 'c'], [$m->status, $m->type, $m->quorum_percent, $m->held_on->toDateString(), $m->comment]);
        $res2 = $this->as()->post(route('meetings.store'), ['title' => 'Char 2', 'held_on' => '2099-05-02', 'type' => 'nahradni', 'quorum_percent' => 1]);
        $res2->assertRedirect(route('meetings.show', (int) Meeting::where('title', 'Char 2')->value('id')));
        $this->as()->post(route('meetings.store'), ['title' => '', 'held_on' => 'x', 'type' => 'jina', 'quorum_percent' => 0])
            ->assertSessionHasErrors(['title', 'held_on', 'type', 'quorum_percent']);

        $ids = $this->as()->get(route('meetings.index'))->viewData('meetings')->pluck('id')->all();
        $this->assertSame(Meeting::orderByDesc('held_on')->orderByDesc('id')->limit(50)->pluck('id')->all(), $ids);
    }

    // ── karta, kvórum ────────────────────────────────────────────────────────

    public function test_karta_pritomnost_plne_moci_kvorum(): void
    {
        DB::table('members')->where('can_vote', 1)->update(['can_vote' => 0]);   // jen naši senioři
        $a = $this->senior('Char A');
        $b = $this->senior('Char B');
        $c = $this->senior('Char C');
        $d = $this->senior('Char D');
        $m = $this->meeting();

        $this->as()->from(route('meetings.show', $m->id))->post(route('meetings.checkin', [$m->id, $a]))->assertRedirect(route('meetings.show', $m->id));
        $this->assertNotNull($this->att($m->id, $a)->checked_in_at);
        $this->as()->post(route('meetings.proxy', [$m->id, $b]), ['proxy_holder_id' => $a]);
        $this->assertSame([null, $a], [$this->att($m->id, $b)->checked_in_at, (int) $this->att($m->id, $b)->proxy_holder_id]);

        $res = $this->as()->get(route('meetings.show', $m->id))->assertOk();
        $this->assertSame([1, 1, 2, 4, 2, true], [$res->viewData('present'), $res->viewData('proxied'), $res->viewData('counted'),
            $res->viewData('total'), $res->viewData('quorumNeed'), $res->viewData('quorumMet')]);
        $this->assertSame([], $res->viewData('degradeCandidates'), 'jen u uzavřené');
        $this->assertTrue($res->viewData('canEdit'));
        $rows = $this->rows($m->id);
        $this->assertSame(['Char A', 'Char B', 'Char C', 'Char D'], array_values(array_map(fn ($r) => $r['member']->name, $rows)), 'podle jména');
        $this->assertSame([true, false, null, 1], [$rows[$a]['present'], $rows[$a]['proxied'], $rows[$a]['proxy_holder'], $rows[$a]['proxy_load']]);
        $this->assertSame([false, true, $a, 0], [$rows[$b]['present'], $rows[$b]['proxied'], (int) $rows[$b]['proxy_holder'], $rows[$b]['proxy_load']]);
        $this->assertSame([false, false, 0, false], [$rows[$c]['present'], $rows[$c]['proxied'], $rows[$c]['prior_absences'], $rows[$c]['at_risk']]);

        // check-in zastoupeného ruší plnou moc; druhý check-in zruší přítomnost
        $this->as()->post(route('meetings.checkin', [$m->id, $b]));
        $this->assertSame(null, $this->att($m->id, $b)->proxy_holder_id);
        $this->as()->post(route('meetings.checkin', [$m->id, $b]));
        $this->assertNull($this->att($m->id, $b)->checked_in_at);
        $res = $this->as()->get(route('meetings.show', $m->id));
        $this->assertSame([1, 0, false], [$res->viewData('counted'), $res->viewData('proxied'), $res->viewData('quorumMet')]);

        $this->as()->get(route('meetings.show', 999999999))->assertNotFound();
    }

    public function test_plna_moc_pravidla(): void
    {
        $a = $this->senior('Char A');
        $b = $this->senior('Char B');
        $non = $this->senior('Char N', 0);
        $m = $this->meeting();
        $from = fn () => $this->as()->from(route('meetings.show', $m->id));

        $from()->post(route('meetings.proxy', [$m->id, $b]), ['proxy_holder_id' => $b])->assertSessionHasErrors(['proxy' => 'Člen nemůže zastupovat sám sebe.']);
        $from()->post(route('meetings.proxy', [$m->id, $b]), ['proxy_holder_id' => $a])->assertSessionHasErrors(['proxy' => 'Zmocněnec musí být fyzicky přítomen.']);
        $this->assertNull($this->att($m->id, $b));
        $from()->post(route('meetings.proxy', [$m->id, $non]), ['proxy_holder_id' => $a])->assertStatus(422);
        $from()->post(route('meetings.checkin', [$m->id, $non]))->assertStatus(422);

        $this->as()->post(route('meetings.checkin', [$m->id, $a]));
        $this->as()->post(route('meetings.proxy', [$m->id, $b]), ['proxy_holder_id' => $a])->assertSessionHasNoErrors();
        $this->as()->post(route('meetings.proxy', [$m->id, $b]), ['proxy_holder_id' => '']);
        $this->assertNull($this->att($m->id, $b)->proxy_holder_id, 'prázdný zmocněnec = zrušit plnou moc');

        $closed = $this->meeting(Meeting::STATUS_CLOSED);
        $this->as()->from(route('meetings.show', $closed->id))->post(route('meetings.checkin', [$closed->id, $a]))->assertSessionHasErrors(['meeting' => 'Schůze je uzavřená.']);
        $this->as()->from(route('meetings.show', $closed->id))->post(route('meetings.proxy', [$closed->id, $b]), ['proxy_holder_id' => $a])->assertSessionHasErrors(['meeting' => 'Schůze je uzavřená.']);
        $this->as()->post(route('meetings.checkin', [999999999, $a]))->assertNotFound();
    }

    // ── průběh: zahájení, uzavření, degradace ────────────────────────────────

    public function test_zahajeni_uzavreni_degradace(): void
    {
        $x = $this->senior('Char X');
        $y = $this->senior('Char Y');
        $old = [];
        foreach (['2098-01-01', '2098-02-01'] as $d) {     // dvě předchozí absence X
            $old[] = $mm = $this->meeting(Meeting::STATUS_CLOSED, $d);
            MeetingAttendance::create(['meeting_id' => $mm->id, 'member_id' => $x]);
            MeetingAttendance::create(['meeting_id' => $mm->id, 'member_id' => $y, 'checked_in_at' => now()]);
        }
        $m = $this->meeting(Meeting::STATUS_PLANNED, '2099-01-01');

        $this->as()->post(route('meetings.open', $m->id))->assertRedirect(route('meetings.show', $m->id));
        $this->assertSame('open', $m->fresh()->status);
        $this->as()->post(route('meetings.open', $m->id));
        $this->assertSame('open', $m->fresh()->status);

        $rows = $this->rows($m->id);
        $this->assertSame([2, true, 0, false], [$rows[$x]['prior_absences'], $rows[$x]['at_risk'], $rows[$y]['prior_absences'], $rows[$y]['at_risk']]);

        $this->as()->post(route('meetings.checkin', [$m->id, $y]));
        $this->as()->post(route('meetings.close', $m->id))->assertRedirect(route('meetings.show', $m->id))
            ->assertSessionHas('success', 'Schůze uzavřena. Zkontroluj případné kandidáty na degradaci níže.');
        $this->assertSame('closed', $m->fresh()->status);
        $snap = $this->att($m->id, $x);
        $this->assertSame([null, null], [$snap->checked_in_at, $snap->proxy_holder_id], 'absent dostal prázdný řádek');
        $this->assertSame(DB::table('members')->where('can_vote', 1)->count(), MeetingAttendance::where('meeting_id', $m->id)->count(), 'snapshot všech seniorů');

        $res = $this->as()->get(route('meetings.show', $m->id));
        $this->assertSame([$x], collect($res->viewData('degradeCandidates'))->pluck('id')->map(fn ($i) => (int) $i)->all());

        $this->as()->post(route('meetings.degrade', $m->id), ['member_ids' => [$x, $y, 0, 'abc']])
            ->assertRedirect(route('meetings.show', $m->id))->assertSessionHas('success', 'Degradováno členů: 1.');
        $this->assertSame([0, 1], [(int) DB::table('members')->where('id', $x)->value('can_vote'), (int) DB::table('members')->where('id', $y)->value('can_vote')]);
        $log = DB::table('member_voting_logs')->where('member_id', $x)->orderByDesc('id')->first();
        $this->assertSame(['degraded', $m->id, '3× po sobě nepřítomen na členské schůzi', $this->adminId], [$log->action, (int) $log->meeting_id, $log->reason, (int) $log->user_id]);
        $this->as()->post(route('meetings.degrade', $m->id), ['member_ids' => [$x]])->assertSessionHas('success', 'Degradováno členů: 0.');

        // uzavřená schůze: senioři = snapshot (nový senior se zpětně neobjeví)
        $z = $this->senior('Char Z');
        $this->assertArrayNotHasKey($z, $this->rows($m->id));

        // opakované uzavření nepřipíše novému seniorovi zpětnou absenci
        $this->as()->post(route('meetings.close', $m->id))->assertRedirect(route('meetings.show', $m->id));
        $this->assertNull($this->att($m->id, $z));
    }

    public function test_jen_clenske_schuze_se_pocitaji(): void
    {
        $x = $this->senior('Char X');
        foreach (['2098-01-01', '2098-02-01', '2098-03-01'] as $d) {
            $mm = $this->meeting(Meeting::STATUS_CLOSED, $d, 'mimoradna');
            MeetingAttendance::create(['meeting_id' => $mm->id, 'member_id' => $x]);
        }
        $this->assertSame(0, $this->rows($this->meeting()->id)[$x]['prior_absences']);
    }

    // ── export ───────────────────────────────────────────────────────────────

    public function test_prezencni_listina_csv(): void
    {
        DB::table('members')->where('can_vote', 1)->update(['can_vote' => 0]);
        $a = $this->senior('Char A');
        $b = $this->senior('Char B');
        $this->senior('Char C');
        $m = $this->meeting(Meeting::STATUS_OPEN, '2099-06-01');
        $this->as()->post(route('meetings.checkin', [$m->id, $a]));
        $this->as()->post(route('meetings.proxy', [$m->id, $b]), ['proxy_holder_id' => $a]);
        $at = $this->att($m->id, $a)->checked_in_at->format('d.m.Y H:i');

        $res = $this->as()->get(route('meetings.presence-export', $m->id))->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('prezence_2099-06-01_' . $m->id . '.csv', $res->headers->get('Content-Disposition'));
        $this->assertSame("\xEF\xBB\xBF" . "Člen;Stav;Zmocněnec;Příchod;Podpis\n" .
            "\"Char A\";Přítomen;;\"{$at}\";\n" . "\"Char B\";Zastoupen;\"Char A\";;\n" . "\"Char C\";Nepřítomen;;;\n", $res->streamedContent());
    }

    // ── ACL ──────────────────────────────────────────────────────────────────

    public function test_prava(): void
    {
        $a = $this->senior('Char A');
        $m = $this->meeting();
        $this->acl(['new_all/Meetings_Controller/meeting', 'edit_all/Meetings_Controller/meeting']);
        $this->assertFalse($this->as()->get(route('meetings.index'))->viewData('canNew'));
        $this->assertFalse($this->as()->get(route('meetings.show', $m->id))->viewData('canEdit'));
        $this->as()->get(route('meetings.create'))->assertForbidden();
        $this->as()->post(route('meetings.store'), [])->assertForbidden();
        foreach (['meetings.open', 'meetings.close', 'meetings.degrade'] as $r) {
            $this->as()->post(route($r, $m->id))->assertForbidden();
        }
        $this->as()->post(route('meetings.checkin', [$m->id, $a]))->assertForbidden();
        $this->as()->post(route('meetings.proxy', [$m->id, $a]))->assertForbidden();
        $this->acl(['view_all/Meetings_Controller/meeting']);
        $this->as()->get(route('meetings.index'))->assertForbidden();
        $this->as()->get(route('meetings.show', $m->id))->assertForbidden();
        $this->as()->get(route('meetings.presence-export', $m->id))->assertForbidden();
    }
}
