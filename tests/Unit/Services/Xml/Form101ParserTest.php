<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Xml;

use App\DTOs\IndividualPartyDto;
use App\DTOs\LegalEntityPartyDto;
use App\Exceptions\UnrecognizedPartyDataException;
use App\Exceptions\UnsupportedXmlFormatException;
use App\Services\Xml\Form101Parser;
use PHPUnit\Framework\TestCase;

final class Form101ParserTest extends TestCase
{
    private Form101Parser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new Form101Parser;
    }

    public function test_parses_valid_form_101_with_both_sides(): void
    {
        $record = $this->parser->parse($this->fixture('form_101_valid.xml'));

        self::assertNull($record->sourceFile);
        self::assertSame('2026-02-10', $record->transactionDate);
        self::assertSame('TJS', $record->currency);
        self::assertSame(15000.5, $record->amount);
        self::assertSame(15000.5, $record->amountNc);
        self::assertSame('10', $record->transactionType);
        self::assertSame('10.3', $record->transactionSubtype);
        self::assertSame('10.3.1', $record->details);
        self::assertStringContainsString('Оплата по договору', (string) $record->transactionDesc);
        self::assertStringContainsString('Тестовое описание', (string) $record->groundText);
        self::assertTrue($record->isSuspicious);

        self::assertInstanceOf(IndividualPartyDto::class, $record->client);
        self::assertSame('T0000001', $record->client->docNumber);
        self::assertSame('Фарход', $record->client->firstName);
        self::assertSame('Тестов', $record->client->lastName);

        self::assertInstanceOf(LegalEntityPartyDto::class, $record->otherSide);
        self::assertSame('010000001', $record->otherSide->taxPayNumber);
        self::assertSame('ООО Тестовая Компания', $record->otherSide->name);
    }

    public function test_parses_form_101_with_single_side_and_no_suspicion(): void
    {
        $record = $this->parser->parse($this->fixture('form_101_single_side.xml'));

        self::assertInstanceOf(IndividualPartyDto::class, $record->client);
        self::assertNull($record->otherSide);
        self::assertFalse($record->isSuspicious);
        self::assertNull($record->groundText);
        self::assertNull($record->amountNc);
    }

    public function test_translates_cyrillic_homoglyph_in_other_side_doc_number_tag(): void
    {
        // Реальный наблюдаемый случай: контрагент второй стороны использует тег
        // <doс_number> с кириллической "с" вместо латинской. См. докблок
        // Form101Parser::CYRILLIC_HOMOGLYPHS.
        $record = $this->parser->parse($this->fixture('form_101_real_homoglyph_doc_number_1.xml'));

        self::assertInstanceOf(IndividualPartyDto::class, $record->otherSide);
        self::assertSame('1401-0500-93417', $record->otherSide->docNumber);
        self::assertSame('QODIRI', $record->otherSide->firstName);
        self::assertSame('OMAR', $record->otherSide->lastName);
    }

    public function test_parses_legal_entity_other_side_without_tax_pay_number(): void
    {
        // Реальный наблюдаемый случай: иностранный контрагент-юрлицо без местного ИНН
        // (нет тега <tax_pay_number> вообще).
        $record = $this->parser->parse($this->fixture('form_101_real_legal_entity_no_tax_pay_number_1.xml'));

        self::assertInstanceOf(LegalEntityPartyDto::class, $record->otherSide);
        self::assertNull($record->otherSide->taxPayNumber);
        self::assertSame('ZENITH PHARMA LIMITED', $record->otherSide->name);
    }

    public function test_throws_unsupported_xml_format_exception_for_legacy_report_root(): void
    {
        $this->expectException(UnsupportedXmlFormatException::class);

        $this->parser->parse($this->fixture('unsupported_root.xml'));
    }

    public function test_throws_unsupported_xml_format_exception_for_malformed_xml(): void
    {
        $this->expectException(UnsupportedXmlFormatException::class);

        $this->parser->parse('<form_101><transaction>');
    }

    public function test_throws_when_side_section_data_is_unrecognizable(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <form_101>
                <transaction>
                    <transaction_type>10</transaction_type>
                </transaction>
                <transaction_details>
                    <trans_date>2026-01-01</trans_date>
                </transaction_details>
                <side_section>
                    <side_type>1</side_type>
                </side_section>
                <suspicious_transaction_description>
                    <is_suspicious>no</is_suspicious>
                </suspicious_transaction_description>
            </form_101>
            XML;

        $this->expectException(UnrecognizedPartyDataException::class);

        $this->parser->parse($xml);
    }

    private function fixture(string $name): string
    {
        $path = __DIR__.'/../../../Fixtures/xml/'.$name;

        return (string) file_get_contents($path);
    }
}
