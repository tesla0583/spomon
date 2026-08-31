<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Entities;

use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Enums\PartyType;
use App\Enums\RiskLabel;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoRaw;
use App\Repositories\EntityRepository;
use App\Services\Entities\ClientNetworkGraphService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientNetworkGraphServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientNetworkGraphService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ClientNetworkGraphService(new EntityRepository);
    }

    public function test_builds_focus_node_plus_related_clients_and_edges(): void
    {
        $focus = $this->createClient('T0000001', 'Клиент Фокус');
        $withCard = $this->createClient('T0000002', 'Клиент С Карточкой');
        $withoutCard = $this->createClient('T0000003', 'Клиент Без Карточки');

        ClientCard::create([
            'client_id' => $withCard->id,
            'risk_label' => RiskLabel::PartOfNetwork,
            'summary' => 'Сводка.',
            'pattern_notes' => null,
            'network_signal' => null,
            'llm_raw_response' => ['ok' => true],
            'history_fingerprint' => hash('sha256', (string) $withCard->id),
            'computed_at' => now(),
        ]);

        $entity = $this->createEntity('компания');
        $this->mention($entity, $focus, $this->createSpoRaw($focus));
        $this->mention($entity, $withCard, $this->createSpoRaw($withCard));
        $this->mention($entity, $withoutCard, $this->createSpoRaw($withoutCard));

        $graph = $this->service->buildGraph($focus);

        self::assertCount(3, $graph->nodes);

        $byId = [];
        foreach ($graph->nodes as $node) {
            $byId[$node->clientId] = $node;
        }

        self::assertTrue($byId[$focus->id]->isFocus);
        self::assertNull($byId[$focus->id]->riskLabel);

        self::assertFalse($byId[$withCard->id]->isFocus);
        self::assertSame(RiskLabel::PartOfNetwork->value, $byId[$withCard->id]->riskLabel);

        self::assertFalse($byId[$withoutCard->id]->isFocus);
        self::assertNull($byId[$withoutCard->id]->riskLabel);

        self::assertCount(2, $graph->edges);
        foreach ($graph->edges as $edge) {
            self::assertSame($focus->id, $edge->fromClientId);
            self::assertSame(EntityType::Organization->value, $edge->entityType);
            self::assertSame('компания', $edge->entityLabel);
        }
    }

    public function test_client_without_any_shared_entity_gets_only_the_focus_node(): void
    {
        $focus = $this->createClient('T0000001', 'Клиент Фокус');

        $graph = $this->service->buildGraph($focus);

        self::assertCount(1, $graph->nodes);
        self::assertTrue($graph->nodes[0]->isFocus);
        self::assertSame([], $graph->edges);
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

    private function createEntity(string $normalizedName, EntityType $entityType = EntityType::Organization): Entity
    {
        return Entity::create([
            'normalized_name' => $normalizedName,
            'raw_name' => $normalizedName,
            'entity_type' => $entityType,
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
