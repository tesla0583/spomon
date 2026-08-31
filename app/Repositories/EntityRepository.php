<?php

declare(strict_types=1);

namespace App\Repositories;

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
            ->get(['entities.normalized_name', 'other_mentions.client_id as other_client_id'])
            ->map(static fn ($row) => sprintf(
                'контрагент "%s" уже встречался в СПО клиента %d',
                $row->normalized_name,
                $row->other_client_id,
            ))
            ->all();
    }
}
