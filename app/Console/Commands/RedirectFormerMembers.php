<?php
namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RedirectFormerMembers extends Command
{
    protected $signature   = 'members:redirect-former {--force : Skip time check}';
    protected $description = 'Mark expired members as former, optionally remove devices, activate redirect message';

    const TYPE_FORMER          = 15;
    const TYPE_FORMER_CUSTOMER = 16;
    const FORMER_TYPES         = [self::TYPE_FORMER, self::TYPE_FORMER_CUSTOMER];

    public function handle(): int
    {
        // Step 1: Mark today's expired members as former (type=15, locked=1)
        $today = now()->format('Y-m-d');

        // Nejdřív si vytáhneme, koho budeme překlápět (kvůli deaktivaci poplatků
        // k jejich vlastnímu leaving_date), pak teprve hromadně přepneme typ.
        $toFormer = DB::table('members')
            ->whereNotIn('type', self::FORMER_TYPES)
            ->whereNotNull('leaving_date')
            ->where('leaving_date', '!=', '9999-12-31')
            ->where('leaving_date', '!=', '0000-00-00')
            ->where('leaving_date', '<=', $today)
            ->get(['id', 'leaving_date']);

        $updated = 0;
        if ($toFormer->isNotEmpty()) {
            $updated = DB::table('members')
                ->whereIn('id', $toFormer->pluck('id'))
                ->update([
                    'type'   => self::TYPE_FORMER,
                    'locked' => 1,
                ]);

            // Individuální tarif + dodatečné služby ukončit k datu odchodu.
            // Sem spadají budoucí datumy (endMembership je sice ukončil hned, ty
            // jsou už FORMER a tudíž mimo tento výběr), přerušení s „ukončit po
            // přerušení" i přímé editace leaving_date.
            foreach ($toFormer as $m) {
                \App\Services\MemberFeesTermination::deactivate((int) $m->id, $m->leaving_date);
            }

            $this->info("Marked {$updated} members as former (type=15).");
        }

        // Zamknout členy, kteří byli ukončeni s budoucím datem (type už je
        // FORMER, ale locked=0, protože endMembership zamyká jen pokud datum
        // už nastalo). V den D je tady dorovnáme.
        $locked = DB::table('members')
            ->whereIn('type', self::FORMER_TYPES)
            ->where('locked', 0)
            ->whereNotNull('leaving_date')
            ->where('leaving_date', '!=', '9999-12-31')
            ->where('leaving_date', '!=', '0000-00-00')
            ->where('leaving_date', '<=', $today)
            ->update(['locked' => 1]);

        if ($locked > 0) {
            $this->info("Locked {$locked} former members whose leaving_date arrived.");
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
                ->whereIn('type', self::FORMER_TYPES)
                ->where('leaving_date', '!=', '9999-12-31')
                ->where('leaving_date', '!=', '0000-00-00')
                ->whereBetween('leaving_date', [$sevenDaysAgo, $today])
                ->pluck('id');

            $removed = 0;
            foreach ($memberIds as $memberId) {
                // Sbírej IP adresy PŘED mazáním a zapiš je jako prefix do members.comment
                // (stejný pattern jako endMembership: [YYYY-MM-DD] ip1,ip2,...).
                // Bez tohoto kroku by se po mazání ztratila stopa, na kterých IP
                // adresách bývalý člen byl — což admin občas potřebuje dohledat.
                $ips1 = DB::table('ip_addresses')
                    ->where('member_id', $memberId)
                    ->whereNotNull('ip_address')
                    ->pluck('ip_address');

                $ips2 = DB::table('ip_addresses as ia')
                    ->join('ifaces as i', 'i.id', '=', 'ia.iface_id')
                    ->join('devices as d', 'd.id', '=', 'i.device_id')
                    ->join('users as u', 'u.id', '=', 'd.user_id')
                    ->where('u.member_id', $memberId)
                    ->whereNotNull('ia.ip_address')
                    ->pluck('ia.ip_address');

                $ips = $ips1->merge($ips2)->unique()->sort()->values()->all();

                if (!empty($ips)) {
                    $prefix = "[{$today}] ";
                    $maxLen = 250;
                    $out    = $prefix;
                    $shown  = 0;
                    foreach ($ips as $ip) {
                        $add = ($shown ? ',' : '') . $ip;
                        if (strlen($out . $add) > $maxLen) break;
                        $out .= $add;
                        $shown++;
                    }
                    $rest = count($ips) - $shown;
                    if ($rest > 0) {
                        $suffix = " (+{$rest} další)";
                        if (strlen($out . $suffix) > $maxLen) {
                            $out = rtrim(substr($out, 0, max(0, $maxLen - strlen($suffix))), ', ');
                        }
                        $out .= $suffix;
                    }
                    DB::statement("
                        UPDATE members
                        SET comment = LEFT(
                            CONCAT(?, IF(comment IS NULL OR comment = '', '', '\n'), IFNULL(comment, '')),
                            250
                        )
                        WHERE id = ?
                    ", [$out, $memberId]);
                }

                $userIds = DB::table('users')->where('member_id', $memberId)->pluck('id');
                foreach ($userIds as $userId) {
                    $deviceIds = DB::table('devices')->where('user_id', $userId)->pluck('id');
                    foreach ($deviceIds as $deviceId) {
                        $ifaceIds = DB::table('ifaces')->where('device_id', $deviceId)->pluck('id');
                        foreach ($ifaceIds as $ifaceId) {
                            DB::table('ip6_addresses')->where('iface_id', $ifaceId)->delete();
                            DB::table('ip_addresses')->where('iface_id', $ifaceId)->delete();
                        }
                        DB::table('ifaces')->where('device_id', $deviceId)->delete();
                        $removed++;
                    }
                    DB::table('devices')->where('user_id', $userId)->delete();
                }
                // Smaž i ip_addresses napojené přímo na member_id (mimo ifaces)
                DB::table('ip_addresses')->where('member_id', $memberId)->delete();
                DB::table('subnets_owners')->where('member_id', $memberId)->delete();
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
            ->whereIn('m.type', self::FORMER_TYPES)
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
