<?php

declare(strict_types=1);

namespace App\Services\Entities;

use App\DTOs\NetworkGraphDto;
use App\DTOs\NetworkGraphEdgeDto;
use App\DTOs\NetworkGraphNodeDto;
use App\Models\Client;
use App\Repositories\EntityRepository;

/**
 * Строит граф связей одного клиента для визуализации на карточке клиента (ECharts,
 * force-directed layout) — см. CLAUDE.md, "Этап 5 — реестр сущностей... и граф связей
 * между клиентами".
 *
 * Граф — звезда/эго-сеть: фокусный клиент в центре, рёбра к каждому клиенту, с которым
 * найдена общая сущность через {@see EntityRepository::findNetworkGraphEdges()}. Рёбра
 * между двумя не-фокусными клиентами не строятся — это ограничение самого запроса
 * (он всегда смотрит от лица одного клиента), ожидаемое для карточки одного клиента.
 */
final class ClientNetworkGraphService
{
    public function __construct(
        private readonly EntityRepository $entityRepository,
    ) {}

    public function buildGraph(Client $client): NetworkGraphDto
    {
        $client->loadMissing('card');

        $edges = $this->entityRepository->findNetworkGraphEdges($client->id);

        $otherClientIds = array_unique(array_column($edges, 'other_client_id'));

        $otherClients = Client::query()
            ->with('card')
            ->whereIn('id', $otherClientIds)
            ->get(['id', 'full_name'])
            ->keyBy('id');

        $nodes = [
            new NetworkGraphNodeDto(
                clientId: $client->id,
                label: $client->full_name,
                riskLabel: $client->card?->risk_label?->value,
                isFocus: true,
            ),
        ];

        foreach ($otherClients as $otherClient) {
            $nodes[] = new NetworkGraphNodeDto(
                clientId: $otherClient->id,
                label: $otherClient->full_name,
                riskLabel: $otherClient->card?->risk_label?->value,
                isFocus: false,
            );
        }

        $graphEdges = array_map(
            static fn (array $edge): NetworkGraphEdgeDto => new NetworkGraphEdgeDto(
                fromClientId: $client->id,
                toClientId: $edge['other_client_id'],
                entityType: $edge['entity_type'],
                entityLabel: $edge['entity_label'],
            ),
            $edges,
        );

        return new NetworkGraphDto(nodes: $nodes, edges: $graphEdges);
    }
}
