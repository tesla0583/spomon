<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\EntityType;
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

        return view('livewire.client-card-page', [
            'card' => $this->client->card,
            'spoHistory' => $spoHistory,
            'networkReferences' => $this->networkReferences(),
        ]);
    }

    /**
     * Человекочитаемый список известных связей клиента — та же логика пересечения
     * сущностей, что и в App\Repositories\EntityRepository::findKnownNetworkEntityReferences()
     * (используется для промпта Claude API и не меняется, т.к. её вывод завязан на
     * существующие тесты и content, отправляемый в LLM), но с именем другого клиента
     * вместо голого client_id — это чисто для отображения на карточке.
     *
     * @return array<int, string>
     */
    private function networkReferences(): array
    {
        $edges = app(EntityRepository::class)->findNetworkGraphEdges($this->client->id);

        $otherClientNames = Client::query()
            ->whereIn('id', array_column($edges, 'other_client_id'))
            ->pluck('full_name', 'id');

        return array_map(static fn (array $edge): string => sprintf(
            '%s "%s" уже встречался в СПО клиента %s',
            EntityType::from($edge['entity_type'])->displayLabel(),
            $edge['entity_label'],
            $otherClientNames[$edge['other_client_id']] ?? sprintf('#%d', $edge['other_client_id']),
        ), $edges);
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
