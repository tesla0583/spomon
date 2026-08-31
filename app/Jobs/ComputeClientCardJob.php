<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\LlmClientAnalysisRequestDto;
use App\Models\Client;
use App\Models\ClientCard;
use App\Repositories\EntityRepository;
use App\Services\Entities\EntityRegistrationService;
use App\Services\Llm\ClaudeApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Асинхронно пересчитывает карточку клиента ({@see ClientCard}) через Claude API — не
 * блокирует HTTP-запрос обработки XML-файла (см. CLAUDE.md, раздел "Очереди").
 *
 * Идемпотентно: если история СПО клиента не изменилась с последнего успешного вызова
 * (тот же {@see self::historyFingerprint()}), Claude API повторно не дёргается.
 * Диспетчеризуется из {@see \App\Services\Ingestion\SpoFileIngestionService} после
 * каждой новой записи СПО.
 */
final class ComputeClientCardJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $clientId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(
        ClaudeApiClient $api,
        EntityRepository $entityRepository,
        EntityRegistrationService $entityRegistrationService,
    ): void {
        $client = Client::findOrFail($this->clientId);

        $spoRaws = $client->spoRaws()->orderBy('transaction_date')->get();
        $fingerprint = $this->historyFingerprint($spoRaws->pluck('id'));

        $alreadyComputed = ClientCard::query()
            ->where('client_id', $client->id)
            ->where('history_fingerprint', $fingerprint)
            ->exists();

        if ($alreadyComputed) {
            // История СПО клиента не изменилась с последнего вызова — LLM не дёргаем.
            return;
        }

        $spoHistory = $spoRaws->map(static fn ($spoRaw) => [
            'date' => $spoRaw->transaction_date?->toDateString(),
            'amount' => $spoRaw->amount !== null ? (float) $spoRaw->amount : null,
            'currency' => $spoRaw->currency,
            'description' => $spoRaw->ground_text,
            'counterparty_structured' => $spoRaw->other_side,
        ])->all();

        $result = $api->analyzeClient(new LlmClientAnalysisRequestDto(
            clientId: $client->id,
            spoHistory: $spoHistory,
            knownNetworkEntities: $entityRepository->findKnownNetworkEntityReferences($client->id),
        ));

        ClientCard::updateOrCreate(
            ['client_id' => $client->id],
            [
                'risk_label' => $result->finalLabel,
                'summary' => $result->summary,
                'pattern_notes' => $result->patternNotes,
                'network_signal' => $this->networkSignalText($result->networkSignalFound, $result->networkSignalClientReference),
                'llm_raw_response' => $result->rawResponse,
                'history_fingerprint' => $fingerprint,
                'computed_at' => now(),
            ],
        );

        $entityRegistrationService->registerNerMentions($client, $result->extractedEntities);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $spoRawIds
     */
    private function historyFingerprint($spoRawIds): string
    {
        return hash('sha256', $spoRawIds->sort()->values()->implode(','));
    }

    private function networkSignalText(bool $found, ?string $reference): ?string
    {
        if (! $found) {
            return null;
        }

        return "Связь с сетью: контрагент упомянут в СПО клиента {$reference}";
    }
}
