<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Exceptions\UnrecognizedPartyDataException;

/**
 * Определяет тип стороны `side_section` по факту заполненных полей.
 *
 * В form_101 тип стороны (физлицо/юрлицо) не помечен отдельным тегом — `side_type`/
 * `side_subtype`, вероятно, кодируют это, но без XSD это не подтверждено, поэтому решение
 * принимается по набору заполненных полей. См. CLAUDE.md, раздел "Структура XML СПО".
 */
final class PartyDtoFactory
{
    /**
     * @param  array<string, string|null>  $data  сырые поля одного `side_section`
     */
    public static function fromSideSection(array $data): PartyDataInterface
    {
        $docNumber = $data['doc_number'] ?? null;
        $firstName = $data['first_name'] ?? null;
        $lastName = $data['last_name'] ?? null;

        if (filled($docNumber) && filled($firstName) && filled($lastName)) {
            return new IndividualPartyDto(
                docNumber: $docNumber,
                firstName: $firstName,
                lastName: $lastName,
                middleName: $data['middle_name'] ?? null,
                dob: $data['dob'] ?? null,
            );
        }

        $taxPayNumber = $data['tax_pay_number'] ?? null;
        $name = $data['name'] ?? null;

        if (filled($taxPayNumber) && filled($name)) {
            return new LegalEntityPartyDto(
                taxPayNumber: $taxPayNumber,
                name: $name,
                legOrgForm: $data['leg_org_form'] ?? null,
            );
        }

        throw new UnrecognizedPartyDataException(sprintf(
            'Не удалось определить тип стороны side_section: нет ни doc_number+first_name+last_name '.
            '(физлицо), ни tax_pay_number+name (юрлицо). Заполненные поля: %s',
            implode(', ', array_keys(array_filter($data, static fn ($value) => filled($value)))),
        ));
    }
}
