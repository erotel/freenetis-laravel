<?php

namespace App\Domains\Members\Support;

use App\Domains\Members\Repositories\MembershipInterruptRepository;
use App\Models\Setting;
use Carbon\Carbon;

/**
 * Pravidla intervalu přerušení: od ≤ do, délka v celých měsících mezi
 * membership_interrupt_minimum a _maximum (0 = bez limitu), bez překryvu
 * s jiným přerušením člena. Vrací první porušení (hláška k poli „from").
 */
class InterruptPeriodPolicy
{
    public function __construct(private MembershipInterruptRepository $interrupts)
    {
    }

    public function violation(int $memberId, Carbon $from, Carbon $to, ?int $ignoreInterruptId = null): ?string
    {
        if ($from->greaterThan($to)) {
            return 'Datum od musí být menší než datum do.';
        }

        $error  = null;
        $months = $from->diffInMonths($to);
        $min    = (int) Setting::get('membership_interrupt_minimum', 0);
        if ($min > 0 && $months < $min) {
            $error = "Minimální délka přerušení je {$min} měsíců.";
        }
        $max = (int) Setting::get('membership_interrupt_maximum', 0);
        if ($max > 0 && $months > $max) {
            $error = "Maximální délka přerušení je {$max} měsíců.";
        }
        if ($error !== null) {
            return $error;
        }

        if ($this->interrupts->overlaps($memberId, $from->toDateString(), $to->toDateString(), $ignoreInterruptId)) {
            return 'Interval přerušení se překrývá s jiným přerušením tohoto člena.';
        }
        return null;
    }
}
