<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Enums\PartyType;
use App\Livewire\ClientNetworkGraph;
use App\Models\Client;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoRaw;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ClientNetworkGraphTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully_for_client_without_connections(): void
    {
        $client = $this->createClient('T0000001', 'Клиент Один');

        Livewire::test(ClientNetworkGraph::class, ['clientId' => $client->id])->assertOk();
    }

    public function test_graph_returns_expected_node_and_edge_structure(): void
    {
        $focus = $this->createClient('T0000001', 'Клиент Фокус');
        $other = $this->createClient('T0000002', 'Клиент Другой');
        $entity = Entity::create([
            'normalized_name' => 'компания',
            'raw_name' => 'компания',
            'entity_type' => EntityType::Organization,
        ]);

        $this->mention($entity, $focus, $this->createSpoRaw($focus));
        $this->mention($entity, $other, $this->createSpoRaw($other));

        $component = Livewire::test(ClientNetworkGraph::class, ['clientId' => $focus->id]);
        $graph = $component->instance()->graph();

        self::assertCount(2, $graph['nodes']);
        self::assertCount(1, $graph['edges']);

        self::assertSame([
            'fromClientId' => $focus->id,
            'toClientId' => $other->id,
            'entityType' => EntityType::Organization->value,
            'entityLabel' => 'компания',
        ], $graph['edges'][0]);
    }

    public function test_throws_when_client_does_not_exist(): void
    {
        // Вложенный компонент не идёт через route model binding (в отличие от
        // ClientCardPage/clients.show) — при рендеринге через Livewire::test()
        // необработанный ModelNotFoundException всплывает как есть (обёрнутый Blade
        // в ViewException), а не превращается в HTTP 404 автоматически.
        try {
            Livewire::test(ClientNetworkGraph::class, ['clientId' => 999999]);
            self::fail('Ожидалось исключение для несуществующего клиента.');
        } catch (\Throwable $exception) {
            while ($exception->getPrevious() !== null) {
                $exception = $exception->getPrevious();
            }

            self::assertInstanceOf(ModelNotFoundException::class, $exception);
        }
    }

    private function createClient(string $docNumber, string $fullName): Client
    {
        return Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => $docNumber,
            'first_name' => $fullName,
            'last_name' => $fullName,
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => $fullName,
        ]);
    }

    private function createSpoRaw(Client $client): SpoRaw
    {
        return SpoRaw::create([
            'client_id' => $client->id,
            'source_file' => 'spo_1.xml',
            'transaction_date' => '2026-02-10',
            'currency' => 'TJS',
            'amount' => 1000,
            'amount_nc' => null,
            'transaction_type' => '10.3',
            'transaction_subtype' => '10.3.1',
            'details' => '10.3.1.9',
            'transaction_desc' => 'Перевод',
            'ground_text' => 'Подозрительный перевод.',
            'other_side' => null,
        ]);
    }

    private function mention(Entity $entity, Client $client, SpoRaw $spoRaw): EntityMention
    {
        return EntityMention::create([
            'entity_id' => $entity->id,
            'client_id' => $client->id,
            'spo_raw_id' => $spoRaw->id,
            'source' => EntityMentionSource::Structured,
        ]);
    }
}
