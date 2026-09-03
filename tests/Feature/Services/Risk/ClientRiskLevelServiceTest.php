<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Risk;

use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Enums\PartyType;
use App\Enums\RiskLevel;
use App\Models\Client;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoRaw;
use App\Repositories\EntityRepository;
use App\Services\Risk\ClientRiskLevelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientRiskLevelServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientRiskLevelService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ClientRiskLevelService(new EntityRepository);
    }

    public function test_single_spo_with_no_connections_is_low(): void
    {
        $client = $this->createClient('T0000001', 'Клиент А');
        $this->createSpoRaw($client);

        self::assertSame(RiskLevel::Low, $this->service->calculate($client));
    }

    public function test_single_spo_with_one_connection_is_medium_not_low(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('компания');

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        self::assertSame(RiskLevel::Medium, $this->service->calculate($clientA));
    }

    public function test_two_to_three_spo_with_no_connections_is_medium(): void
    {
        $client = $this->createClient('T0000001', 'Клиент А');
        $this->createSpoRaw($client, '2026-01-01');
        $this->createSpoRaw($client, '2026-02-01');

        self::assertSame(RiskLevel::Medium, $this->service->calculate($client));
    }

    public function test_two_to_three_spo_with_exactly_one_connection_is_medium(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('компания');

        $this->createSpoRaw($clientA, '2026-01-01');
        $spoRaw2 = $this->createSpoRaw($clientA, '2026-02-01');
        $this->mention($entity, $clientA, $spoRaw2);
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        self::assertSame(RiskLevel::Medium, $this->service->calculate($clientA));
    }

    public function test_two_to_three_spo_with_multiple_connections_is_medium(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $clientC = $this->createClient('T0000003', 'Клиент В');
        $entityOne = $this->createEntity('компания один');
        $entityTwo = $this->createEntity('компания два');

        $spoRaw1 = $this->createSpoRaw($clientA, '2026-01-01');
        $spoRaw2 = $this->createSpoRaw($clientA, '2026-02-01');
        $this->mention($entityOne, $clientA, $spoRaw1);
        $this->mention($entityTwo, $clientA, $spoRaw2);
        $this->mention($entityOne, $clientB, $this->createSpoRaw($clientB));
        $this->mention($entityTwo, $clientC, $this->createSpoRaw($clientC));

        self::assertSame(RiskLevel::Medium, $this->service->calculate($clientA));
    }

    public function test_four_or_more_spo_with_no_connections_is_high(): void
    {
        $client = $this->createClient('T0000001', 'Клиент А');
        foreach (['2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01'] as $date) {
            $this->createSpoRaw($client, $date);
        }

        self::assertSame(RiskLevel::High, $this->service->calculate($client));
    }

    public function test_single_spo_mentioned_in_another_clients_free_text_is_high_overriding_low(): void
    {
        $client = $this->createClient('T0000001', 'Иванов Иван Иванович');
        $this->createSpoRaw($client);

        $otherClient = $this->createClient('T0000002', 'Клиент Б');
        $otherSpoRaw = $this->createSpoRaw($otherClient);
        $entity = $this->createEntity('иванов иван иванович', EntityType::Unknown);
        $this->mention($entity, $otherClient, $otherSpoRaw, EntityMentionSource::Ner);

        self::assertSame(RiskLevel::High, $this->service->calculate($client));
    }

    public function test_calculates_correctly_without_a_client_card(): void
    {
        $client = $this->createClient('T0000001', 'Клиент А');
        $this->createSpoRaw($client);

        // Клиент без ClientCard (LLM ещё не считался) — RiskLevel не зависит от неё
        // вовсе, ошибок из-за отсутствующей карточки быть не должно.
        self::assertNull($client->card);
        self::assertSame(RiskLevel::Low, $this->service->calculate($client));
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

    private function mention(
        Entity $entity,
        Client $client,
        SpoRaw $spoRaw,
        EntityMentionSource $source = EntityMentionSource::Structured,
    ): EntityMention {
        return EntityMention::create([
            'entity_id' => $entity->id,
            'client_id' => $client->id,
            'spo_raw_id' => $spoRaw->id,
            'source' => $source,
        ]);
    }
}
