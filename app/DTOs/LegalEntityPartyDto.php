<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\PartyType;

/**
 * Сторона СПО — юридическое лицо (`side_section` с заполненным `name`).
 *
 * `taxPayNumber` nullable: иностранный контрагент может не иметь местного ИНН (реальный
 * наблюдаемый случай — пакистанские компании `ZENITH PHARMA LIMITED`, `ORION TRADE ENTERPRISES`
 * с `<name>`/`<bank>`/`<bank_country>`, но без тега `<tax_pay_number>` вообще). См.
 * {@see \App\DTOs\PartyDtoFactory::fromSideSection()} и
 * {@see \App\Repositories\ClientRepository::findOrCreateLegalEntity()} — юрлицо без ИНН
 * никогда не дедуплицируется по этому полю.
 */
final class LegalEntityPartyDto implements PartyDataInterface
{
    public function __construct(
        public readonly ?string $taxPayNumber,
        public readonly string $name,
        public readonly ?string $legOrgForm = null,
        public readonly ?string $address = null,
    ) {}

    public function partyType(): PartyType
    {
        return PartyType::LegalEntity;
    }

    public function address(): ?string
    {
        return $this->address;
    }
}
