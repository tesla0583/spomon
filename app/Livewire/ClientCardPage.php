<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PartyType;
use App\Models\Client;
use App\Repositories\EntityRepository;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Карточка клиента: данные, результат LLM-анализа, известные связи в графе,
 * полная история СПО.
 */
final class ClientCardPage extends Component
{
    public Client $client;

    public function mount(Client $client): void
    {
        $this->client = $client;
        $this->client->load('card');
    }

    public function render(): View
    {
        $spoHistory = $this->client->spoRaws()->orderBy('transaction_date')->get();

        $networkReferences = app(EntityRepository::class)
            ->findKnownNetworkEntityReferences($this->client->id);

        return view('livewire.client-card-page', [
            'card' => $this->client->card,
            'spoHistory' => $spoHistory,
            'networkReferences' => $networkReferences,
        ]);
    }

    /**
     * Сжатая человекочитаемая расшифровка второй стороны операции (other_side).
     *
     * Формат подтверждён по App\Services\Ingestion\SpoFileIngestionService::otherSideToArray():
     * физлицо — party_type/doc_number/first_name/last_name/middle_name/dob;
     * юрлицо — party_type/tax_pay_number/name/leg_org_form.
     *
     * @param  array<string, string|null>|null  $otherSide
     */
    public function otherSideSummary(?array $otherSide): string
    {
        if ($otherSide === null) {
            return '—';
        }

        if (($otherSide['party_type'] ?? null) === PartyType::Individual->value) {
            $name = implode(' ', array_filter([
                $otherSide['last_name'] ?? null,
                $otherSide['first_name'] ?? null,
                $otherSide['middle_name'] ?? null,
            ]));

            return trim(sprintf('%s, паспорт %s', $name, $otherSide['doc_number'] ?? '—'));
        }

        if (($otherSide['party_type'] ?? null) === PartyType::LegalEntity->value) {
            return trim(sprintf(
                '%s (%s), ИНН %s',
                $otherSide['name'] ?? '—',
                $otherSide['leg_org_form'] ?? '—',
                $otherSide['tax_pay_number'] ?? '—',
            ));
        }

        return json_encode($otherSide, JSON_UNESCAPED_UNICODE) ?: '—';
    }
}
