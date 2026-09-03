<?php

declare(strict_types=1);

namespace App\Livewire;

use App\DTOs\NetworkGraphEdgeDto;
use App\DTOs\NetworkGraphNodeDto;
use App\Models\Client;
use App\Services\Entities\ClientNetworkGraphService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Вложенный компонент (не полностраничный) — интерактивный граф связей клиента,
 * встраивается на карточку клиента через `<livewire:client-network-graph
 * :client-id="$client->id" />` (см. resources/views/livewire/client-card-page.blade.php).
 *
 * Принимает `clientId`, а не модель `Client`, чтобы совпадать с обычным
 * Livewire-паттерном передачи примитивов вложенным компонентам (сериализуемый
 * public-параметр), и сам резолвит модель в mount().
 */
final class ClientNetworkGraph extends Component
{
    public int $clientId;

    public function mount(int $clientId): void
    {
        $this->clientId = $clientId;
    }

    /**
     * Возвращает граф в виде простых ассоциативных массивов (без вложенных DTO-объектов),
     * чтобы `@js($this->graph())` в блейд-шаблоне без проблем сериализовался в JSON для
     * ECharts на фронтенде.
     *
     * @return array{nodes: array<int, array{clientId: int, label: string, riskLevel: string, isFocus: bool}>, edges: array<int, array{fromClientId: int, toClientId: int, entityType: string, entityLabel: string, connectionLabel: string}>}
     */
    public function graph(): array
    {
        $client = Client::findOrFail($this->clientId);

        $graph = app(ClientNetworkGraphService::class)->buildGraph($client);

        return [
            'nodes' => array_map(
                static fn (NetworkGraphNodeDto $node): array => [
                    'clientId' => $node->clientId,
                    'label' => $node->label,
                    'riskLevel' => $node->riskLevel,
                    'isFocus' => $node->isFocus,
                ],
                $graph->nodes,
            ),
            'edges' => array_map(
                static fn (NetworkGraphEdgeDto $edge): array => [
                    'fromClientId' => $edge->fromClientId,
                    'toClientId' => $edge->toClientId,
                    'entityType' => $edge->entityType,
                    'entityLabel' => $edge->entityLabel,
                    'connectionLabel' => $edge->connectionLabel,
                ],
                $graph->edges,
            ),
        ];
    }

    public function render(): View
    {
        return view('livewire.client-network-graph');
    }
}
