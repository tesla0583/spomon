<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Enums\EntityType;
use App\Models\EntityMention;

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
     * @return array<int, array{entity_type: string, entity_label: string, own_client_id: int, other_client_id: int}>
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
                'other_mentions.client_id as other_client_id',
            ])
            ->map(static fn ($row) => [
                'entity_type' => (string) $row->entity_type,
                'entity_label' => (string) $row->normalized_name,
                'own_client_id' => (int) $row->own_client_id,
                'other_client_id' => (int) $row->other_client_id,
            ])
            ->all();
    }
}
