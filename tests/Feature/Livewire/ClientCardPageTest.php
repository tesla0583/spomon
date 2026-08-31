<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\PartyType;
use App\Enums\RiskLabel;
use App\Livewire\ClientCardPage;
use App\Models\Client;
use App\Models\ClientCard;
use App\Models\SpoRaw;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class ClientCardPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_successfully_for_client_with_full_data(): void
    {
        $client = Client::create([
            'party_type' => PartyType::Individual,
            'doc_number' => 'T0000001',
            'first_name' => 'Клиент',
            'last_name' => 'Один',
            'middle_name' => null,
            'dob' => '1990-05-10',
            'full_name' => 'Клиент Один',
        ]);

        ClientCard::create([
            'client_id' => $client->id,
            'risk_label' => RiskLabel::NeedsAttention,
            'summary' => 'Сводка по клиенту.',
            'pattern_notes' => null,
            'network_signal' => null,
            'llm_raw_response' => ['ok' => true],
            'history_fingerprint' => hash('sha256', (string) $client->id),
            'computed_at' => now(),
        ]);

        SpoRaw::create([
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

        Livewire::test(ClientCardPage::class, ['client' => $client])->assertOk();
    }

    public function test_returns_404_for_nonexistent_client(): void
    {
        $this->get(route('clients.show', 999999))->assertStatus(404);
    }
}
