<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\AddonEffects;
use App\Domains\Contracts\Support\AddonType;
use App\Domains\Contracts\Support\ContractEventLog;
use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\ContractParty;
use App\Models\EmailQueue;
use App\Models\EmailQueueAttachment;
use App\Models\Setting;
use App\Services\Contracts\PdfService;
use Illuminate\Support\Facades\Log;

/**
 * Vydání dodatku o ZRUŠENÍ služby / přípojného místa poskytovatelem — bez
 * podpisu zákazníka (zrušení jen snižuje jeho závazek, proto bez OTP):
 * PDF, status signed, deaktivace na členovi, PDF zákazníkovi e-mailem
 * (best-effort). Jen service_action='remove' a ještě nevydané.
 */
class IssueAddonRemovalAction
{
    public function __construct(private AddonEffects $effects)
    {
    }

    public function handle(ContractAddon $addon, AddonType $type, PdfService $pdf): ?ContractAddon
    {
        if ($type === AddonType::TariffChange || $addon->type !== $type->value
            || $addon->service_action !== 'remove' || $addon->status === 'signed') {
            return null;
        }

        SignAddonAction::finalize($addon, $type, $pdf, null, $type->eventPrefix() . '_removal_issued');

        if ($type === AddonType::AdditionalService && $addon->fee_id) {
            $this->effects->removeServiceFee($addon);
        }
        if ($type === AddonType::ConnectionPoint && $addon->allowed_subnet_id) {
            $this->effects->setPlaceCharged($addon, false);
        }

        $this->email($addon, $type);

        return $addon->refresh();
    }

    /** Best-effort: PDF zrušení na e-mail smluvní strany (chyba jen do logu). */
    private function email(ContractAddon $addon, AddonType $type): void
    {
        $contract = Contract::find($addon->contract_id);
        if (!$contract) return;

        $party   = ContractParty::where('contract_id', $addon->contract_id)->orderByDesc('id')->first();
        $email   = trim((string) ($party?->email ?? ''));
        $pdfPath = (string) $addon->pdf_path;
        if ($email === '' || $pdfPath === '' || !is_file($pdfPath)) return;

        try {
            [$subject, $what, $attachmentPrefix] = $type->removalEmail((string) $contract->contract_no);
            $esc  = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body = '<p>Dobrý den,</p>'
                . '<p>v příloze Vám zasíláme dodatek o zrušení ' . $what . ' <strong>' . $esc($addon->service_name) . '</strong> ke smlouvě <strong>' . $esc($contract->contract_no) . '</strong>'
                . ' s účinností od ' . $esc($addon->effective_date?->format('d.m.Y')) . '.</p>'
                . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>';

            $q = EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => IssueSignLinkAction::subject($subject),
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);
            EmailQueueAttachment::create([
                'email_queue_id' => $q->id,
                'path'           => $pdfPath,
                'name'           => $attachmentPrefix . $contract->contract_no . '.pdf',
                'mime'           => 'application/pdf',
                'created_at'     => now(),
            ]);
            ContractEventLog::record($contract->id, $type->eventPrefix() . '_removal_email_sent',
                ['to' => $email, 'addon_id' => $addon->id], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            Log::warning($type->removalLogLabel() . ' email failed for contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }
}
