<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Entities;

use App\DTOs\IndividualPartyDto;
use App\DTOs\LegalEntityPartyDto;
use App\Enums\EntityMentionSource;
use App\Enums\EntityType;
use App\Enums\PartyType;
use App\Models\Client;
use App\Models\Entity;
use App\Models\EntityMention;
use App\Models\SpoRaw;
use App\Services\Entities\EntityRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class EntityRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private EntityRegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new EntityRegistrationService;
    }

    public function test_registers_legal_entity_other_side_as_organization(): void
    {
        $spoRaw = $this->createSpoRaw();

        $this->service->registerStructuredMention(
            $spoRaw,
            new LegalEntityPartyDto(taxPayNumber: '010000001', name: 'ООО «Компания»'),
        );

        $entity = Entity::query()->firstOrFail();
        self::assertSame('компания', $entity->normalized_name);
        self::assertSame(EntityType::Organization, $entity->entity_type);

        $mention = EntityMention::query()->firstOrFail();
        self::assertSame($entity->id, $mention->entity_id);
        self::assertSame($spoRaw->client_id, $mention->client_id);
        self::assertSame($spoRaw->id, $mention->spo_raw_id);
        self::assertSame(EntityMentionSource::Structured, $mention->source);
    }

    public function test_registers_individual_other_side_as_person_with_full_name_in_order(): void
    {
        $spoRaw = $this->createSpoRaw();

        $this->service->registerStructuredMention(
            $spoRaw,
            new IndividualPartyDto(docNumber: 'T9999999', firstName: 'Азиз', lastName: 'Примеров', middleName: 'Олимович'),
        );

        $entity = Entity::query()->firstOrFail();
        self::assertSame('примеров азиз олимович', $entity->normalized_name);
        self::assertSame(EntityType::Person, $entity->entity_type);
    }

    public function test_null_other_side_registers_nothing(): void
    {
        $spoRaw = $this->createSpoRaw();

        $this->service->registerStructuredMention($spoRaw, null);

        self::assertSame(0, Entity::query()->count());
        self::assertSame(0, EntityMention::query()->count());
    }

    public function test_blank_name_after_normalization_registers_nothing(): void
    {
        $spoRaw = $this->createSpoRaw();

        $this->service->registerStructuredMention(
            $spoRaw,
            new LegalEntityPartyDto(taxPayNumber: '010000001', name: '   '),
        );

        self::assertSame(0, Entity::query()->count());
        self::assertSame(0, EntityMention::query()->count());
    }

    public function test_registers_address_mention_with_address_entity_type(): void
    {
        $spoRaw = $this->createSpoRaw();

        $this->service->registerAddressMention($spoRaw->client_id, $spoRaw, 'г Хучанд, кучаи Исмоили Сомони 45, дом 9, кв 12');

        $entity = Entity::query()->firstOrFail();
        self::assertSame('г хучанд, кучаи исмоили сомони 45, дом 9, кв 12', $entity->normalized_name);
        self::assertSame(EntityType::Address, $entity->entity_type);

        $mention = EntityMention::query()->firstOrFail();
        self::assertSame($entity->id, $mention->entity_id);
        self::assertSame($spoRaw->client_id, $mention->client_id);
        self::assertSame($spoRaw->id, $mention->spo_raw_id);
        self::assertSame(EntityMentionSource::Structured, $mention->source);
    }

    public function test_null_address_registers_nothing(): void
    {
        $spoRaw = $this->createSpoRaw();

        $this->service->registerAddressMention($spoRaw->client_id, $spoRaw, null);

        self::assertSame(0, Entity::query()->count());
        self::assertSame(0, EntityMention::query()->count());
    }

    public function test_repeated_address_registration_does_not_duplicate(): void
    {
        $spoRaw = $this->createSpoRaw();
        $address = 'г Хучанд, кучаи Исмоили Сомони 45, дом 9, кв 12';

        $this->service->registerAddressMention($spoRaw->client_id, $spoRaw, $address);
        $this->service->registerAddressMention($spoRaw->client_id, $spoRaw, $address);

        self::assertSame(1, Entity::query()->count());
        self::assertSame(1, EntityMention::query()->count());
    }

    public function test_ner_mentions_matched_by_spo_date_are_registered_as_unknown(): void
    {
        $spoRaw = $this->createSpoRaw(transactionDate: '2026-02-10');

        $this->service->registerNerMentions($spoRaw->client, [
            ['spo_date' => '2026-02-10', 'entities' => ['ООО «Ромашка»', 'Иванов Иван']],
        ]);

        self::assertSame(2, Entity::query()->count());
        self::assertSame(2, EntityMention::query()->where('source', EntityMentionSource::Ner)->count());

        $entity = Entity::query()->where('normalized_name', 'ромашка')->firstOrFail();
        self::assertSame(EntityType::Unknown, $entity->entity_type);
    }

    public function test_ner_element_without_matching_spo_raw_is_skipped(): void
    {
        $spoRaw = $this->createSpoRaw(transactionDate: '2026-02-10');

        $this->service->registerNerMentions($spoRaw->client, [
            ['spo_date' => '2099-01-01', 'entities' => ['ООО «Ромашка»']],
        ]);

        self::assertSame(0, Entity::query()->count());
        self::assertSame(0, EntityMention::query()->count());
    }

    public function test_repeated_structured_registration_does_not_duplicate_rows(): void
    {
        $spoRaw = $this->createSpoRaw();
        $otherSide = new LegalEntityPartyDto(taxPayNumber: '010000001', name: 'ООО «Компания»');

        $this->service->registerStructuredMention($spoRaw, $otherSide);
        $this->service->registerStructuredMention($spoRaw, $otherSide);

        self::assertSame(1, Entity::query()->count());
        self::assertSame(1, EntityMention::query()->count());
    }

    public function test_unknown_entity_is_upgraded_to_concrete_type_on_structured_match(): void
    {
        $spoRaw = $this->createSpoRaw(transactionDate: '2026-02-10');

        $this->service->registerNerMentions($spoRaw->client, [
            ['spo_date' => '2026-02-10', 'entities' => ['ООО «Компания»']],
        ]);

        $entity = Entity::query()->firstOrFail();
        self::assertSame(EntityType::Unknown, $entity->entity_type);

        $this->service->registerStructuredMention(
            $spoRaw,
            new LegalEntityPartyDto(taxPayNumber: '010000001', name: 'ООО «Компания»'),
        );

        self::assertSame(1, Entity::query()->count());
        $entity->refresh();
        self::assertSame(EntityType::Organization, $entity->entity_type);
    }

    private function createSpoRaw(string $transactionDate = '2026-02-10'): SpoRaw
    {
        $client = Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => 'T0000001',
            'first_name' => 'Гулнора',
            'last_name' => 'Файзуллоева',
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => 'Файзуллоева Гулнора',
        ]);

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
}
