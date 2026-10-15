<?php

namespace App\Domains\Members\Exceptions;

use App\Domains\Billing\Repositories\RefundRepository;

/**
 * Členství nelze ukončit; zpráva je určená přímo pro uživatele. $field = pole
 * formuláře, ke kterému chyba patří (null = celý požadavek, HTTP 400).
 */
class MembershipNotEndable extends \DomainException
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** Už bývalý, nebo ve výpovědní lhůtě — druhé ukončení by vyplatilo vratku znovu. */
    public static function alreadyEnded(): self
    {
        return new self('Člen již byl ukončen.');
    }

    public static function refundExceedsCredit(float $balance): self
    {
        return new self('Ve výpovědní lhůtě nesmí vratka převýšit kredit (' . number_format($balance, 2, ',', ' ') . ' Kč).', 'refund_amount');
    }

    /**
     * Bez bankovního účtu by se odchozí platba nevytvořila, ale kredit by se
     * vynuloval a e-mail odešel — admin by pak hledal „kam zmizely peníze".
     */
    public static function refundBankAccountMissing(int $memberType): self
    {
        $key = RefundRepository::bankAccountSetting($memberType);
        return new self("Není nakonfigurován bankovní účet pro typ člena {$memberType} "
            . "(setting '{$key}'). Nastavte ho v Nastavení → Banka, pak vratku zopakujte.", 'refund_account');
    }
}
