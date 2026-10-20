<?php

namespace App\Http\Controllers;

use App\Domains\Notifications\Queries\RedirectionPageQuery;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Public captive-portal endpoint hit by fn-redirector on the IGW.
 *
 * Suspendovaní členové jsou na routeru přesměrováni přes nftables REDIRECT
 * a fn-redirector vrátí 302 na /redirection?redirect_to=<orig>. Klient přijde
 * napřímo (žádný proxy hop), takže request()->ip() je reálná IP klienta.
 *
 * NEZAMĚŇOVAT s App\Http\Controllers\RedirectController (admin CRUD).
 */
class RedirectionController extends Controller
{
    public function show(Request $request, RedirectionPageQuery $page): Response
    {
        $clientIp = (string) $request->ip();

        // 1) Najdi aktivní redirection message pro tuto IP.
        //    Záznam v messages_ip_addresses existuje jen po dobu aktivního
        //    přesměrování — existence řádku = aktivní (žádný from/to_date).
        $row = $page->activeFor($clientIp);

        // 2) Žádný záznam v messages_ip_addresses → rozdělíme dva stavy:
        //    - IP není vůbec v ip_addresses    → neevidované zařízení, msg type 3
        //    - IP je evidovaná, ale bez flagu  → fn-redirector ji sem neměl poslat,
        //                                        ukážeme generickou "vše v pořádku".
        if (!$row) {
            $isKnownIp = $page->isKnownIp($clientIp);

            if (!$isKnownIp) {
                $unknownMsg = $page->unknownDeviceMessage();
                Log::info('redirection: unknown device IP', [
                    'client_ip' => $clientIp,
                    'has_msg_template' => (bool) $unknownMsg,
                ]);

                if ($unknownMsg && trim((string) $unknownMsg->text) !== '') {
                    return $this->render([
                        'has_message'     => true,
                        'client_ip'       => $clientIp,
                        'message_name'    => $unknownMsg->name,
                        'message_text'    => (string) $unknownMsg->text,
                        'comment'         => null,
                        'redirected_at'   => null,
                        'member'          => null,
                        'balance'         => null,
                        'expiration_date' => null,
                        'variable_symbol' => null,
                        'support'         => $this->supportContact(),
                    ]);
                }
            }

            Log::info('redirection: no active message', [
                'client_ip'    => $clientIp,
                'is_known_ip'  => $isKnownIp,
            ]);

            return $this->render([
                'has_message' => false,
                'client_ip'   => $clientIp,
                'support'     => $this->supportContact(),
            ]);
        }

        // 3) Načti člena + saldo + expirationDate (pokud je member_id známý)
        $member         = null;
        $balance        = null;
        $expirationDate = null;
        $variableSymbol = null;

        if ($row->member_id) {
            ['member' => $member, 'balance' => $balance, 'expiration_date' => $expirationDate, 'variable_symbol' => $variableSymbol]
                = $page->member((int) $row->member_id);
        }

        // Substituce placeholderů typu {member_name}, {variable_symbol}, …
        // Bez známého člena placeholdery zůstanou v textu jako-jsou.
        $messageText = (string) $row->message_text;
        if ($row->member_id) {
            $vars = app(\App\Domains\Notifications\Support\MemberPlaceholders::class)->forMember((int) $row->member_id, [
                'variable_symbol' => $variableSymbol ?? '',
            ]);
            $messageText = Message::substitute($messageText, $vars);
        }

        Log::info('redirection: hit', [
            'client_ip'  => $clientIp,
            'member_id'  => $row->member_id,
            'message_id' => $row->message_id,
        ]);

        return $this->render([
            'has_message'     => true,
            'client_ip'       => $clientIp,
            'message_name'    => $row->message_name,
            'message_text'    => $messageText,
            'comment'         => $row->comment,
            'redirected_at'   => $row->redirected_at,
            'member'          => $member,
            'balance'         => $balance,
            'expiration_date' => $expirationDate,
            'variable_symbol' => $variableSymbol,
            'support'         => $this->supportContact(),
        ]);
    }

    private function render(array $data): Response
    {
        $html = view('redirection.show', $data)->render();

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    private function supportContact(): array
    {
        return [
            'org_name' => Setting::get('title', 'FreenetIS'),
            'email'    => Setting::get('email_default_email', ''),
            'phone'    => Setting::get('phone_support', '588 207 234'),
        ];
    }
}
