<?php

namespace App\Domains\Contracts\Actions;

use App\Domains\Contracts\Support\MemberContacts;
use App\Domains\Contracts\Support\SignLinkKind;
use App\Domains\Contracts\Support\SignLinkToken;
use App\Models\Contract;
use App\Models\ContractAddon;
use App\Models\ContractEvent;
use App\Models\EmailQueue;
use App\Models\Message;
use App\Models\Setting;

/**
 * Vydá podpisový odkaz (smlouva / dodatek) platný 7 dní, zapíše událost
 * a pošle ho e-mailem hlavnímu uživateli člena. Nevyřízený dodatek (draft)
 * přejde do otp_sent. Bez e-mailu odkaz vznikne, jen se neodešle.
 */
class IssueSignLinkAction
{
    public function __construct(private SignLinkToken $tokens)
    {
    }

    /** @return array{url: string, email_sent: bool, email: ?string} */
    public function handle(int $contractId, SignLinkKind $kind, ?ContractAddon $addon = null): array
    {
        $claims = ['cid' => $contractId];
        if ($addon) {
            $claims['aid'] = (int) $addon->id;
        }
        $token = $this->tokens->issue($claims);

        if ($addon && $addon->status === 'draft') {
            $addon->update(['status' => 'otp_sent']);
        }

        $meta = $addon ? ['addon_id' => (int) $addon->id] : [];
        ContractEvent::create([
            'contract_id' => $contractId,
            'event'       => $kind->issuedEvent(),
            'meta_json'   => json_encode($meta + [
                'by'    => auth()->user()?->login,
                'token' => SignLinkToken::preview($token),
            ]),
        ]);

        $url   = route($kind->route(), ['t' => $token]);
        $email = $this->queueEmail($contractId, $url, $kind->isAddon());

        return ['url' => $url, 'email_sent' => $email !== null, 'email' => $email];
    }

    /**
     * E-mail s odkazem ze systémové šablony (admin ji edituje v Sdělení); chybí-li
     * nebo je prázdná, záložní text — podpisový flow nesmí nikdy ztichnout.
     * @return ?string adresát, nebo null (není e-mail / chyba fronty)
     */
    private function queueEmail(int $contractId, string $url, bool $isAddon): ?string
    {
        $contract = Contract::find($contractId);
        if (!$contract) {
            return null;
        }

        [, $email] = MemberContacts::of(MemberContacts::mainUser($contract->member_id));
        if (!$email) {
            return null;
        }

        $tpl = Message::where('type', $isAddon ? Message::CONTRACT_ADDON_SIGN_LINK : Message::CONTRACT_SIGN_LINK)->first();
        if ($tpl && trim((string) $tpl->email_text) !== '') {
            $body    = Message::substitute($tpl->email_text, ['url' => $url]);
            $subject = self::subject($tpl->name);
        } else {
            $urlHtml = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $what    = $isAddon ? 'dodatku smlouvy' : 'smlouvy';
            $subject = self::subject('Odkaz pro podpis ' . $what);
            $body = '<p>Dobrý den,</p>'
                . '<p>zasíláme Vám odkaz pro elektronický podpis ' . $what . ':</p>'
                . '<p><a href="' . $urlHtml . '">' . $urlHtml . '</a></p>'
                . '<p>Odkaz je platný 7 dní.</p>'
                . '<p>S pozdravem<br>PVfree.net</p>';
        }

        try {
            EmailQueue::create([
                'from'        => Setting::get('email_default_email', 'noreply@pvfree.net'),
                'to'          => $email,
                'subject'     => $subject,
                'body'        => $body,
                'state'       => EmailQueue::STATE_NEW,
                'access_time' => now(),
            ]);

            ContractEvent::create([
                'contract_id' => $contractId,
                'event'       => 'email_sent',
                'meta_json'   => json_encode([
                    'by'    => auth()->user()?->login,
                    'to'    => $email,
                    'addon' => $isAddon,
                ]),
            ]);

            return $email;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Předmět s prefixem systému („PREFIX :: Název") jako ostatní e-maily. */
    public static function subject(string $name): string
    {
        $prefix = (string) Setting::get('email_subject_prefix', '');
        return ($prefix !== '' ? $prefix . ' :: ' : '') . $name;
    }
}
