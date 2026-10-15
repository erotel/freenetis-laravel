<?php

namespace App\Domains\Members\Support;

/** Údaje pro založení člena (formulář Nový člen). */
final class NewMember
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $surname,
        public readonly int|string $type,          // hodnota z formuláře (audit ji zapisuje tak, jak přišla)
        public readonly string $entranceDate,
        public readonly string $login,
        public readonly string $password,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly int $townId,
        public readonly int $streetId,
        public readonly string $streetNumber,
        public readonly ?string $organizationIdentifier,
        public readonly ?string $vatOrganizationIdentifier,
        public readonly ?string $birthday,
        public readonly ?string $comment,
    ) {
    }

    /** Jméno člena = jméno + příjmení (bez příjmení jen jméno). */
    public function fullName(): string
    {
        return $this->surname ? trim($this->name . ' ' . $this->surname) : $this->name;
    }
}
