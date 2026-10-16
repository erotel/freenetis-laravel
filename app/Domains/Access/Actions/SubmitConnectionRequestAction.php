<?php

namespace App\Domains\Access\Actions;

use App\Domains\Access\Pppoe\PppoeCredentialGenerator;
use App\Domains\Access\Repositories\ConnectionRequestRepository;
use App\Domains\Notifications\Actions\SendTemplateToMemberAction;
use App\Models\ConnectionRequest;
use App\Models\EmailQueue;
use App\Models\Member;
use App\Models\Setting;

/**
 * Podání žádosti o připojení (technikem s IP/subnetem, nebo samoobslužně —
 * IP doplní technik). Se zapnutým PPPoE dostane žádost rovnou credential
 * (instalátor ho opíše do CPE, při schválení se překlopí do pppoe_secrets).
 * Notifikace správci (connection_request_notify_email) + potvrzení žadateli
 * (zpráva 12). Validaci a oprávnění řeší volající.
 */
class SubmitConnectionRequestAction
{
    private const MESSAGE_RECEIVED = 12;

    public function __construct(
        private ConnectionRequestRepository $requests,
        private SendTemplateToMemberAction $sendTemplate,
    ) {
    }

    /**
     * @param array{ip_address:?string, subnet_id:?int, mac_address:string, device_type_id:?int,
     *              device_template_id:?int, comment:?string} $data
     * @param bool $selfService samoobslužná (jiný text notifikace)
     */
    public function handle(int $memberId, ?int $addedBy, array $data, bool $selfService): ConnectionRequest
    {
        $cr = $this->requests->createPending([
            'member_id'     => $memberId,
            'added_user_id' => $addedBy,
        ] + $data + $this->pppoeCredential($memberId));

        $this->notifyNoc($cr, $memberId, $data, $selfService);

        $this->sendTemplate->handle(self::MESSAGE_RECEIVED, $memberId, [
            'member_name' => Member::find($memberId)?->name ?? '',
            'comment'     => $data['comment'] ?? '',
        ]);

        return $cr;
    }

    /** @return array{pppoe_username: ?string, pppoe_secret: ?string} */
    private function pppoeCredential(int $memberId): array
    {
        if (!Setting::get('pppoe_enabled', 0)) {
            return ['pppoe_username' => null, 'pppoe_secret' => null];
        }
        $generator = app(PppoeCredentialGenerator::class);
        return ['pppoe_username' => $generator->usernameFor($memberId), 'pppoe_secret' => $generator->secret()];
    }

    private function notifyNoc(ConnectionRequest $cr, int $memberId, array $data, bool $selfService): void
    {
        $to = Setting::get('connection_request_notify_email');
        if (!$to) {
            return;
        }
        $name = Member::find($memberId)?->name ?? "#{$memberId}";

        [$subject, $body] = $selfService
            ? ['Nová žádost o připojení — ' . $name,
               'Byla podána nová žádost o připojení (self-service).'
                . "\n\nČlen: " . $name
                . "\nIP adresa: " . ($data['ip_address'] ?? '— doplní technik')
                . "\nMAC adresa: " . $data['mac_address']
                . "\nPoznámka: " . ($data['comment'] ?? '—')]
            : ['Nová žádost o připojení — ' . $data['ip_address'],
               'Byla podána nová žádost o připojení.'
                . "\n\nČlen: " . $name
                . "\nIP adresa: " . $data['ip_address']
                . "\nMAC adresa: " . $data['mac_address']];

        EmailQueue::create([
            'from'    => Setting::get('email_default_email', 'freenetis@localhost'),
            'to'      => $to,
            'subject' => $subject,
            'body'    => $body . "\n\nDetail: " . route('connection_requests.show', $cr->id),
            'state'   => EmailQueue::STATE_NEW,
        ]);
    }
}
