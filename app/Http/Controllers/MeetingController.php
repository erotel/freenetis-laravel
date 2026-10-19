<?php

namespace App\Http\Controllers;

use App\Domains\Meetings\Actions\CloseMeetingAction;
use App\Domains\Meetings\Actions\DegradeSeniorsAction;
use App\Domains\Meetings\Actions\SetProxyAction;
use App\Domains\Meetings\Actions\ToggleCheckInAction;
use App\Domains\Meetings\Documents\PresenceListCsv;
use App\Domains\Meetings\Exceptions\AttendanceRejected;
use App\Domains\Meetings\Queries\MeetingCardQuery;
use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Http\Requests\Meetings\StoreMeetingRequest;
use App\Models\Meeting;
use Illuminate\Http\Request;

/** Členské schůze: docházka seniorů, plné moci, kvórum, degradace; logika v app/Domains/Meetings. */
class MeetingController extends Controller
{
    private const ACL_SECTION = 'Meetings_Controller';
    private const ACL_VALUE   = 'meeting';

    public function __construct(private MeetingRepository $meetings) {}

    private function can(string $action): bool
    {
        return $this->aclCheck($action, self::ACL_SECTION, self::ACL_VALUE);
    }

    public function index()
    {
        if (!$this->can('view_all')) abort(403);

        return view('meetings.index', [
            'meetings'    => $this->meetings->paginate(50),
            'canNew'      => $this->can('new_all'),
            'seniorCount' => $this->meetings->seniorCount(),
        ]);
    }

    public function create()
    {
        if (!$this->can('new_all')) abort(403);
        return view('meetings.create', ['typeLabels' => Meeting::typeLabels()]);
    }

    public function store(StoreMeetingRequest $request)
    {
        $meeting = $this->meetings->create($request->validated());
        session()->flash('success', 'Schůze byla založena.');
        return redirect()->route('meetings.show', $meeting->id);
    }

    public function show(int $id, MeetingCardQuery $card)
    {
        if (!$this->can('view_all')) abort(403);

        $meeting = $this->meetings->find($id);
        if (!$meeting) abort(404);

        return view('meetings.show', $card->card($meeting) + ['canEdit' => $this->can('edit_all')]);
    }

    /** Zahájit schůzi (planned → open). */
    public function open(int $id)
    {
        if (!$this->can('edit_all')) abort(403);
        $meeting = $this->meetings->findOrFail($id);
        if ($meeting->status === Meeting::STATUS_PLANNED) {
            $this->meetings->setStatus($meeting, Meeting::STATUS_OPEN);
        }
        return redirect()->route('meetings.show', $id);
    }

    /** Přepnout přítomnost seniora (check-in / zrušení). Plnou moc tím ruší. */
    public function checkIn(Request $request, int $id, int $memberId, ToggleCheckInAction $toggle)
    {
        if (!$this->can('edit_all')) abort(403);
        try {
            $toggle->handle($this->meetings->findOrFail($id), $memberId);
        } catch (AttendanceRejected $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }
        return back();
    }

    /** Nastavit plnou moc: nepřítomného člena $memberId zastupuje přítomný holder. */
    public function setProxy(Request $request, int $id, int $memberId, SetProxyAction $setProxy)
    {
        if (!$this->can('edit_all')) abort(403);
        $holderId = $request->input('proxy_holder_id') ?: null;
        try {
            $setProxy->handle($this->meetings->findOrFail($id), $memberId, $holderId !== null ? (int) $holderId : null);
        } catch (AttendanceRejected $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }
        return back();
    }

    /** Uzavřít schůzi: snapshot docházky (absenti dostanou prázdný řádek). */
    public function close(int $id, CloseMeetingAction $close)
    {
        if (!$this->can('edit_all')) abort(403);
        $close->handle($this->meetings->findOrFail($id));
        session()->flash('success', 'Schůze uzavřena. Zkontroluj případné kandidáty na degradaci níže.');
        return redirect()->route('meetings.show', $id);
    }

    /** Degradace vybraných seniorů (3× po sobě nepřítomen) → can_vote=0 + log. */
    public function degrade(Request $request, int $id, DegradeSeniorsAction $degrade)
    {
        if (!$this->can('edit_all')) abort(403);
        $meeting = $this->meetings->findOrFail($id);
        $ids = array_filter(array_map('intval', (array) $request->input('member_ids', [])));

        $done = $degrade->handle($meeting, $ids, auth()->id());
        session()->flash('success', "Degradováno členů: {$done}.");
        return redirect()->route('meetings.show', $id);
    }

    /** Prezenční listina jako CSV. */
    public function presenceExport(int $id, PresenceListCsv $csv)
    {
        if (!$this->can('view_all')) abort(403);
        $meeting = $this->meetings->findOrFail($id);

        return response()->streamDownload(function () use ($csv, $meeting) {
            $out = fopen('php://output', 'w');
            $csv->write($meeting, $out);
            fclose($out);
        }, $csv->filename($meeting), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
