<?php
namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationActivation extends Command
{
    protected $signature   = 'notifications:activate {--force : Skip time rule checks}';
    protected $description = 'Activate notifications for debtors, low credit members etc.';

    // Message types that can be auto-activated
    const AUTO_TYPES = [
        Message::DEBTOR_MESSAGE,               // 5 - zákazník dlužník
        Message::PAYMENT_NOTICE_MESSAGE,       // 6 - zákazník upomínka
        Message::DEBTOR_MESSAGE_CLEN,          // 25 - člen dlužník
        Message::PAYMENT_NOTICE_MESSAGE_CLEN,  // 26 - člen upomínka
    ];

    public function handle(): int
    {
        if (!Setting::get('finance_enabled', 0)) {
            $this->info('Finance disabled, skipping.');
            return 0;
        }

        $emailEnabled       = (bool) Setting::get('email_enabled', 0);
        $smsEnabled         = (bool) Setting::get('sms_enabled', 0);
        $redirectEnabled    = (bool) Setting::get('redirection_enabled', 0);
        $debtorBoundary     = (float) Setting::get('debtor_boundary', 0);
        $bigDebtorBoundary  = (float) Setting::get('big_debtor_boundary', 0);
        $debtorImmunity     = (int)   Setting::get('initial_debtor_immunity', 0);
        $subjectPrefix      = Setting::get('email_subject_prefix', 'PVfree.net - FreenetIS');
        $fromEmail          = Setting::get('email_default_email', 'noreply@pvfree.net');

        $now       = now();
        $today     = $now->day;
        $hour      = $now->hour;
        $weekday   = $now->dayOfWeek ?: 7; // 1=Mon, 7=Sun
        $deductDay = (int) Setting::get('deduct_day', 26);

        $messages = Message::whereIn('type', self::AUTO_TYPES)->get();

        foreach ($messages as $message) {
            $rules = DB::table('messages_automatical_activations')
                ->where('message_id', $message->id)
                ->get();

            if ($rules->isEmpty()) continue;

            foreach ($rules as $rule) {
                // Check time rule
                if (!$this->option('force') && !$this->isRuleActive($rule, $today, $hour, $weekday, $deductDay)) {
                    continue;
                }

                $doEmail    = $rule->email_enabled    && $emailEnabled;
                $doSms      = $rule->sms_enabled      && $smsEnabled;
                $doRedirect = $rule->redirection_enabled && $redirectEnabled;

                if (!$doEmail && !$doSms && !$doRedirect) continue;

                // Get members to notify
                $members = $this->getMembersToNotify($message->type, $debtorBoundary, $bigDebtorBoundary, $debtorImmunity);

                if ($members->isEmpty()) {
                    $this->info("Message [{$message->id}] {$message->name}: no members to notify.");
                    continue;
                }

                $this->info("Message [{$message->id}] {$message->name}: {$members->count()} members.");

                foreach ($members as $member) {
                    // Send email
                    if ($doEmail && !empty($member->email)) {
                        $subject = $subjectPrefix . ' ::' . $message->name;
                        DB::table('email_queues')->insert([
                            'from'    => $fromEmail,
                            'to'      => $member->email,
                            'subject' => $subject,
                            'body'    => $message->email_text ?? $message->text ?? '',
                            'state'   => 0,
                        ]);
                    }

                    // Activate redirect on all member IPs
                    if ($doRedirect) {
                        $ipIds = DB::table('ip_addresses as ip')
                            ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                            ->join('devices as d', 'd.id', '=', 'i.device_id')
                            ->join('users as u', 'u.id', '=', 'd.user_id')
                            ->where('u.member_id', $member->id)
                            ->pluck('ip.id');

                        foreach ($ipIds as $ipId) {
                            DB::table('messages_ip_addresses')->insertOrIgnore([
                                'message_id'    => $message->id,
                                'ip_address_id' => $ipId,
                                'user_id'       => 1,
                                'comment'       => 'Auto-aktivace: ' . $message->name,
                                'datetime'      => now(),
                            ]);
                        }
                    }
                }

                // Send activation report email if configured
                if (!empty($rule->send_activation_to_email)) {
                    $subject = $subjectPrefix . ' ::Aktivace zprávy: ' . $message->name;
                    $body    = "Aktivována zpráva: {$message->name}\n";
                    $body   .= "Počet členů: {$members->count()}\n";
                    $body   .= "Datum: " . now()->format('d.m.Y H:i') . "\n";
                    DB::table('email_queues')->insert([
                        'from'    => $fromEmail,
                        'to'      => $rule->send_activation_to_email,
                        'subject' => $subject,
                        'body'    => nl2br(htmlspecialchars($body)),
                        'state'   => 0,
                    ]);
                }
            }
        }

        return 0;
    }

    private function isRuleActive(object $rule, int $today, int $hour, int $weekday, int $deductDay): bool
    {
        $type      = (int) $rule->type;
        $attribute = trim($rule->attribute ?? '');

        switch ($type) {
            case 1: // TYPE_MONTHLY - DD/H
                [$day, $h] = array_pad(explode('/', $attribute), 2, 0);
                return (int)$day === $today && (int)$h === $hour;

            case 2: // TYPE_WEEKLY - D/H (1=Mon, 7=Sun)
                [$day, $h] = array_pad(explode('/', $attribute), 2, 0);
                return (int)$day === $weekday && (int)$h === $hour;

            case 3: // TYPE_DAILY - /H
                $h = (int) ltrim($attribute, '/');
                return $h === $hour;

            case 4: // TYPE_DAILY_WD - /H (only Mon-Fri)
                if ($weekday > 5) return false;
                $h = (int) ltrim($attribute, '/');
                return $h === $hour;

            case 5: // TYPE_HOURLY - always
                return true;

            case 6: // TYPE_AFTER_DEDUCTION - /H on deduct day
                $h = (int) ltrim($attribute, '/');
                return $today === $deductDay && $h === $hour;

            default:
                return false;
        }
    }

    private function getMembersToNotify(int $messageType, float $debtorBoundary, float $bigDebtorBoundary, int $immunity): \Illuminate\Support\Collection
    {
        $date = now()->format('Y-m-d');

        // Base query - člen s kreditním účtem
        $base = DB::table('members as m')
            ->join('accounts as a', function ($j) {
                $j->on('a.member_id', '=', 'm.id')
                  ->where('a.account_attribute_id', 221100);
            })
            ->leftJoin('users as u', 'u.member_id', '=', 'm.id')
            ->leftJoin('users_contacts as uc', 'uc.user_id', '=', 'u.id')
            ->leftJoin('contacts as c', function ($j) {
                $j->on('c.id', '=', 'uc.contact_id')
                  ->where('c.type', 20); // email contact type
            })
            ->where('m.id', '!=', 1)
            ->where('m.entrance_date', '!=', '0000-00-00')
            ->whereNotNull('m.entrance_date')
            ->where(function ($q) use ($date) {
                $q->where('m.leaving_date', '0000-00-00')
                  ->orWhere('m.leaving_date', '9999-12-31')
                  ->orWhere('m.leaving_date', '>', $date);
            });

        // Imunita nových členů
        if ($immunity > 0) {
            $base->where('m.entrance_date', '<', now()->subDays($immunity)->format('Y-m-d'));
        }

        switch ($messageType) {
            case Message::DEBTOR_MESSAGE: // type 5 - zákazník dlužník
                return $base
                    ->where('m.type', 2)
                    ->where('a.balance', '<', $debtorBoundary)
                    ->select('m.id', 'm.name', DB::raw('MAX(c.value) as email'))
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            case Message::PAYMENT_NOTICE_MESSAGE: // type 6 - zákazník upomínka (balance < tarif)
                return $base
                    ->where('m.type', 2)
                    ->leftJoin('members_fees as mf', function ($j) use ($date) {
                        $j->on('mf.member_id', '=', 'm.id')
                          ->where('mf.activation_date', '<=', $date)
                          ->where(function ($q) use ($date) {
                              $q->whereNull('mf.deactivation_date')
                                ->orWhere('mf.deactivation_date', '>=', $date);
                          });
                    })
                    ->leftJoin('fees as f', 'f.id', '=', 'mf.fee_id')
                    ->whereRaw('a.balance < COALESCE(f.fee, ?, 0)', [
                        (float) Setting::get('default_fee_member_type_2', 0),
                    ])
                    ->select('m.id', 'm.name', DB::raw('MAX(c.value) as email'))
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            case Message::DEBTOR_MESSAGE_CLEN: // type 25 - člen dlužník
                return $base
                    ->where('m.type', 90)
                    ->where('a.balance', '<', $debtorBoundary)
                    ->select('m.id', 'm.name', DB::raw('MAX(c.value) as email'))
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            case Message::PAYMENT_NOTICE_MESSAGE_CLEN: // type 26 - člen upomínka
                return $base
                    ->where('m.type', 90)
                    ->leftJoin('members_fees as mf', function ($j) use ($date) {
                        $j->on('mf.member_id', '=', 'm.id')
                          ->where('mf.activation_date', '<=', $date)
                          ->where(function ($q) use ($date) {
                              $q->whereNull('mf.deactivation_date')
                                ->orWhere('mf.deactivation_date', '>=', $date);
                          });
                    })
                    ->leftJoin('fees as f', 'f.id', '=', 'mf.fee_id')
                    ->whereRaw('a.balance < COALESCE(f.fee, ?, 0)', [
                        (float) Setting::get('default_fee_member_type_90', 0),
                    ])
                    ->select('m.id', 'm.name', DB::raw('MAX(c.value) as email'))
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            default:
                return collect();
        }
    }
}
