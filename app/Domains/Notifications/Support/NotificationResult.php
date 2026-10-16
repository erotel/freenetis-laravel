<?php

namespace App\Domains\Notifications\Support;

/** Souhrn rozeslání upozornění. */
final class NotificationResult
{
    public int $redirectsActivated = 0;
    public int $redirectsDeactivated = 0;
    public int $emails = 0;
    public int $sms = 0;

    /** Hláška pro uživatele („Přesměrování aktivováno pro N IP. Odesláno …"). */
    public function summary(): string
    {
        $parts = [];
        if ($this->redirectsActivated)   $parts[] = "Přesměrování aktivováno pro {$this->redirectsActivated} IP.";
        if ($this->redirectsDeactivated) $parts[] = "Přesměrování deaktivováno pro {$this->redirectsDeactivated} IP.";
        if ($this->emails)               $parts[] = "Odesláno {$this->emails} e-mailů.";
        if ($this->sms)                  $parts[] = "Odesláno {$this->sms} SMS.";
        return $parts ? implode(' ', $parts) : 'Žádná akce nebyla provedena.';
    }
}
