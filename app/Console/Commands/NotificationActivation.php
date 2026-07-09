<?php
namespace App\Console\Commands;

use App\Models\Message;
use App\Models\Setting;
use App\Services\PaymentQrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotificationActivation extends Command
{
    protected $signature   = 'notifications:activate {--force : Skip time rule checks}';
    protected $description = 'Activate notifications for debtors, low credit members etc.';

    // Mirrors Kohana scheduler::AM_NOTIFICATION — rules only fire on this minute of the hour,
    // so an hour-matching rule sends exactly once per hour instead of 60× when cron runs every minute.
    const APPLY_MINUTE = 10;

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
        $smsDriver          = (int) Setting::get('sms_driver', 0);
        $smsSender          = (string) Setting::get('sms_sender_number', '');

        $now       = now();
        $today     = $now->day;
        $hour      = $now->hour;
        $weekday   = $now->dayOfWeek ?: 7; // 1=Mon, 7=Sun
        $deductDay = (int) Setting::get('deduct_day', 26);

        // Gate: only run on the apply minute so each rule fires once per hour, not every minute.
        if (!$this->option('force') && $now->minute !== self::APPLY_MINUTE) {
            return 0;
        }

        $messages = Message::whereIn('type', self::AUTO_TYPES)->get();

        foreach ($messages as $message) {
            $rules = DB::table('messages_automatical_activations')
                ->where('message_id', $message->id)
                ->get();

            if ($rules->isEmpty()) continue;

            // Agregace přes OR — pokud má zpráva víc pravidel, která zrovna matchují
            // (typicky --force nebo dvě pravidla na stejnou minutu), pošleme JEDNU sadu
            // notifikací, ne N. Stejné chování má i Kohana Scheduler_Controller::notification_activation.
            $aEmail    = false;
            $aSms      = false;
            $aRedirect = false;
            $reportTo  = [];

            foreach ($rules as $rule) {
                if (!$this->option('force') && !$this->isRuleActive($rule, $today, $hour, $weekday, $deductDay)) {
                    continue;
                }
                $aEmail    = $aEmail    || (bool) $rule->email_enabled;
                $aSms      = $aSms      || (bool) $rule->sms_enabled;
                $aRedirect = $aRedirect || (bool) $rule->redirection_enabled;
                if (!empty($rule->send_activation_to_email)) {
                    foreach (explode(',', $rule->send_activation_to_email) as $e) {
                        $e = trim($e);
                        if ($e !== '') $reportTo[$e] = true;
                    }
                }
            }

            $doEmail    = $aEmail    && $emailEnabled;
            $doSms      = $aSms      && $smsEnabled && $smsDriver && $smsSender !== '';
            $doRedirect = $aRedirect && $redirectEnabled;

            if (!$doEmail && !$doSms && !$doRedirect) continue;

            $members = $this->getMembersToNotify($message->type, $debtorBoundary, $bigDebtorBoundary, $debtorImmunity);

            if ($members->isEmpty()) {
                $this->info("Message [{$message->id}] {$message->name}: no members to notify.");
                continue;
            }

            $this->info("Message [{$message->id}] {$message->name}: {$members->count()} members.");

            $emailsSent   = 0;
            $smsSent      = 0;
            $ipsRedirect  = 0;
            $ipsDeleted   = 0;
            $skippedOptOut    = ['email' => 0, 'sms' => 0, 'redirect' => 0];
            $skippedWhitelist = 0;

            // Načti opt-out flagy pro všechny aktuální adresáty najednou.
            // Členové si tohle nastavují přes /me/notifications nebo admin přes edit;
            // automatický cron musí jejich volbu tvrdě respektovat (na rozdíl od
            // hromadného UI, kde admin volbu vidí jen jako varování).
            $memberIds = $members->pluck('id')->all();
            $optOut    = DB::table('members')
                ->whereIn('id', $memberIds)
                ->get(['id', 'notification_by_redirection', 'notification_by_email', 'notification_by_sms'])
                ->keyBy('id');

            // Bílá listina — admin může individuálně vyjmout člena z auto-přesměrování
            // (typicky když ručně řeší platbu / poskytl odklad). Pokud má message
            // ignore_whitelist=1 (kritické zprávy), listinu ignorujeme. Konzistentní
            // s NotificationController::membersNotify.
            $whitelisted = (int) ($message->ignore_whitelist ?? 0) === 1
                ? []
                : DB::table('members_whitelists')
                    ->whereIn('member_id', $memberIds)
                    ->where('since', '<=', $today)
                    ->where('until', '>=', $today)
                    ->pluck('member_id')
                    ->flip()
                    ->toArray();

            // Clean slate před přepsáním redirectu: smažeme všechny existující řádky
            // messages_ip_addresses pro tuto zprávu a o pár řádků níž je naplníme
            // znovu jen pro aktuální dlužníky. Tím:
            //   - člen, co mezitím doplatil, vypadne z přesměrování,
            //   - člen, co si redirect odklikl přes self_cancel, ho do hodiny dostane
            //     zpět, dokud má dluh (= chování Kohana Notifications_Controller::notify
            //     s $truncate_redir=true).
            if ($doRedirect) {
                $ipsDeleted = DB::table('messages_ip_addresses')
                    ->where('message_id', $message->id)
                    ->delete();
            }

            foreach ($members as $member) {
                $optOutRow   = $optOut[$member->id] ?? null;
                $memberEmail = !$optOutRow || (int) $optOutRow->notification_by_email;
                $memberSms   = !$optOutRow || (int) $optOutRow->notification_by_sms;
                $memberRedir = !$optOutRow || (int) $optOutRow->notification_by_redirection;

                if ($doEmail && !empty($member->email) && !$memberEmail) { $skippedOptOut['email']++; }
                if ($doSms   && !empty($member->phone) && !$memberSms)   { $skippedOptOut['sms']++; }
                if ($doRedirect && !$memberRedir)                        { $skippedOptOut['redirect']++; }

                if ($doEmail && !empty($member->email) && $memberEmail) {
                    $subject = ($subjectPrefix ? $subjectPrefix . ' :: ' : '') . $message->name;
                    $body    = Message::substitute(
                        $message->email_text ?? $message->text ?? '',
                        Message::buildPlaceholders((int) $member->id)
                    );

                    // QR platba: pokud šablona obsahuje {payment_qr}, vygenerujeme QR podle
                    // tarifu člena a vložíme ho jako inline (cid) obrázek — spolehlivě se
                    // zobrazí i v Gmailu. Když QR nejde sestavit (chybí účet/VS/tarif),
                    // placeholder jen zmizí.
                    $qrPng = null;
                    if (str_contains($body, '{payment_qr}')) {
                        $qrPng = app(PaymentQrService::class)->pngBytesForMember((int) $member->id);
                        $body  = str_replace(
                            '{payment_qr}',
                            $qrPng
                                ? '<img src="cid:payment_qr.png" alt="QR platba" width="200" height="200" style="width:200px;height:200px">'
                                : '',
                            $body
                        );
                    }

                    $emailQueueId = DB::table('email_queues')->insertGetId([
                        'from'    => $fromEmail,
                        'to'      => $member->email,
                        'subject' => $subject,
                        'body'    => $body,
                        'state'   => 0,
                    ]);
                    $emailsSent++;

                    if ($qrPng !== null) {
                        $this->attachInlineQr($emailQueueId, $qrPng);
                    }
                }

                if ($doSms && !empty($member->phone) && $memberSms) {
                    $text = Message::substitute(
                        $message->sms_text ?? $message->text ?? '',
                        Message::buildPlaceholders((int) $member->id)
                    );
                    DB::table('sms_messages')->insert([
                        'user_id'        => 1,
                        'sms_message_id' => null,
                        'stamp'          => now(),
                        'send_date'      => now(),
                        'text'           => $text,
                        'sender'         => $smsSender,
                        'receiver'       => $member->phone,
                        'driver'         => $smsDriver,
                        'type'           => 1,
                        'state'          => 1,
                    ]);
                    $smsSent++;
                }

                if ($doRedirect && $memberRedir) {
                    // Bílá listina má přednost před dluhem: whitelistovanému členovi
                    // se přesměrování nezapíše, i když má nedoplatek. Zprávy s
                    // ignore_whitelist=1 tenhle check obchází (v $whitelisted už
                    // v takovém případě není žádný záznam — viz načítání výš).
                    if (isset($whitelisted[$member->id])) {
                        $skippedWhitelist++;
                        continue;
                    }

                    $ipIds = DB::table('ip_addresses as ip')
                        ->join('ifaces as i', 'i.id', '=', 'ip.iface_id')
                        ->join('devices as d', 'd.id', '=', 'i.device_id')
                        ->join('users as u', 'u.id', '=', 'd.user_id')
                        ->where('u.member_id', $member->id)
                        ->pluck('ip.id');

                    foreach ($ipIds as $ipId) {
                        DB::table('messages_ip_addresses')->insert([
                            'message_id'    => $message->id,
                            'ip_address_id' => $ipId,
                            'user_id'       => 1,
                            'comment'       => 'Auto-aktivace: ' . $message->name,
                            'datetime'      => now(),
                        ]);
                        $ipsRedirect++;
                    }
                }
            }

            // Log to log_queues (mirrors Kohana Log_queue_Model::info — type=3, state=0,
            // stats stored in exception_backtrace column, same as Kohana did).
            // Logujeme vždy, když některý kanál byl aktivovaný — i kdyby čísla
            // vyšla 0/0 (např. žádní dlužníci) — aby admin viděl, že běh proběhl.
            if ($doEmail || $doSms || $doRedirect) {
                $stats = [];
                if ($doRedirect) {
                    $stats[] = "Přesměrování bylo deaktivováno pro {$ipsDeleted} IP adres.";
                    $stats[] = "Přesměrování bylo aktivováno pro {$ipsRedirect} IP adres.";
                }
                if ($doEmail) $stats[] = "E-mail byl odeslán pro {$emailsSent} e-mailových adres.";
                if ($doSms)   $stats[] = "SMS zpráva byla odeslána pro {$smsSent} telefonních čísel.";
                $optOutTotal = $skippedOptOut['email'] + $skippedOptOut['sms'] + $skippedOptOut['redirect'];
                if ($optOutTotal > 0) {
                    $stats[] = "Vynecháno kvůli vlastnímu odhlášení člena: "
                        . "{$skippedOptOut['email']} e-mailů, "
                        . "{$skippedOptOut['sms']} SMS, "
                        . "{$skippedOptOut['redirect']} přesměrování.";
                }
                if ($skippedWhitelist > 0) {
                    $stats[] = "Přesměrování vynecháno kvůli bílé listině: {$skippedWhitelist} členů.";
                }

                DB::table('log_queues')->insert([
                    'type'                => 3, // TYPE_INFO
                    'state'               => 0, // STATE_NEW
                    'created_at'          => now(),
                    'description'         => 'Upozorňovací zpráva "' . $message->name . '" byla automaticky aktivována',
                    'exception_backtrace' => implode("\n", $stats),
                ]);
            }

            foreach (array_keys($reportTo) as $email) {
                $subject = ($subjectPrefix ? $subjectPrefix . ' :: ' : '') . 'Aktivace zprávy: ' . $message->name;
                $body    = "Aktivována zpráva: {$message->name}\n";
                $body   .= "Počet členů: {$members->count()}\n";
                $body   .= "Datum: " . now()->format('d.m.Y H:i') . "\n";
                DB::table('email_queues')->insert([
                    'from'    => $fromEmail,
                    'to'      => $email,
                    'subject' => $subject,
                    'body'    => nl2br(htmlspecialchars($body)),
                    'state'   => 0,
                ]);
            }
        }

        return 0;
    }

    /** Uloží QR PNG do storage a zaeviduje ho jako inline (cid) přílohu e-mailu. */
    private function attachInlineQr(int $emailQueueId, string $pngBytes): void
    {
        $dir = storage_path('app/private/qr');
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $path = $dir . '/qr_' . $emailQueueId . '.png';
        file_put_contents($path, $pngBytes);

        DB::table('email_queue_attachments')->insert([
            'email_queue_id' => $emailQueueId,
            'path'           => $path,
            'name'           => 'payment_qr.png',
            'mime'           => 'image/png',
            'inline'         => 1,
            'created_at'     => now(),
        ]);
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
                  ->whereIn('c.type', [20, 21]); // 20=email, 21=phone
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
            case Message::DEBTOR_MESSAGE: // type 5 - zákazník 'Nedostatečná výše konta'
                // Prepaid: zahrň i payment_blocked=1 i kdyby balance ≥ 0
                // (DeductFees nestrhl, takže technicky není v mínusu, ale stále
                // nemá dostatek kreditu na další měsíc).
                return $base
                    ->where('m.type', 2)
                    ->where(function ($q) use ($debtorBoundary) {
                        $q->where('a.balance', '<', $debtorBoundary)
                          ->orWhere('m.payment_blocked', 1);
                    })
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
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
                        $this->defaultFeeAmount(2),
                    ])
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            case Message::DEBTOR_MESSAGE_CLEN: // type 25 - člen 'Nedostatečná výše konta'
                return $base
                    ->where('m.type', 90)
                    ->where(function ($q) use ($debtorBoundary) {
                        $q->where('a.balance', '<', $debtorBoundary)
                          ->orWhere('m.payment_blocked', 1);
                    })
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
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
                        $this->defaultFeeAmount(90),
                    ])
                    ->select(
                        'm.id', 'm.name',
                        DB::raw('MAX(CASE WHEN c.type = 20 THEN c.value END) as email'),
                        DB::raw('MAX(CASE WHEN c.type = 21 THEN c.value END) as phone')
                    )
                    ->groupBy('m.id', 'm.name', 'a.balance')
                    ->get();

            default:
                return collect();
        }
    }

    /**
     * Setting default_fee_member_type_{2|90} drží fee_id (FK do fees), ne částku.
     * Vrací skutečnou částku z fees.fee podle uložené id, jinak 0.
     */
    private function defaultFeeAmount(int $memberType): float
    {
        $feeId = (int) Setting::get("default_fee_member_type_{$memberType}", 0);
        if ($feeId <= 0) return 0.0;
        return (float) (DB::table('fees')->where('id', $feeId)->value('fee') ?? 0);
    }
}
