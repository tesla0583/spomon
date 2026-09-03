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

    public function test_shared_entity_produces_structured_edge_rows_for_both_clients(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('компания');

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findNetworkGraphEdges($clientA->id);
        $forB = $this->repository->findNetworkGraphEdges($clientB->id);

        self::assertSame([[
            'entity_type' => EntityType::Organization->value,
            'entity_label' => 'компания',
            'own_client_id' => $clientA->id,
            'other_client_id' => $clientB->id,
            'connection_label' => 'общий контрагент',
        ]], $forA);

        self::assertSame([[
            'entity_type' => EntityType::Organization->value,
            'entity_label' => 'компания',
            'own_client_id' => $clientB->id,
            'other_client_id' => $clientA->id,
            'connection_label' => 'общий контрагент',
        ]], $forB);
    }

    public function test_two_distinct_shared_entities_produce_two_edge_rows(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entityOne = $this->createEntity('компания один');
        $entityTwo = $this->createEntity('компания два');

        $this->mention($entityOne, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entityOne, $clientB, $this->createSpoRaw($clientB));
        $this->mention($entityTwo, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entityTwo, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findNetworkGraphEdges($clientA->id);

        self::assertCount(2, $forA);
        self::assertEqualsCanonicalizing(['компания один', 'компания два'], array_column($forA, 'entity_label'));
    }

    public function test_repeated_mentions_of_the_same_entity_collapse_into_one_edge_row(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('компания');

        // Клиент А дважды упоминает ту же сущность (в двух разных SpoRaw) — без
        // ->distinct() self-join дал бы 2 строки для одного и того же ребра.
        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findNetworkGraphEdges($clientA->id);

        self::assertCount(1, $forA);
    }

    public function test_client_without_intersections_gets_empty_edges_array(): void
    {
        $client = $this->createClient('T0000001', 'Клиент А');
        $entity = $this->createEntity('компания');

        $this->mention($entity, $client, $this->createSpoRaw($client));

        self::assertSame([], $this->repository->findNetworkGraphEdges($client->id));
    }

    public function test_address_entity_edge_has_common_address_connection_label(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('г хучанд, кучаи исмоили сомони 45, дом 9, кв 12', EntityType::Address);

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA));
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findNetworkGraphEdges($clientA->id);

        self::assertSame('общий адрес', $forA[0]['connection_label']);
    }

    public function test_structured_entity_edge_has_common_counterparty_connection_label(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('компания', EntityType::Organization);

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA), EntityMentionSource::Structured);
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB));

        $forA = $this->repository->findNetworkGraphEdges($clientA->id);

        self::assertSame('общий контрагент', $forA[0]['connection_label']);
    }

    public function test_ner_sourced_edge_has_description_connection_label_regardless_of_entity_type(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент А');
        $clientB = $this->createClient('T0000002', 'Клиент Б');
        // entity_type уже "повышен" до Organization (структурным совпадением где-то
        // ещё), но САМО упоминание клиента A по-прежнему из свободного текста (NER) —
        // подпись для A должна отражать именно это, а не текущий entity_type сущности.
        $entity = $this->createEntity('компания', EntityType::Organization);

        $this->mention($entity, $clientA, $this->createSpoRaw($clientA), EntityMentionSource::Ner);
        $this->mention($entity, $clientB, $this->createSpoRaw($clientB), EntityMentionSource::Structured);

        $forA = $this->repository->findNetworkGraphEdges($clientA->id);

        self::assertSame('связь по описанию СПО', $forA[0]['connection_label']);
    }

    public function test_client_is_mentioned_in_another_clients_free_text_via_ner(): void
    {
        $client = $this->createClient('T0000001', 'Иванов Иван Иванович');
        $otherClient = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('иванов иван иванович', EntityType::Unknown);

        $this->mention($entity, $otherClient, $this->createSpoRaw($otherClient), EntityMentionSource::Ner);

        self::assertTrue($this->repository->isMentionedInAnotherClientsFreeText($client));
    }

    public function test_structured_mention_of_clients_name_does_not_count_as_mentioned_elsewhere(): void
    {
        // Правило требует именно source = Ner — структурное совпадение (контрагент
        // сделки, а не упоминание в свободном тексте) не в счёт.
        $client = $this->createClient('T0000001', 'Иванов Иван Иванович');
        $otherClient = $this->createClient('T0000002', 'Клиент Б');
        $entity = $this->createEntity('иванов иван иванович', EntityType::Person);

        $this->mention($entity, $otherClient, $this->createSpoRaw($otherClient), EntityMentionSource::Structured);

        self::assertFalse($this->repository->isMentionedInAnotherClientsFreeText($client));
    }

    public function test_clients_own_mention_of_the_same_name_does_not_count(): void
    {
        $client = $this->createClient('T0000001', 'Иванов Иван Иванович');
        $entity = $this->createEntity('иванов иван иванович', EntityType::Unknown);

        $this->mention($entity, $client, $this->createSpoRaw($client), EntityMentionSource::Ner);

        self::assertFalse($this->repository->isMentionedInAnotherClientsFreeText($client));
    }

    public function test_client_without_any_matching_entity_is_not_mentioned_elsewhere(): void
    {
        $client = $this->createClient('T0000001', 'Иванов Иван Иванович');

        self::assertFalse($this->repository->isMentionedInAnotherClientsFreeText($client));
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
