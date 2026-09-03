<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Entities;

use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Enums\PartyType;
use App\Enums\RiskLevel;
use App\Models\Client;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoRaw;
use App\Repositories\EntityRepository;
use App\Services\Entities\ClientNetworkGraphService;
use App\Services\Risk\ClientRiskLevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientNetworkGraphServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientNetworkGraphService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $entityRepository = new EntityRepository;
        $this->service = new ClientNetworkGraphService($entityRepository, new ClientRiskLevelService($entityRepository));
    }

    public function test_builds_focus_node_plus_related_clients_and_edges(): void
    {
        $focus = $this->createClient('T0000001', 'Клиент Фокус');
        $mediumRiskOther = $this->createClient('T0000002', 'Клиент Средний');
        $highRiskOther = $this->createClient('T0000003', 'Клиент Высокий');

        $entity = $this->createEntity('компания');
        $this->mention($entity, $focus, $this->createSpoRaw($focus));
        $this->mention($entity, $mediumRiskOther, $this->createSpoRaw($mediumRiskOther));

        // 4+ СПО — RiskLevel::High независимо от связей (правило 1 приоритетнее правила 2).
        foreach (range(1, 4) as $i) {
            $spoRaw = $this->createSpoRaw($highRiskOther, sprintf('2026-0%d-01', $i));

            if ($i === 1) {
                $this->mention($entity, $highRiskOther, $spoRaw);
            }
        }

        $graph = $this->service->buildGraph($focus);

        self::assertCount(3, $graph->nodes);

        $byId = [];
        foreach ($graph->nodes as $node) {
            $byId[$node->clientId] = $node;
        }

        // Фокус: 1 СПО, 2 связи — distinctOtherClients !== 0, значит не Low; Medium.
        self::assertTrue($byId[$focus->id]->isFocus);
        self::assertSame(RiskLevel::Medium->value, $byId[$focus->id]->riskLevel);

        // 1 СПО и как минимум 1 связь (на деле 2 — общая сущность роднит его и с
        // фокусом, и с highRiskOther) — тоже Medium, не Low (правило Low требует
        // ровно 0 связей).
        self::assertFalse($byId[$mediumRiskOther->id]->isFocus);
        self::assertSame(RiskLevel::Medium->value, $byId[$mediumRiskOther->id]->riskLevel);

        self::assertFalse($byId[$highRiskOther->id]->isFocus);
        self::assertSame(RiskLevel::High->value, $byId[$highRiskOther->id]->riskLevel);

        self::assertCount(2, $graph->edges);
        foreach ($graph->edges as $edge) {
            self::assertSame($focus->id, $edge->fromClientId);
            self::assertSame(EntityType::Organization->value, $edge->entityType);
            self::assertSame('компания', $edge->entityLabel);
            self::assertSame('общий контрагент', $edge->connectionLabel);
        }
    }

    public function test_client_without_any_shared_entity_gets_only_the_focus_node(): void
    {
        $focus = $this->createClient('T0000001', 'Клиент Фокус');

        $graph = $this->service->buildGraph($focus);

        self::assertCount(1, $graph->nodes);
        self::assertTrue($graph->nodes[0]->isFocus);
        // 0 СПО (не 1) — правило Low не срабатывает буквально по условию spoCount === 1,
        // падает в Medium по умолчанию.
        self::assertSame(RiskLevel::Medium->value, $graph->nodes[0]->riskLevel);
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

    private function createSpoRaw(Client $client, string $transactionDate = '2026-02-10'): SpoRaw
    {
        return SpoRaw::create([
            'client_id' => $client->id,
            'source_file' => 'spo_1.xml',
            'transaction_date' => $transactionDate,
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
