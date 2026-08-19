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
