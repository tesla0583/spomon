<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Enums\PartyType;
use App\Models\Client;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoRaw;
use App\Repositories\EntityRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EntityRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EntityRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new EntityRepository;
    }

    public function test_two_clients_sharing_an_entity_find_each_other(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('компания');

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findKnownNetworkEntityReferences($clientA->id);
        $forB = $this->repository->findKnownNetworkEntityReferences($clientB->id);

        self::assertSame([sprintf('контрагент "компания" уже встречался в СПО клиента %d', $clientB->id)], $forA);
        self::assertSame([sprintf('контрагент "компания" уже встречался в СПО клиента %d', $clientA->id)], $forB);
    }

    public function test_client_without_intersections_gets_empty_array(): void
    {
        $client = $this->createClient('T0000001', 'Клиент А');
        $entity = $this->createEntity('компания');

        $this->mention($entity, $client, $this->createSpoRaw($client));

        self::assertSame([], $this->repository->findKnownNetworkEntityReferences($client->id));
    }

    public function test_client_does_not_encounter_itself_via_its_own_repeated_mentions(): void
    {
        $client = $this->createClient('T0000001', 'Клиент А');
        $entity = $this->createEntity('компания');

        // Тот же клиент дважды упоминает того же контрагента (например, в двух разных
        // СПО) — это не должно считаться пересечением с "другим клиентом".
        $this->mention($entity, $client, $this->createSpoRaw($client));
        $this->mention($entity, $client, $this->createSpoRaw($client));

        self::assertSame([], $this->repository->findKnownNetworkEntityReferences($client->id));
    }

    public function test_shared_address_entity_produces_address_worded_message(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('г хучанд, кучаи исмоили сомони 45, дом 9, кв 12', EntityType::Address);

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findKnownNetworkEntityReferences($clientA->id);

        self::assertSame(
            [sprintf('адрес "г хучанд, кучаи исмоили сомони 45, дом 9, кв 12" уже встречался в СПО клиента %d', $clientB->id)],
            $forA,
        );
    }

    public function test_shared_bank_entity_produces_bank_worded_message(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('бонки симург', EntityType::Bank);

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findKnownNetworkEntityReferences($clientA->id);

        self::assertSame(
            [sprintf('банк "бонки симург" уже встречался в СПО клиента %d', $clientB->id)],
            $forA,
        );
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
