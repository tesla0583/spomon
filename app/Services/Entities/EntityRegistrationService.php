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
 * Независимые источники, независимые публичные методы:
 * - {@see self::registerStructuredMention()} — вторая сторона СПО из структурных полей
 *   XML (часть домена "успешное сохранение XML", вызывается из
 *   App\Services\Ingestion\SpoFileIngestionService::persist());
 * - {@see self::registerAddressMention()} — физический адрес стороны СПО (клиента банка
 *   и/или контрагента) как отдельный AML-сигнал графа связей (общий адрес — общий
 *   "почтовый ящик"), тоже часть домена "успешное сохранение XML", вызывается из
 *   App\Services\Ingestion\SpoFileIngestionService::persist();
 * - {@see self::registerNerMentions()} — контрагенты, извлечённые LLM (NER) из свободного
 *   текста, вызывается из App\Jobs\ComputeClientCardJob после успешного пересчёта карточки.
 *
 * Все методы идемпотентны (firstOrCreate по normalized_name / составному ключу
 * упоминания) — повторный вызов с теми же данными не создаёт дублей.
 */
final class EntityRegistrationService
{
    /**
     * Фрагменты нормализованного имени сущности, при наличии которых сущность вообще
     * не регистрируется как контрагент графа связей — служебные реквизиты,
     * встречающиеся почти в каждом СПО и не несущие AML-сигнала (иначе ложно связывают
     * почти всех клиентов друг с другом). Проверка по вхождению подстроки, а не по
     * точному совпадению: LLM извлекает email регулятора из свободного текста вместе
     * с окружающим пояснением (на реальных данных встречались варианты вида
     * "fiu@nbt.tj (служба финансового мониторинга таджикистана)"), поэтому точное
     * совпадение не покрыло бы такие случаи. Список пополняется без изменения логики
     * регистрации.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_NORMALIZED_NAME_FRAGMENTS = [
        'fiu@nbt.tj', // email Службы финансового мониторинга Таджикистана (регулятор)
    ];

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
     * Регистрирует физический адрес стороны СПО как сущность графа связей
     * ({@see EntityType::Address}). Общий адрес у разных клиентов — реальный AML-сигнал
     * (общий "почтовый ящик"/квартира для дропов), поэтому регистрируется наравне с
     * контрагентом, а не как вспомогательное поле.
     */
    public function registerAddressMention(int $clientId, SpoRaw $spoRaw, ?string $address): void
    {
        if ($address === null) {
            return;
        }

        $this->registerMention($address, EntityType::Address, $clientId, $spoRaw->id, EntityMentionSource::Structured);
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

    private function containsExcludedFragment(string $normalized): bool
    {
        foreach (self::EXCLUDED_NORMALIZED_NAME_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
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

        if ($normalized === '' || $this->containsExcludedFragment($normalized)) {
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
