<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PartyType;

/**
 * Сторона СПО — юридическое лицо (`side_section` с заполненными
 * `tax_pay_number` + `name`).
 */
final class LegalEntityPartyDto implements PartyDataInterface
{
    public function __construct(
        public readonly string $taxPayNumber,
        public readonly string $name,
        public readonly ?string $legOrgForm = null,
    ) {}

    public function partyType(): PartyType
    {
        return PartyType::LegalEntity;
    }
}
