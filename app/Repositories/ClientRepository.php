<?php

declare(strict_types=1);

namespace App\Repositories;

use App\DTOs\IndividualPartyDto;
use App\DTOs\LegalEntityPartyDto;
use App\Enums\PartyType;
use App\Models\Client;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;

/**
 * Матчинг и создание клиентов. См. CLAUDE.md, разделы "Архитектурные принципы" и
 * "Схема БД (MVP)" — репозиторий здесь оправдан, т.к. матчинг клиента — не простой CRUD.
 *
 * Юрлицо — точное совпадение по tax_pay_number, без исключений. Если tax_pay_number
 * отсутствует (иностранное юрлицо без местного ИНН) — дедупликация не выполняется вообще,
 * всегда создаётся новый клиент: у юрлица без ИНН нет устойчивого идентификатора, а
 * ложное объединение двух разных компаний в истории СПО хуже пропущенного совпадения.
 *
 * Физлицо — сначала точное совпадение по doc_number (приоритетно). Если его нет или
 * оно не найдено — fuzzy-fallback: среди клиентов с той же датой рождения ищем
 * максимально похожее (>= FUZZY_NAME_MATCH_THRESHOLD %, по similar_text()) нормализованное
 * ФИО. Без dob fuzzy-поиск не запускается — слишком велик риск ложного совпадения
 * по одному лишь ФИО.
 */
final class ClientRepository
{
    /**
     * Порог схожести нормализованного ФИО (см. similar_text()), в процентах.
     */
    private const FUZZY_NAME_MATCH_THRESHOLD = 85.0;

    public function findOrCreateIndividual(IndividualPartyDto $party): Client
    {
        if (filled($party->docNumber)) {
            $existing = Client::query()->where('doc_number', $party->docNumber)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $fullName = $this->buildFullName($party);

        if (filled($party->dob)) {
            $match = $this->findFuzzyMatch($party, $fullName);

            if ($match !== null) {
                return $match;
            }
        }

        return Client::create([
            'party_type' => PartyType::Individual,
            // Пустой doc_number храним как null — семантически "документа нет", а не
            // пустая строка (иначе несколько таких физлиц столкнутся с unique-индексом).
            'doc_number' => filled($party->docNumber) ? $party->docNumber : null,
            'first_name' => $party->firstName,
            'last_name' => $party->lastName,
            'middle_name' => $party->middleName,
            'dob' => $party->dob,
            'full_name' => $fullName,
        ]);
    }

    public function findOrCreateLegalEntity(LegalEntityPartyDto $party): Client
    {
        if ($party->taxPayNumber === null) {
            // Никогда не матчим по null tax_pay_number — иначе firstOrCreate() смэтчит
            // между собой вообще все юрлица без ИНН по WHERE tax_pay_number IS NULL.
            return Client::create([
                'party_type' => PartyType::LegalEntity,
                'tax_pay_number' => null,
                'full_name' => $party->name,
            ]);
        }

        return Client::query()->firstOrCreate(
            ['tax_pay_number' => $party->taxPayNumber],
            [
                'party_type' => PartyType::LegalEntity,
                'full_name' => $party->name,
            ],
        );
    }

    private function findFuzzyMatch(IndividualPartyDto $party, string $fullName): ?Client
    {
        $candidates = Client::query()
            ->where('party_type', PartyType::Individual)
            ->where('dob', $party->dob)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $normalizedNew = NameNormalizer::normalize($fullName);

        return $this->bestMatch($candidates, $normalizedNew);
    }

    /**
     * @param  Collection<int, Client>  $candidates
     */
    private function bestMatch(Collection $candidates, string $normalizedNew): ?Client
    {
        $bestCandidate = null;
        $bestPercent = 0.0;

        foreach ($candidates as $candidate) {
            similar_text($normalizedNew, NameNormalizer::normalize((string) $candidate->full_name), $percent);

            if ($percent > $bestPercent) {
                $bestPercent = $percent;
                $bestCandidate = $candidate;
            }
        }

        return $bestPercent >= self::FUZZY_NAME_MATCH_THRESHOLD ? $bestCandidate : null;
    }

    private function buildFullName(IndividualPartyDto $party): string
    {
        return trim(implode(' ', array_filter([$party->lastName, $party->firstName, $party->middleName])));
    }
}
