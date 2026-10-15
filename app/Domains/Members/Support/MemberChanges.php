<?php

namespace App\Domains\Members\Support;

/** Údaje z formuláře úpravy člena. */
final class MemberChanges
{
    public function __construct(
        public readonly string $name,
        public readonly int $type,
        public readonly int|string $typeInput,     // typ tak, jak přišel (ukládá se do modelu beze změny)
        public readonly ?string $entranceDate,
        public readonly ?string $leavingDate,      // null = neurčeno (9999-12-31)
        public readonly ?string $comment,
        public readonly ?string $organizationIdentifier,
        public readonly ?string $vatOrganizationIdentifier,
        public readonly bool $locked,
        public readonly bool $registration,
        public readonly int|string|null $speedClassId,
        public readonly bool $canVote,
        public readonly bool $notificationByRedirection,
        public readonly bool $notificationByEmail,
        public readonly bool $notificationBySms,
        public readonly ?int $townId,              // null = adresa se nemění
        public readonly int|string|null $streetId,
        public readonly ?string $streetNumber,
    ) {
    }
}
