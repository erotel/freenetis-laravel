<?php

namespace App\Domains\Billing\Support;

/** `accounts.account_attribute_id` — druhy podvojných účtů. */
final class AccountAttribute
{
    public const CREDIT         = 221100; // kreditní účet člena
    public const OPERATING      = 221101; // provozní účet organizace (member 1)
    public const INFRASTRUCTURE = 221102; // infrastrukturní účet organizace (member 1)
}
