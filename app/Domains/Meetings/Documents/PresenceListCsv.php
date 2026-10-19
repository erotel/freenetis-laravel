<?php

namespace App\Domains\Meetings\Documents;

use App\Domains\Meetings\Queries\MeetingCardQuery;
use App\Domains\Meetings\Repositories\MeetingRepository;
use App\Models\Meeting;

/** Prezenční listina (CSV pro Excel: BOM, „;"): člen, stav, zmocněnec, příchod, podpis. */
class PresenceListCsv
{
    public function __construct(private MeetingRepository $meetings, private MeetingCardQuery $card)
    {
    }

    public function filename(Meeting $meeting): string
    {
        return 'prezence_' . $meeting->held_on?->format('Y-m-d') . '_' . $meeting->id . '.csv';
    }

    /** Zapíše CSV do streamu. @param resource $out */
    public function write(Meeting $meeting, $out): void
    {
        $seniors = $this->card->seniors($meeting);
        $att     = $this->meetings->attendances($meeting->id);
        $names   = $seniors->pluck('name', 'id');

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Člen', 'Stav', 'Zmocněnec', 'Příchod', 'Podpis'], ';');
        foreach ($seniors as $s) {
            $a = $att->get($s->id);
            if ($a && $a->checked_in_at !== null) {
                [$stav, $zmoc] = ['Přítomen', ''];
            } elseif ($a && $a->proxy_holder_id !== null) {
                [$stav, $zmoc] = ['Zastoupen', $names[$a->proxy_holder_id] ?? ('#' . $a->proxy_holder_id)];
            } else {
                [$stav, $zmoc] = ['Nepřítomen', ''];
            }
            fputcsv($out, [$s->name, $stav, $zmoc, $a && $a->checked_in_at ? $a->checked_in_at->format('d.m.Y H:i') : '', ''], ';');
        }
    }
}
