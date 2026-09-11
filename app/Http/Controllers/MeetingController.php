<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\Member;
use App\Models\MemberVotingLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingController extends Controller
{
    private const ACL_SECTION = 'Meetings_Controller';
    private const ACL_VALUE   = 'meeting';

    /** Kolik po sobě jdoucích absencí = degradace seniora na řadového člena. */
    private const DEGRADE_AFTER = 3;

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        if (!$this->can('view_all')) abort(403);

        $meetings = Meeting::orderByDesc('held_on')->orderByDesc('id')->paginate(50);

        return view('meetings.index', [
            'meetings' => $meetings,
            'canNew'   => $this->can('new_all'),
            'seniorCount' => Member::where('can_vote', 1)->count(),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) abort(403);
        return view('meetings.create', ['typeLabels' => Meeting::typeLabels()]);
    }

    public function store(Request $request)
    {
        if (!$this->can('new_all')) abort(403);

        $data = $request->validate([
            'title'          => 'required|string|max:255',
            'held_on'        => 'required|date',
            'type'           => 'required|in:' . implode(',', array_keys(Meeting::typeLabels())),
            'quorum_percent' => 'required|integer|min:1|max:100',
            'comment'        => 'nullable|string|max:500',
        ]);
        $data['status'] = Meeting::STATUS_PLANNED;

        $meeting = Meeting::create($data);
        session()->flash('success', 'Schůze byla založena.');
        return redirect()->route('meetings.show', $meeting->id);
    }

    public function show(int $id)
    {
        if (!$this->can('view_all')) abort(403);

        $meeting = Meeting::find($id);
        if (!$meeting) abort(404);

        // Senioři = členové s hlasovacím právem. Řadíme podle jména.
        $seniors = $this->seniorsForMeeting($meeting);

        $attendances = MeetingAttendance::where('meeting_id', $id)->get()->keyBy('member_id');

        // Počet plných mocí nesených jednotlivými přítomnými (proxy_holder_id → kolik).
        $proxyLoad = MeetingAttendance::where('meeting_id', $id)
            ->whereNotNull('proxy_holder_id')
            ->select('proxy_holder_id', DB::raw('COUNT(*) c'))
            ->groupBy('proxy_holder_id')->pluck('c', 'proxy_holder_id');

        $rows = [];
        $present = 0; $proxied = 0;
        foreach ($seniors as $s) {
            $att = $attendances->get($s->id);
            $isPresent = $att && $att->checked_in_at !== null;
            $isProxied = $att && $att->checked_in_at === null && $att->proxy_holder_id !== null;
            if ($isPresent) $present++;
            if ($isProxied) $proxied++;
            // Série absencí z PŘEDCHOZÍCH uzavřených schůzí (bez této).
            $priorAbsences = $this->consecutiveAbsences($s->id, $id);
            $rows[] = [
                'member'        => $s,
                'present'       => $isPresent,
                'proxied'       => $isProxied,
                'proxy_holder'  => $att->proxy_holder_id ?? null,
                'checked_in_at' => $att->checked_in_at ?? null,
                'proxy_load'    => (int) ($proxyLoad[$s->id] ?? 0),
                'prior_absences'=> $priorAbsences,
                'at_risk'       => $priorAbsences >= self::DEGRADE_AFTER - 1, // 2× → hrozí 3.
            ];
        }

        $total       = count($seniors);
        $counted     = $present + $proxied;                       // do kvóra
        $quorumNeed  = (int) ceil($total * $meeting->quorum_percent / 100);
        $quorumMet   = $total > 0 && $counted >= $quorumNeed;

        // Kandidáti na degradaci: jen u UZAVŘENÉ schůze (po snapshotu).
        $degradeCandidates = [];
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            foreach ($seniors as $s) {
                if ($this->consecutiveAbsences($s->id) >= self::DEGRADE_AFTER) {
                    $degradeCandidates[] = $s;
                }
            }
        }

        return view('meetings.show', [
            'meeting'    => $meeting,
            'rows'       => $rows,
            'present'    => $present,
            'proxied'    => $proxied,
            'counted'    => $counted,
            'total'      => $total,
            'quorumNeed' => $quorumNeed,
            'quorumMet'  => $quorumMet,
            'degradeCandidates' => $degradeCandidates,
            'canEdit'    => $this->can('edit_all'),
        ]);
    }

    /** Zahájit schůzi (planned → open). */
    public function open(int $id)
    {
        if (!$this->can('edit_all')) abort(403);
        $meeting = Meeting::findOrFail($id);
        if ($meeting->status === Meeting::STATUS_PLANNED) {
            $meeting->update(['status' => Meeting::STATUS_OPEN]);
        }
        return redirect()->route('meetings.show', $id);
    }

    /** Přepnout přítomnost seniora (check-in / zrušení). Plnou moc tím ruší. */
    public function checkIn(Request $request, int $id, int $memberId)
    {
        if (!$this->can('edit_all')) abort(403);
        $meeting = Meeting::findOrFail($id);
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            return back()->withErrors(['meeting' => 'Schůze je uzavřená.']);
        }
        $this->assertSenior($memberId);

        $att = MeetingAttendance::firstOrNew(['meeting_id' => $id, 'member_id' => $memberId]);
        if ($att->checked_in_at !== null) {
            // už přítomen → zrušit přítomnost
            $att->checked_in_at = null;
        } else {
            $att->checked_in_at = now();
            $att->proxy_holder_id = null; // přítomen osobně → žádná plná moc za něj
        }
        $att->save();
        // Když člověk dorazil, nemůže zároveň nést plné moci? Může — drží je dál.
        return back();
    }

    /** Nastavit plnou moc: nepřítomného člena $memberId zastupuje přítomný holder. */
    public function setProxy(Request $request, int $id, int $memberId)
    {
        if (!$this->can('edit_all')) abort(403);
        $meeting = Meeting::findOrFail($id);
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            return back()->withErrors(['meeting' => 'Schůze je uzavřená.']);
        }
        $this->assertSenior($memberId);

        $holderId = $request->input('proxy_holder_id') ?: null;
        if ($holderId !== null) {
            $holderId = (int) $holderId;
            if ($holderId === $memberId) {
                return back()->withErrors(['proxy' => 'Člen nemůže zastupovat sám sebe.']);
            }
            // holder musí být přítomný senior na této schůzi
            $holderPresent = MeetingAttendance::where('meeting_id', $id)
                ->where('member_id', $holderId)->whereNotNull('checked_in_at')->exists();
            if (!$holderPresent) {
                return back()->withErrors(['proxy' => 'Zmocněnec musí být fyzicky přítomen.']);
            }
        }

        $att = MeetingAttendance::firstOrNew(['meeting_id' => $id, 'member_id' => $memberId]);
        $att->proxy_holder_id = $holderId;
        if ($holderId !== null) {
            $att->checked_in_at = null; // zastoupen = nepřítomen osobně
        }
        $att->save();
        return back();
    }

    /** Uzavřít schůzi: snapshot docházky (absenti dostanou prázdný řádek). */
    public function close(int $id)
    {
        if (!$this->can('edit_all')) abort(403);
        $meeting = Meeting::findOrFail($id);

        // Snapshot: každý současný senior bez řádku = explicitní absence.
        $seniorIds = Member::where('can_vote', 1)->pluck('id');
        $existing  = MeetingAttendance::where('meeting_id', $id)->pluck('member_id')->flip();
        $now = now();
        foreach ($seniorIds as $mid) {
            if (!isset($existing[$mid])) {
                MeetingAttendance::create([
                    'meeting_id' => $id, 'member_id' => $mid,
                    'checked_in_at' => null, 'proxy_holder_id' => null,
                ]);
            }
        }
        $meeting->update(['status' => Meeting::STATUS_CLOSED]);
        session()->flash('success', 'Schůze uzavřena. Zkontroluj případné kandidáty na degradaci níže.');
        return redirect()->route('meetings.show', $id);
    }

    /** Degradace vybraných seniorů (3× po sobě nepřítomen) → can_vote=0 + audit. */
    public function degrade(Request $request, int $id)
    {
        if (!$this->can('edit_all')) abort(403);
        $meeting = Meeting::findOrFail($id);
        $ids = array_filter(array_map('intval', (array) $request->input('member_ids', [])));

        $done = 0;
        foreach ($ids as $mid) {
            // Bezpečnostní vynucení: degradovat jen když fakt má 3× absenci.
            if ($this->consecutiveAbsences($mid) < self::DEGRADE_AFTER) continue;
            $m = Member::find($mid);
            if (!$m || !$m->can_vote) continue;
            $m->update(['can_vote' => 0]);
            MemberVotingLog::create([
                'member_id' => $mid,
                'action'    => MemberVotingLog::ACTION_DEGRADED,
                'meeting_id'=> $id,
                'reason'    => self::DEGRADE_AFTER . '× po sobě nepřítomen na členské schůzi',
                'user_id'   => auth()->id(),
                'created_at'=> now(),
            ]);
            $done++;
        }
        session()->flash('success', "Degradováno členů: {$done}.");
        return redirect()->route('meetings.show', $id);
    }

    /** Prezenční listina jako CSV (jméno, stav, zmocněnec, čas příchodu). */
    public function presenceExport(int $id)
    {
        if (!$this->can('view_all')) abort(403);
        $meeting = Meeting::findOrFail($id);

        $seniors = $this->seniorsForMeeting($meeting);
        $att = MeetingAttendance::where('meeting_id', $id)->get()->keyBy('member_id');
        $names = $seniors->pluck('name', 'id');

        $fname = 'prezence_' . $meeting->held_on?->format('Y-m-d') . '_' . $meeting->id . '.csv';
        return response()->streamDownload(function () use ($seniors, $att, $names) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM pro Excel
            fputcsv($out, ['Člen', 'Stav', 'Zmocněnec', 'Příchod', 'Podpis'], ';');
            foreach ($seniors as $s) {
                $a = $att->get($s->id);
                if ($a && $a->checked_in_at !== null) {
                    $stav = 'Přítomen'; $zmoc = '';
                } elseif ($a && $a->proxy_holder_id !== null) {
                    $stav = 'Zastoupen'; $zmoc = $names[$a->proxy_holder_id] ?? ('#' . $a->proxy_holder_id);
                } else {
                    $stav = 'Nepřítomen'; $zmoc = '';
                }
                fputcsv($out, [
                    $s->name, $stav, $zmoc,
                    $a && $a->checked_in_at ? $a->checked_in_at->format('d.m.Y H:i') : '',
                    '',
                ], ';');
            }
            fclose($out);
        }, $fname, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Množina seniorů RELEVANTNÍ pro danou schůzi:
     *  - UZAVŘENÁ → historický snapshot (členové s docházkovým řádkem v době
     *    uzavření). Nově přidaný senior se tak NEobjeví zpětně v uzavřených schůzích.
     *  - OTEVŘENÁ/plánovaná → aktuální senioři (can_vote=1) ∪ ti, co už mají řádek
     *    (např. přítomní, kterým se mezitím změnilo can_vote).
     */
    private function seniorsForMeeting(Meeting $meeting)
    {
        if ($meeting->status === Meeting::STATUS_CLOSED) {
            $ids = MeetingAttendance::where('meeting_id', $meeting->id)->pluck('member_id');
        } else {
            $ids = Member::where('can_vote', 1)->pluck('id')
                ->merge(MeetingAttendance::where('meeting_id', $meeting->id)->pluck('member_id'))
                ->unique();
        }
        return Member::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    private function assertSenior(int $memberId): void
    {
        $ok = Member::where('id', $memberId)->where('can_vote', 1)->exists();
        if (!$ok) abort(422, 'Člen nemá hlasovací právo.');
    }

    /**
     * Počet po sobě jdoucích absencí v UZAVŘENÝCH členských schůzích (od nejnovější).
     * Účast (osobně NEBO plnou mocí) sérii ukončí. Chybějící řádek = člen tehdy
     * nebyl senior/sledován → sérii taky ukončí (nepočítá jako absenci).
     * @param ?int $excludeMeetingId vynech tuto schůzi (pro indikátor „před touto").
     */
    private function consecutiveAbsences(int $memberId, ?int $excludeMeetingId = null): int
    {
        $meetings = Meeting::where('status', Meeting::STATUS_CLOSED)
            ->where('type', 'clenska_schuze')
            ->when($excludeMeetingId, fn ($q) => $q->where('id', '<>', $excludeMeetingId))
            ->orderByDesc('held_on')->orderByDesc('id')->get(['id']);

        $streak = 0;
        foreach ($meetings as $m) {
            $att = MeetingAttendance::where('meeting_id', $m->id)
                ->where('member_id', $memberId)->first();
            if (!$att) break;                              // nebyl senior → konec
            if ($att->checked_in_at !== null || $att->proxy_holder_id !== null) break; // účast
            $streak++;                                     // absence
        }
        return $streak;
    }
}
