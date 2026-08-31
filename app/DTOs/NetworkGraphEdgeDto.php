<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Одно ребро графа связей — общая сущность (контрагент/банк/физлицо/адрес) между
 * фокусным клиентом и другим клиентом. Без дедупликации между разными сущностями:
 * если у пары клиентов несколько общих сущностей, это несколько DTO с одинаковыми
 * fromClientId/toClientId и разными entityType/entityLabel — ECharts отрисует их как
 * параллельные рёбра.
 */
final class NetworkGraphEdgeDto
{
    public function __construct(
        public readonly int $fromClientId,
        public readonly int $toClientId,
        public readonly string $entityType,
        public readonly string $entityLabel,
    ) {}
}
