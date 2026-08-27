<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\DTOs\IndividualPartyDto;
use App\DTOs\LegalEntityPartyDto;
use App\Enums\PartyType;
use App\Models\Client;
use App\Repositories\ClientRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClientRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private ClientRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ClientRepository;
    }

    public function test_exact_doc_number_match_takes_priority_over_fuzzy_name_match(): void
    {
        $existing = Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => 'T0000001',
            'first_name' => 'Гулнора',
            'last_name' => 'Файзуллоева',
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => 'Файзуллоева Гулнора',
        ]);

        // Тот же документ, но совсем другое ФИО и другая дата рождения (например, опечатка
        // при вводе в другой раз) — точный doc_number всё равно должен победить.
        $party = new IndividualPartyDto(
            docNumber: 'T0000001',
            firstName: 'Петр',
            lastName: 'Иванов',
            dob: '1975-01-01',
        );

        $result = $this->repository->findOrCreateIndividual($party);

        self::assertSame($existing->id, $result->id);
        self::assertSame(1, Client::query()->count());
    }

    public function test_similar_full_name_with_same_dob_matches_existing_client(): void
    {
        $existing = Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => null,
            'first_name' => 'Гулнора',
            'last_name' => 'Файзуллоева',
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => 'Файзуллоева Гулнора',
        ]);

        $party = new IndividualPartyDto(
            docNumber: '',
            firstName: 'Гулнора',
            lastName: 'Файзулоева',
            dob: '1990-05-10',
        );

        $result = $this->repository->findOrCreateIndividual($party);

        self::assertSame($existing->id, $result->id);
        self::assertSame(1, Client::query()->count());
    }

    public function test_similar_full_name_with_different_dob_creates_new_client(): void
    {
        Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => null,
            'first_name' => 'Гулнора',
            'last_name' => 'Файзуллоева',
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => 'Файзуллоева Гулнора',
        ]);

        $party = new IndividualPartyDto(
            docNumber: '',
            firstName: 'Гулнора',
            lastName: 'Файзулоева',
            dob: '1985-11-20',
        );

        $result = $this->repository->findOrCreateIndividual($party);

        self::assertSame(2, Client::query()->count());
        self::assertNotNull($result->id);
        self::assertSame('Файзулоева Гулнора', $result->full_name);
    }

    public function test_dissimilar_full_name_with_same_dob_creates_new_client(): void
    {
        Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => null,
            'first_name' => 'Гулнора',
            'last_name' => 'Файзуллоева',
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => 'Файзуллоева Гулнора',
        ]);

        $party = new IndividualPartyDto(
            docNumber: '',
            firstName: 'Петр',
            lastName: 'Иванов',
            dob: '1990-05-10',
        );

        $result = $this->repository->findOrCreateIndividual($party);

        self::assertSame(2, Client::query()->count());
        self::assertSame('Иванов Петр', $result->full_name);
    }

    public function test_empty_doc_number_and_empty_dob_creates_new_client_without_running_fuzzy_match(): void
    {
        Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => null,
            'first_name' => 'Гулнора',
            'last_name' => 'Файзуллоева',
            'middle_name' => null,
            'dob' => null,
            'full_name' => 'Файзуллоева Гулнора',
        ]);

        // Точно такое же ФИО, но без doc_number и без dob — fuzzy не должен запускаться
        // без dob, поэтому это всегда новый клиент, а не совпадение по имени.
        $party = new IndividualPartyDto(
            docNumber: '',
            firstName: 'Гулнора',
            lastName: 'Файзуллоева',
            dob: null,
        );

        $result = $this->repository->findOrCreateIndividual($party);

        self::assertSame(2, Client::query()->count());
        self::assertNull($result->doc_number);
    }

    public function test_legal_entity_matches_only_on_exact_tax_pay_number_without_fuzzy(): void
    {
        $existing = Client::create([
            'party_type' => PartyType::LegalEntity,
            'tax_pay_number' => '010000001',
            'full_name' => 'ООО Тестовая Компания',
        ]);

        $party = new LegalEntityPartyDto(
            taxPayNumber: '010000001',
            name: 'Совершенно другое название',
        );

        $result = $this->repository->findOrCreateLegalEntity($party);

        self::assertSame($existing->id, $result->id);
        self::assertSame(1, Client::query()->count());

        // Другой tax_pay_number с похожим или даже идентичным названием — новый клиент,
        // fuzzy-логика для юрлиц не применяется вообще.
        $anotherParty = new LegalEntityPartyDto(
            taxPayNumber: '020000002',
            name: 'ООО Тестовая Компания',
        );

        $anotherResult = $this->repository->findOrCreateLegalEntity($anotherParty);

        self::assertNotSame($existing->id, $anotherResult->id);
        self::assertSame(2, Client::query()->count());
    }
}
