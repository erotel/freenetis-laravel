<?php

namespace App\Domains\Billing\Support;

/** Typy převodů (`transfers.type`) — shodné s Kohana Transfer_Model. */
final class TransferType
{
    public const MEMBER_FEE             = 1;
    public const ENTRANCE_FEE           = 2;
    public const DEVICE_FEE             = 5;
    public const ADDITIONAL_SERVICE_FEE = 6; // dodatečné služby (např. veřejná IP)
}
