<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Models\Client;
use App\Models\EntityMention;
use App\Support\EntityNormalizer;

/**
 * Поиск пересечений сущностей (контрагентов) для построения графа связей между
 * клиентами. См. CLAUDE.md, раздел "Логика вызова Claude API": поиск связей —
 * обычный SQL (JOIN/GROUP BY по normalized_name в entity_mentions), не LLM — агент
 * лишь помечает совпадение с уже известным реестром, который передаёт backend.
 */
final class EntityRepository
{
    /**
     * @return array<int, string>
     */
    public function findKnownNetworkEntityReferences(int $clientId): array
    {
        return EntityMention::query()
            ->join('entity_mentions as other_mentions', 'entity_mentions.entity_id', '=', 'other_mentions.entity_id')
            ->join('entities', 'entities.id', '=', 'entity_mentions.entity_id')
            ->where('entity_mentions.client_id', $clientId)
            ->where('other_mentions.client_id', '!=', $clientId)
            ->distinct()
            ->get(['entities.normalized_name', 'entities.entity_type', 'other_mentions.client_id as other_client_id'])
            ->map(static function ($row) {
                // $row->entity_type приходит как сырая строка — это колонка модели Entity,
                // выбранная через join на модели EntityMention, а не на неё самой,
                // поэтому Eloquent-каст EntityType здесь не применяется.
                $entityType = EntityType::tryFrom((string) $row->entity_type) ?? EntityType::Unknown;

                return sprintf(
                    '%s "%s" уже встречался в СПО клиента %d',
                    $entityType->displayLabel(),
                    $row->normalized_name,
                    $row->other_client_id,
                );
            })
            ->all();
    }

    /**
     * Структурированная версия {@see self::findKnownNetworkEntityReferences()} для
     * визуализации графа связей клиента (ECharts) — та же логика пересечения сущностей,
     * но без форматирования в текст. `->distinct()` схлопывает кросс-произведение,
     * возникающее когда один и тот же клиент упоминает одну и ту же сущность в
     * нескольких SpoRaw (иначе такая пара клиент-сущность дала бы несколько
     * одинаковых строк) — разные сущности между той же парой клиентов при этом
     * остаются отдельными строками (это отдельные рёбра графа, без дедупликации).
     *
     * Подпись связи (`connection_label`) определяется по `source` упоминания
     * ЗАПРАШИВАЕМОГО клиента (`own_client_id`), а не второй стороны — у одной и той
     * же пары клиентов каждая сторона может дойти до общей сущности своим путём
     * (структурное поле / NER из свободного текста), поэтому одно и то же ребро
     * может быть подписано по-разному на карточках двух разных клиентов. Это
     * ожидаемо: подпись описывает связь с точки зрения клиента, чью карточку/граф
     * сейчас строим.
     *
     * @return array<int, array{entity_type: string, entity_label: string, own_client_id: int, other_client_id: int, connection_label: string}>
     */
    public function findNetworkGraphEdges(int $clientId): array
    {
        return EntityMention::query()
            ->join('entity_mentions as other_mentions', 'entity_mentions.entity_id', '=', 'other_mentions.entity_id')
            ->join('entities', 'entities.id', '=', 'entity_mentions.entity_id')
            ->where('entity_mentions.client_id', $clientId)
            ->where('other_mentions.client_id', '!=', $clientId)
            ->distinct()
            ->get([
                'entities.entity_type',
                'entities.normalized_name',
                'entity_mentions.client_id as own_client_id',
                'entity_mentions.source',
                'other_mentions.client_id as other_client_id',
            ])
            ->map(static function ($row) {
                $entityType = EntityType::tryFrom((string) $row->entity_type) ?? EntityType::Unknown;

                // $row->source, в отличие от $row->entity_type, уже приходит как
                // App\Enums\EntityMentionSource — 'source' совпадает по имени с
                // атрибутом, закастованным в самой модели EntityMention (в отличие от
                // 'entity_type', пришедшего join'ом с чужой таблицы и модели), поэтому
                // Eloquent применяет каст автоматически при гидрации.
                $source = $row->source instanceof EntityMentionSource
                    ? $row->source
                    : (EntityMentionSource::tryFrom((string) $row->source) ?? EntityMentionSource::Structured);

                return [
                    'entity_type' => (string) $row->entity_type,
                    'entity_label' => (string) $row->normalized_name,
                    'own_client_id' => (int) $row->own_client_id,
                    'other_client_id' => (int) $row->other_client_id,
                    'connection_label' => self::connectionLabel($entityType, $source),
                ];
            })
            ->all();
    }

    private static function connectionLabel(EntityType $entityType, EntityMentionSource $source): string
    {
        return match (true) {
            $entityType === EntityType::Address => 'общий адрес',
            $source === EntityMentionSource::Ner => 'связь по описанию СПО',
            default => 'общий контрагент',
        };
    }

    /**
     * Упомянуто ли полное имя/название этого клиента как сущность в свободном тексте
     * ЧУЖОГО СПО — т.е. другой клиент упомянул имя этого клиента в
     * `doubt_description`, и LLM извлёк его через NER (см.
     * {@see \App\Services\Entities\EntityRegistrationService::registerNerMentions()}).
     * В отличие от {@see self::findNetworkGraphEdges()} (пересечение сущностей МЕЖДУ
     * клиентами), здесь речь о совпадении имени САМОГО клиента с чужой сущностью —
     * сигнал для {@see \App\Services\Risk\ClientRiskLevelService}.
     */
    public function isMentionedInAnotherClientsFreeText(Client $client): bool
    {
        $normalizedName = EntityNormalizer::normalize($client->full_name);

        if ($normalizedName === '') {
            return false;
        }

        return EntityMention::query()
            ->join('entities', 'entities.id', '=', 'entity_mentions.entity_id')
            ->where('entities.normalized_name', $normalizedName)
            ->where('entity_mentions.source', EntityMentionSource::Ner)
            ->where('entity_mentions.client_id', '!=', $client->id)
            ->exists();
    }
}
