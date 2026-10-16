<?php
namespace App\Console\Commands;

use App\Domains\Notifications\Actions\ActivateAutoMessageAction;
use App\Domains\Notifications\Activation\ActivationRule;
use App\Domains\Notifications\Queries\NotificationRecipientsQuery;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Automatická upozornění dlužníkům / upomínky podle pravidel
 * messages_automatical_activations. Cron každou minutu, pravidla se vyhodnotí
 * jen v :10 (Kohana scheduler::AM_NOTIFICATION) → hodinové pravidlo jednou
 * za hodinu. Logika v app/Domains/Notifications.
 */
class NotificationActivation extends Command
{
    protected $signature   = 'notifications:activate {--force : Skip time rule checks}';
    protected $description = 'Activate notifications for debtors, low credit members etc.';

    const APPLY_MINUTE = 10;

    // Typy zpráv, které jde automaticky aktivovat
    const AUTO_TYPES = [
        Message::DEBTOR_MESSAGE,               // 5 - zákazník dlužník
        Message::PAYMENT_NOTICE_MESSAGE,       // 6 - zákazník upomínka
        Message::DEBTOR_MESSAGE_CLEN,          // 25 - člen dlužník
        Message::PAYMENT_NOTICE_MESSAGE_CLEN,  // 26 - člen upomínka
    ];

    public function handle(ActivateAutoMessageAction $activate): int
    {
        if (!Setting::get('finance_enabled', 0)) {
            $this->info('Finance disabled, skipping.');
            return 0;
        }

        $cfg = [
            'debtor_boundary'     => (float) Setting::get('debtor_boundary', 0),
            'big_debtor_boundary' => (float) Setting::get('big_debtor_boundary', 0),
            'immunity'            => (int) Setting::get('initial_debtor_immunity', 0),
            'subject_prefix'      => Setting::get('email_subject_prefix', 'PVfree.net - FreenetIS'),
            'from'                => Setting::get('email_default_email', 'noreply@pvfree.net'),
            'sms_driver'          => (int) Setting::get('sms_driver', 0),
            'sms_sender'          => (string) Setting::get('sms_sender_number', ''),
        ];
        $emailEnabled    = (bool) Setting::get('email_enabled', 0);
        $smsEnabled      = (bool) Setting::get('sms_enabled', 0);
        $redirectEnabled = (bool) Setting::get('redirection_enabled', 0);

        $now       = now();
        $weekday   = $now->dayOfWeek ?: 7; // 1 = Po, 7 = Ne
        $deductDay = (int) Setting::get('deduct_day', 26);

        if (!$this->option('force') && $now->minute !== self::APPLY_MINUTE) {
            return 0;
        }

        foreach (Message::whereIn('type', self::AUTO_TYPES)->get() as $message) {
            $rules = DB::table('messages_automatical_activations')->where('message_id', $message->id)->get();
            if ($rules->isEmpty()) continue;

            // Víc platných pravidel = OR kanálů → JEDNA sada notifikací, ne N (jako Kohana).
            $email = $sms = $redirect = false;
            $reportTo = [];
            foreach ($rules as $rule) {
                if (!$this->option('force') && !ActivationRule::isActive($rule, $now->day, $now->hour, $weekday, $deductDay)) {
                    continue;
                }
                $email    = $email    || (bool) $rule->email_enabled;
                $sms      = $sms      || (bool) $rule->sms_enabled;
                $redirect = $redirect || (bool) $rule->redirection_enabled;
                foreach (explode(',', (string) $rule->send_activation_to_email) as $e) {
                    if (($e = trim($e)) !== '') $reportTo[$e] = true;
                }
            }

            $doEmail    = $email    && $emailEnabled;
            $doSms      = $sms      && $smsEnabled && $cfg['sms_driver'] && $cfg['sms_sender'] !== '';
            $doRedirect = $redirect && $redirectEnabled;
            if (!$doEmail && !$doSms && !$doRedirect) continue;

            $count = $activate->handle($message, $doEmail, $doSms, $doRedirect, array_keys($reportTo), $cfg);
            $this->info($count === null
                ? "Message [{$message->id}] {$message->name}: no members to notify."
                : "Message [{$message->id}] {$message->name}: {$count} members.");
        }

        return 0;
    }

    /** Příjemci podle typu zprávy — viz NotificationRecipientsQuery (ponecháno kvůli testům). */
    private function getMembersToNotify(int $messageType, float $debtorBoundary, float $bigDebtorBoundary, int $immunity): \Illuminate\Support\Collection
    {
        return app(NotificationRecipientsQuery::class)->forMessageType($messageType, $debtorBoundary, $bigDebtorBoundary, $immunity);
    }
}
