<?php

namespace App\Domains\Contracts\Support;

use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\ContractParty;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * E-mail zákazníkovi po podpisu (na e-mail poslední smluvní strany) s
 * podepsaným PDF. Bez e-mailu / bez PDF = nic; chyba jen do logu.
 */
class PostSignEmails
{
    /**
     * Podepsaná smlouva + ceník + VOP (pokud jsou nastavené a existují).
     * Jen se zapnutým contract_email_attachments_enabled.
     */
    public function contract(Contract $contract): void
    {
        if (!Setting::get('contract_email_attachments_enabled', 0)) {
            return;
        }
        $email = $this->partyEmail($contract->id);
        $pdf   = (string) $contract->pdf_path;
        if ($email === '' || $pdf === '' || !is_file($pdf)) {
            return;
        }

        $attachments = [['path' => $pdf, 'name' => 'smlouva-' . $contract->id . '.pdf']];
        foreach (['contract_email_pricelist_pdf' => 'cenik.pdf', 'contract_email_vop_pdf' => 'vop.pdf'] as $key => $name) {
            $cfg = trim((string) Setting::get($key, ''));
            if ($cfg === '') continue;
            $abs = str_starts_with($cfg, '/') ? $cfg : base_path(ltrim($cfg, '/'));
            if (is_file($abs)) {
                $attachments[] = ['path' => $abs, 'name' => $name];
            }
        }

        $this->send($contract, Message::CONTRACT_SIGNED,
            fn () => 'Podepsaná smlouva ' . $contract->contract_no . ' - PVfree.net',
            fn (string $no) => '<p>Dobrý den,</p>'
                . '<p>v příloze Vám zasíláme podepsanou smlouvu <strong>' . $no . '</strong>'
                . ', ceník služeb a Všeobecné obchodní podmínky.</p>'
                . '<p>Dokumenty si prosím uschovejte pro svoji evidenci.</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>',
            $email, $attachments, 'post_sign_email_sent',
            ['to' => $email, 'attachments' => array_column($attachments, 'name')], 'Post-sign email queue');
    }

    /** Starý dodatek (contracts.addon_pdf_path). */
    public function legacyAddon(Contract $contract): void
    {
        $email = $this->partyEmail($contract->id);
        $pdf   = (string) $contract->addon_pdf_path;
        if ($email === '' || $pdf === '' || !is_file($pdf)) {
            return;
        }

        $this->send($contract, Message::CONTRACT_ADDON_SIGNED,
            fn () => 'Podepsaný dodatek ke smlouvě ' . $contract->contract_no . ' - PVfree.net',
            fn (string $no) => '<p>Dobrý den,</p>'
                . '<p>v příloze Vám zasíláme podepsaný dodatek ke smlouvě <strong>' . $no . '</strong>.</p>'
                . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>',
            $email, [['path' => $pdf, 'name' => 'dodatek-' . $contract->contract_no . '.pdf']],
            'addon_post_sign_email_sent', ['to' => $email], 'Addon post-sign email queue');
    }

    /** Dodatek tarif / služba / přípojné místo. */
    public function addon(Contract $contract, ContractAddon $addon, AddonType $type): void
    {
        $email = $this->partyEmail($contract->id);
        $pdf   = (string) $addon->pdf_path;
        if ($email === '' || $pdf === '' || !is_file($pdf)) {
            return;
        }

        $label = $type->signedLabel();
        $this->send($contract, Message::CONTRACT_ADDON_SIGNED,
            fn () => 'Podepsaný dodatek (' . $label . ') ke smlouvě ' . $contract->contract_no . ' - PVfree.net',
            fn (string $no) => '<p>Dobrý den,</p>'
                . '<p>v příloze Vám zasíláme podepsaný dodatek (' . $label . ') ke smlouvě <strong>' . $no . '</strong>.</p>'
                . '<p>Dokument si prosím uschovejte pro svoji evidenci.</p>'
                . '<p>S pozdravem,<br>PVfree.net, z.s.</p>',
            $email, [['path' => $pdf, 'name' => $type->signedAttachmentPrefix() . $contract->contract_no . '.pdf']],
            $type->downloadFileType() . '_post_sign_email_sent', ['to' => $email, 'addon_id' => $addon->id],
            $type->signedLogLabel() . ' post-sign email');
    }

    private function partyEmail(int $contractId): string
    {
        $party = ContractParty::where('contract_id', $contractId)->orderByDesc('id')->first();
        return trim((string) ($party?->email ?? ''));
    }

    private function send(Contract $contract, int $messageType, \Closure $subject, \Closure $body, string $to,
                          array $attachments, string $event, array $meta, string $logLabel): void
    {
        try {
            [$s, $b] = ContractEmail::render($messageType, (int) $contract->member_id, (string) $contract->contract_no, $subject, $body);
            ContractEmail::queue((int) $contract->id, $to, $s, $b, $attachments, $event, $meta, $logLabel);
        } catch (\Throwable $e) {
            Log::warning($logLabel . ' failed for contract #' . $contract->id . ': ' . $e->getMessage());
        }
    }
}
