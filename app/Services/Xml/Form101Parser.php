<?php

declare(strict_types=1);

namespace App\Services\Xml;

use App\DTOs\PartyDtoFactory;
use App\DTOs\SpoRecordDto;
use App\Exceptions\UnsupportedXmlFormatException;
use SimpleXMLElement;

/**
 * Парсер XML СПО в актуальном формате `form_101`.
 */
final class Form101Parser
{
    private const ROOT_TAG = 'form_101';

    public function parse(string $xmlContent): SpoRecordDto
    {
        $xml = $this->loadXml($xmlContent);

        if ($xml->getName() !== self::ROOT_TAG) {
            throw new UnsupportedXmlFormatException(sprintf(
                'Неподдерживаемый формат XML: корневой тег "%s", ожидается "%s". '.
                'Старый формат "Report" (образцы до 2020 года) не поддерживается.',
                $xml->getName(),
                self::ROOT_TAG,
            ));
        }

        $sides = [];
        foreach ($xml->side_section as $sideSection) {
            $sides[] = $this->elementToArray($sideSection);
        }

        if ($sides === []) {
            throw new UnsupportedXmlFormatException(
                'В файле form_101 отсутствует обязательный элемент side_section (сторона клиента).',
            );
        }

        $client = PartyDtoFactory::fromSideSection($sides[0]);
        $otherSide = isset($sides[1]) ? PartyDtoFactory::fromSideSection($sides[1]) : null;

        $transaction = $xml->transaction;
        $transactionDetails = $xml->transaction_details;
        $suspiciousDescription = $xml->suspicious_transaction_description;

        $isSuspicious = $this->nullableString($suspiciousDescription->is_suspicious);

        return new SpoRecordDto(
            sourceFile: null,
            transactionDate: $this->nullableString($transactionDetails->trans_date),
            currency: $this->nullableString($transactionDetails->currency),
            amount: $this->nullableFloat($transactionDetails->amount),
            amountNc: $this->nullableFloat($transactionDetails->amount_nc),
            transactionType: $this->nullableString($transaction->transaction_type),
            transactionSubtype: $this->nullableString($transaction->transaction_subtype),
            details: $this->nullableString($transaction->details),
            transactionDesc: $this->nullableString($transactionDetails->transaction_desc),
            groundText: $this->nullableString($suspiciousDescription->doubt_description),
            isSuspicious: $isSuspicious !== null && strtolower($isSuspicious) === 'yes',
            client: $client,
            otherSide: $otherSide,
        );
    }

    private function loadXml(string $xmlContent): SimpleXMLElement
    {
        $usedInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $xml = simplexml_load_string($xmlContent);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($usedInternalErrors);

        if ($xml === false) {
            $message = $errors[0]->message ?? 'неизвестная ошибка разбора XML';

            throw new UnsupportedXmlFormatException(
                sprintf('Не удалось разобрать XML-файл: %s', trim($message)),
            );
        }

        return $xml;
    }

    /**
     * @return array<string, string|null>
     */
    private function elementToArray(SimpleXMLElement $element): array
    {
        $result = [];

        foreach ($element->children() as $name => $child) {
            $result[$name] = $this->nullableString($child);
        }

        return $result;
    }

    private function nullableString(SimpleXMLElement $node): ?string
    {
        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }

    private function nullableFloat(SimpleXMLElement $node): ?float
    {
        $value = $this->nullableString($node);

        return $value === null ? null : (float) $value;
    }
}
