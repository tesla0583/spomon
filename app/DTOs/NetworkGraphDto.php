<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Граф связей одного клиента для визуализации на карточке клиента (ECharts,
 * force-directed layout) — см. {@see \App\Services\Entities\ClientNetworkGraphService}.
 */
final class NetworkGraphDto
{
    /**
     * @param  array<int, NetworkGraphNodeDto>  $nodes
     * @param  array<int, NetworkGraphEdgeDto>  $edges
     */
    public function __construct(
        public readonly array $nodes,
        public readonly array $edges,
    ) {}
}
