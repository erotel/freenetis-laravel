<?php

namespace App\Domains\Members\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Members\Repositories\PasswordResetRepository;
use App\Models\Setting;
use Illuminate\Support\Str;

/**
 * Žádost o reset hesla: najde účet podle loginu, jinak podle e-mailu (jen
 * když zbude právě jeden aktivní účet), jinak podle VS (hlavní uživatel
 * člena). Účet s e-mailem dostane odkaz s tokenem (40 znaků, platnost
 * TOKEN_TTL_MIN); do DB jde jen SHA-256 tokenu. Volající odpovídá vždy
 * stejně — výsledek se ven nehlásí (bez enumerace účtů).
 */
class RequestPasswordResetAction
{
    public const TOKEN_TTL_MIN = 15;

    public function __construct(private PasswordResetRepository $resets)
    {
    }

    public function handle(string $input): void
    {
        $user = $this->locate(trim($input));
        if (!$user) {
            return;
        }
        $email = $this->resets->email((int) $user->id);
        if (!$email) {
            return;
        }

        $token = Str::random(40);
        $this->resets->setToken((int) $user->id, hash('sha256', $token), now()->addMinutes(self::TOKEN_TTL_MIN));
        AuditLogger::log('updated', 'users', (int) $user->id, null, ['password_reset_requested' => true]);

        $this->queueEmail($email, $token);
    }

    private function locate(string $input): ?object
    {
        if ($user = $this->resets->userByLogin($input)) {
            return $user;
        }
        $byEmail = $this->resets->activeUserIdsByEmail($input);
        if ($byEmail->count() === 1) {
            return $this->resets->user((int) $byEmail->first());
        }
        return ctype_digit($input) ? $this->resets->mainUserByVariableSymbol($input) : null;
    }

    private function queueEmail(string $email, string $token): void
    {
        $siteTitle = Setting::get('title', 'FreenetIS');
        $urlHtml   = htmlspecialchars(route('forgotten-password.reset') . '?request=' . $token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleHtml = htmlspecialchars($siteTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $body = '<p>Dobrý den,</p>'
            . '<p>pro reset hesla klikněte na tento odkaz (platnost ' . self::TOKEN_TTL_MIN . ' minut):</p>'
            . '<p><a href="' . $urlHtml . '">' . $urlHtml . '</a></p>'
            . '<p>Pokud jste o reset hesla nežádali, ignorujte tento email.</p>'
            . '<p>' . $titleHtml . '</p>';

        $this->resets->queueEmail(Setting::get('email_default_email', 'noreply@freenetis.org'), $email, $siteTitle . ' :: Reset hesla', $body);
    }
}
