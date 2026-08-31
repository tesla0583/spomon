<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs;

use App\DTOs\IndividualPartyDto;
use App\DTOs\LegalEntityPartyDto;
use App\DTOs\PartyDtoFactory;
use App\Enums\PartyType;
use App\Exceptions\UnrecognizedPartyDataException;
use PHPUnit\Framework\TestCase;

final class PartyDtoFactoryTest extends TestCase
{
    public function test_recognizes_individual_party_by_doc_number_and_name(): void
    {
        $dto = PartyDtoFactory::fromSideSection([
            'doc_number' => 'T0000001',
            'first_name' => 'Имя',
            'last_name' => 'Фамилия',
            'middle_name' => 'Отчество',
            'dob' => '1990-01-01',
        ]);

        self::assertInstanceOf(IndividualPartyDto::class, $dto);
        self::assertSame(PartyType::Individual, $dto->partyType());
        self::assertSame('T0000001', $dto->docNumber);
        self::assertSame('Имя', $dto->firstName);
        self::assertSame('Фамилия', $dto->lastName);
        self::assertSame('Отчество', $dto->middleName);
        self::assertSame('1990-01-01', $dto->dob);
    }

    public function test_recognizes_individual_party_without_optional_fields(): void
    {
        $dto = PartyDtoFactory::fromSideSection([
            'doc_number' => 'T0000002',
            'first_name' => 'Имя',
            'last_name' => 'Фамилия',
        ]);

        self::assertInstanceOf(IndividualPartyDto::class, $dto);
        self::assertNull($dto->middleName);
        self::assertNull($dto->dob);
    }

    public function test_recognizes_legal_entity_party_by_tax_pay_number_and_name(): void
    {
        $dto = PartyDtoFactory::fromSideSection([
            'tax_pay_number' => '010000001',
            'name' => 'ООО Тестовая Компания',
            'leg_org_form' => 'ООО',
        ]);

        self::assertInstanceOf(LegalEntityPartyDto::class, $dto);
        self::assertSame(PartyType::LegalEntity, $dto->partyType());
        self::assertSame('010000001', $dto->taxPayNumber);
        self::assertSame('ООО Тестовая Компания', $dto->name);
        self::assertSame('ООО', $dto->legOrgForm);
    }

    public function test_recognizes_legal_entity_without_tax_pay_number_when_no_individual_fields_present(): void
    {
        // Реальный случай: иностранное юрлицо-контрагент (например, пакистанская компания)
        // без местного ИНН — есть name/bank/bank_country, но нет tax_pay_number.
        $dto = PartyDtoFactory::fromSideSection([
            'name' => 'ZENITH PHARMA LIMITED',
            'bank' => 'MERIDIAN COMMERCIAL BANK LIMITED(MAIN BRANCH)',
            'bank_country' => 'PK',
        ]);

        self::assertInstanceOf(LegalEntityPartyDto::class, $dto);
        self::assertSame(PartyType::LegalEntity, $dto->partyType());
        self::assertNull($dto->taxPayNumber);
        self::assertSame('ZENITH PHARMA LIMITED', $dto->name);
    }

    public function test_address_flows_into_individual_party_dto(): void
    {
        $dto = PartyDtoFactory::fromSideSection([
            'doc_number' => 'T0000001',
            'first_name' => 'Имя',
            'last_name' => 'Фамилия',
            'address' => 'г Хучанд, кучаи Исмоили Сомони 45, дом 9, кв 12',
        ]);

        self::assertInstanceOf(IndividualPartyDto::class, $dto);
        self::assertSame('г Хучанд, кучаи Исмоили Сомони 45, дом 9, кв 12', $dto->address());
    }

    public function test_address_flows_into_legal_entity_party_dto_without_tax_pay_number(): void
    {
        $dto = PartyDtoFactory::fromSideSection([
            'name' => 'ZENITH PHARMA LIMITED',
            'address' => 'ZENITH PHARMA B-22 S.I.T.E MANGOPEER ROAD',
        ]);

        self::assertInstanceOf(LegalEntityPartyDto::class, $dto);
        self::assertSame('ZENITH PHARMA B-22 S.I.T.E MANGOPEER ROAD', $dto->address());
    }

    public function test_throws_when_name_present_but_ambiguous_partial_individual_fields(): void
    {
        $this->expectException(UnrecognizedPartyDataException::class);

        // name заполнен, но и first_name заполнен (без last_name) — не полный набор
        // физлица, но и не "чистое" юрлицо без ИНН. Не должно тихо стать юрлицом.
        PartyDtoFactory::fromSideSection([
            'name' => 'Какая-то организация',
            'first_name' => 'Имя',
        ]);
    }

    public function test_throws_when_party_type_cannot_be_determined(): void
    {
        $this->expectException(UnrecognizedPartyDataException::class);

        PartyDtoFactory::fromSideSection([
            'side_type' => '1',
            'side_subtype' => '2',
        ]);
    }

    public function test_throws_when_individual_fields_are_incomplete(): void
    {
        $this->expectException(UnrecognizedPartyDataException::class);

        // doc_number есть, но нет ни first_name, ни last_name — и нет полей юрлица.
        PartyDtoFactory::fromSideSection([
            'doc_number' => 'T0000003',
        ]);
    }
}
