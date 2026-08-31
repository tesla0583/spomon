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
 *
 * Юрлицо распознаётся и без `tax_pay_number`, если заполнен `name` и не заполнено ни одно
 * поле физлица (реальный случай — иностранный контрагент без местного ИНН, см.
 * {@see LegalEntityPartyDto}).
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
                address: $data['address'] ?? null,
            );
        }

        $taxPayNumber = $data['tax_pay_number'] ?? null;
        $name = $data['name'] ?? null;

        // Юрлицо без tax_pay_number — легитимный случай (иностранный контрагент без
        // местного ИНН, см. докблок LegalEntityPartyDto), но только если при этом нет ни
        // одного поля физлица — иначе не отличить "юрлицо без ИНН" от "непонятно что".
        $hasIndividualFields = filled($docNumber) || filled($firstName) || filled($lastName);

        if (filled($name) && (filled($taxPayNumber) || ! $hasIndividualFields)) {
            return new LegalEntityPartyDto(
                taxPayNumber: filled($taxPayNumber) ? $taxPayNumber : null,
                name: $name,
                legOrgForm: $data['leg_org_form'] ?? null,
                address: $data['address'] ?? null,
            );
        }

        throw new UnrecognizedPartyDataException(sprintf(
            'Не удалось определить тип стороны side_section: нет ни doc_number+first_name+last_name '.
            '(физлицо), ни tax_pay_number+name (юрлицо). Заполненные поля: %s',
            implode(', ', array_keys(array_filter($data, static fn ($value) => filled($value)))),
        ));
    }
}
