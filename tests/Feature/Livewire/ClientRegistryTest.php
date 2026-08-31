<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\PartyType;
use App\Livewire\ClientRegistry;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ClientRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully_with_clients(): void
    {
        $this->createClient('T0000001', 'Клиент Один');
        $this->createClient('T0000002', 'Клиент Два');

        Livewire::test(ClientRegistry::class)->assertOk();
    }

    public function test_search_by_doc_number_narrows_results(): void
    {
        $clientA = $this->createClient('T0000001', 'Клиент Один');
        $clientB = $this->createClient('T0000002', 'Клиент Два');

        Livewire::test(ClientRegistry::class)
            ->set('search', 'T0000001')
            ->assertSee($clientA->full_name)
            ->assertDontSee($clientB->full_name);
    }

    public function test_client_full_name_links_to_the_card_page(): void
    {
        $client = $this->createClient('T0000001', 'Клиент Один');

        Livewire::test(ClientRegistry::class)
            ->assertSeeHtml('href="'.route('clients.show', $client).'"');
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
}
