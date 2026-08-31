<?php

declare(strict_types=1);

namespace App\Services\Entities;

use App\DTOs\IndividualPartyDto;
use App\DTOs\LegalEntityPartyDto;
use App\DTOs\PartyDataInterface;
use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Models\Client;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoRaw;
use App\Support\EntityNormalizer;

/**
 * Регистрация сущностей (контрагентов) в реестре `entities`/`entity_mentions` для
 * построения графа связей между клиентами — см. CLAUDE.md, таблицы `entities`/
 * `entity_mentions` и раздел "Логика вызова Claude API".
 *
 * Два независимых источника, два независимых публичных метода:
 * - {@see self::registerStructuredMention()} — вторая сторона СПО из структурных полей
 *   XML (часть домена "успешное сохранение XML", вызывается из
 *   App\Services\Ingestion\SpoFileIngestionService::persist());
 * - {@see self::registerNerMentions()} — контрагенты, извлечённые LLM (NER) из свободного
 *   текста, вызывается из App\Jobs\ComputeClientCardJob после успешного пересчёта карточки.
 *
 * Оба метода идемпотентны (firstOrCreate по normalized_name / составному ключу
 * упоминания) — повторный вызов с теми же данными не создаёт дублей.
 */
final class EntityRegistrationService
{
    public function registerStructuredMention(SpoRaw $spoRaw, ?PartyDataInterface $otherSide): void
    {
        [$rawName, $entityType] = match (true) {
            $otherSide instanceof LegalEntityPartyDto => [$otherSide->name, EntityType::Organization],
            $otherSide instanceof IndividualPartyDto => [$this->buildFullName($otherSide), EntityType::Person],
            default => [null, null],
        };

        if ($rawName === null || $entityType === null) {
            return;
        }

        $this->registerMention($rawName, $entityType, $spoRaw->client_id, $spoRaw->id, EntityMentionSource::Structured);
    }

    /**
     * @param  array<int, array{spo_date: string, entities: array<int, string>}>  $extractedEntities
     */
    public function registerNerMentions(Client $client, array $extractedEntities): void
    {
        foreach ($extractedEntities as $item) {
            $spoDate = $item['spo_date'] ?? null;
            $entityNames = $item['entities'] ?? [];

            if ($spoDate === null) {
                continue;
            }

            // Первая найденная запись, если СПО клиента с такой датой несколько — это
            // ожидаемо и ок (см. задачу Этапа 5).
            $spoRaw = $client->spoRaws()->where('transaction_date', $spoDate)->first();

            if ($spoRaw === null) {
                continue;
            }

            foreach ($entityNames as $rawName) {
                // LLM не даёт тип сущности — регистрируем как Unknown, тип уточнится
                // позже структурным совпадением (см. registerMention()).
                $this->registerMention($rawName, EntityType::Unknown, $client->id, $spoRaw->id, EntityMentionSource::Ner);
            }
        }
    }

    private function buildFullName(IndividualPartyDto $party): string
    {
        return trim(implode(' ', array_filter([$party->lastName, $party->firstName, $party->middleName])));
    }

    private function registerMention(
        string $rawName,
        EntityType $entityType,
        int $clientId,
        int $spoRawId,
        EntityMentionSource $source,
    ): void {
        $normalized = EntityNormalizer::normalize($rawName);

        if ($normalized === '') {
            return;
        }

        $entity = Entity::query()->firstOrCreate(
            ['normalized_name' => $normalized],
            ['raw_name' => $rawName, 'entity_type' => $entityType],
        );

        if ($entity->entity_type === EntityType::Unknown && $entityType !== EntityType::Unknown) {
            $entity->update(['entity_type' => $entityType]);
        }

        EntityMention::query()->firstOrCreate([
            'entity_id' => $entity->id,
            'client_id' => $clientId,
            'spo_raw_id' => $spoRawId,
            'source' => $source,
        ]);
    }
}
