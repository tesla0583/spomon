<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PartyType;

/**
 * Сторона СПО — физическое лицо (`side_section` с заполненными
 * `doc_number` + `first_name` + `last_name`).
 */
final class IndividualPartyDto implements PartyDataInterface
{
    public function __construct(
        public readonly string $docNumber,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $middleName = null,
        public readonly ?string $dob = null,
    ) {}

    public function partyType(): PartyType
    {
        return PartyType::Individual;
    }
}
