<?php
namespace App\Console\Commands;

use App\Domains\Members\Actions\RemoveFormerMemberDevicesAction;
use App\Domains\Members\Repositories\MemberRepository;
use App\Domains\Members\Support\FormerType;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RedirectFormerMembers extends Command
{
    protected $signature   = 'members:redirect-former {--force : Skip time check}';
    protected $description = 'Mark expired members as former, optionally remove devices, activate redirect message';

    public function handle(): int
    {
        // Step 1: Mark today's expired members as former (locked=1) — řádný člen (90)
        // → bývalý člen (15), ostatní (zákazník 2, čekající 18 …) → bývalý zákazník (16),
        // stejně jako ruční ukončení (Members\MembershipController::endMembership).
        $today = now()->format('Y-m-d');

        // Nejdřív si vytáhneme, koho budeme překlápět (kvůli deaktivaci poplatků
        // k jejich vlastnímu leaving_date), pak teprve hromadně přepneme typ.
        $toFormer = DB::table('members')
            ->whereNotIn('type', FormerType::TYPES)
            ->whereNotNull('leaving_date')
            ->where('leaving_date', '!=', '9999-12-31')
            ->where('leaving_date', '!=', '0000-00-00')
            ->where('leaving_date', '<=', $today)
            ->get(['id', 'type', 'leaving_date']);

        $updated = 0;
        if ($toFormer->isNotEmpty()) {
            foreach ($toFormer->groupBy(fn ($m) => FormerType::for((int) $m->type)) as $formerType => $group) {
                $updated += DB::table('members')
                    ->whereIn('id', $group->pluck('id'))
                    ->update([
                        'type'           => $formerType,
                        'locked'         => 1,
                        'speed_class_id' => null, // bývalý člen → rychlost „žádná"
                    ]);
            }

            // Individuální tarif + dodatečné služby ukončit k datu odchodu.
            // Sem spadají budoucí datumy (endMembership je sice ukončil hned, ty
            // jsou už FORMER a tudíž mimo tento výběr), přerušení s „ukončit po
            // přerušení" i přímé editace leaving_date.
            foreach ($toFormer as $m) {
                \App\Services\MemberFeesTermination::deactivate((int) $m->id, $m->leaving_date);
                app(MemberRepository::class)->markVariableSymbolLeft((int) $m->id);
            }

            // Audit trail: automatické překlopení na bývalé členy (systém, user_id NULL).
            \App\Services\AuditLogger::log('auto_former', 'members', null, null, [
                'marked_former' => $updated,
                'member_ids'    => $toFormer->pluck('id')->all(),
            ]);

            $this->info("Marked {$updated} members as former (type 15/16).");
        }

        // Zamknout členy, kteří byli ukončeni s budoucím datem (type už je
        // FORMER, ale locked=0, protože endMembership zamyká jen pokud datum
        // už nastalo). V den D je tady dorovnáme — a se zámkem i vynulujeme
        // rychlost, protože endMembership ji u budoucího data nechal být, aby
        // člen internet dohrál do odjezdu. Step 1 výše je nechytne (jsou už
        // FORMER), takže je to jediné místo, kde jim tarif v den D „dojede".
        $locked = DB::table('members')
            ->whereIn('type', FormerType::TYPES)
            ->where('locked', 0)
            ->whereNotNull('leaving_date')
            ->where('leaving_date', '!=', '9999-12-31')
            ->where('leaving_date', '!=', '0000-00-00')
            ->where('leaving_date', '<=', $today)
            ->update(['locked' => 1, 'speed_class_id' => null]);

        if ($locked > 0) {
            $this->info("Locked {$locked} former members whose leaving_date arrived (speed cleared).");
        }

        // Step 2: Auto-remove zařízení u členů, jejichž leaving_date právě uplynul.
        // Nezávisíme na $updated z předchozího kroku (Step 1 přepíše jen members,
        // co nejsou FORMER — admin co ukončil přes endMembership form má type=15/16
        // už zaznamenaný, takže $updated=0 a původní gate to skipnul).
        //
        // Okno (today-7 days, today) limituje blast radius — kdyby admin teď
        // zapnul auto-remove poprvé, nechceme retroaktivně smazat zařízení 2700+
        // historických former členů. Cron běží denně, 7 dní zpětně je dostatečná
        // rezerva pro vynechané běhy. Smazání je idempotentní (foreach po
        // ifaces/devices), takže opakovaný běh nic nezpůsobí.
        $autoRemove = (bool) \App\Models\Setting::get('former_member_auto_device_remove', 0);
        if ($autoRemove) {
            $sevenDaysAgo = now()->subDays(7)->format('Y-m-d');

            $memberIds = DB::table('members')
                ->whereIn('type', FormerType::TYPES)
                ->where('leaving_date', '!=', '9999-12-31')
                ->where('leaving_date', '!=', '0000-00-00')
                ->whereBetween('leaving_date', [$sevenDaysAgo, $today])
                ->pluck('id');

            // IP adresy se nejdřív zapíšou do komentáře ([YYYY-MM-DD] ip1,ip2,…) —
            // stopa, na kterých IP bývalý člen byl. Bez IP se tady nic nezapisuje.
            $removeDevices = app(RemoveFormerMemberDevicesAction::class);
            $removed = 0;
            foreach ($memberIds as $memberId) {
                $removed += $removeDevices->handle((int) $memberId, $today, false);
            }
            if ($removed > 0) {
                $this->info("Removed {$removed} devices for former members in window (last 7d).");
            }
        }

        // Step 3: Get FORMER_MEMBER_MESSAGE (type=19)
        $message = Message::where('type', 19)->first();
        if (!$message) {
            $this->warn('Former member message (type=19) not found.');
            return 1;
        }

        // Step 4: Získat former členy, jejichž leaving_date už **uplynul**.
        // Pokud admin ukončí s datem 14 dní v budoucnosti (např. dohrání služby
        // do data odjezdu), member dostane type=15/16 hned, ale internet má
        // dojet až do leaving_date — proto redirect aktivujeme až k datu.
        // Sentinel hodnoty 9999-12-31 / 0000-00-00 znamenají "datum neurčeno"
        // a takové členy raději nepřesměrováváme (defensive: admin to doplní).
        $formerMembers = DB::table('members as m')
            ->whereIn('m.type', FormerType::TYPES)
            ->where('m.leaving_date', '<=', $today)
            ->where('m.leaving_date', '!=', '9999-12-31')
            ->where('m.leaving_date', '!=', '0000-00-00')
            ->pluck('m.id');

        // Step 5: Clear old redirections for this message
        DB::table('messages_ip_addresses')
            ->where('message_id', $message->id)
            ->delete();

        // Step 6: Activate redirect for all former members' IPs
        $count = 0;
        $now   = now();
        foreach ($formerMembers as $memberId) {
            $ipIds = DB::table('ip_addresses as ip')
                ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                ->join('devices as d', 'd.id', '=', 'i.device_id')
                ->join('users as u', 'u.id', '=', 'd.user_id')
                ->where('u.member_id', $memberId)
                ->pluck('ip.id');

            foreach ($ipIds as $ipId) {
                DB::table('messages_ip_addresses')->insertOrIgnore([
                    'message_id'    => $message->id,
                    'ip_address_id' => $ipId,
                    'user_id'       => 1,
                    'comment'       => 'Auto: bývalý člen',
                    'datetime'      => $now,
                ]);
                $count++;
            }
        }

        $this->info("Activated redirect for {$count} IP addresses of {$formerMembers->count()} former members.");
        return 0;
    }
}
