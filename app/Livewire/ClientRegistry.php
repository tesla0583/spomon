<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\SpoFileIngestOutcome;
use App\Models\Client;
use App\Services\Cards\ClientCardRecomputeService;
use App\Services\Ingestion\SpoFileIngestionService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Реестр клиентов банка с живым поиском по ФИО/названию, документу и ИНН, а также кнопками
 * запуска загрузки новых СПО и пересчёта карточек клиентов.
 *
 * Обе операции обрабатываются по одному элементу за wire:poll-тик (см. processNextIngestItem()/
 * processNextRecomputeItem()) — без отдельного очередного воркера, что работает и при
 * QUEUE_CONNECTION=sync. Активна одновременно только одна операция: $queue хранит либо пути
 * файлов (ingest), либо ID клиентов (recompute), но не смесь — это отражено в $activeOperation.
 */
final class ClientRegistry extends Component
{
    use WithPagination;

    public string $search = '';

    /** @var array<int, string|int> оставшиеся элементы очереди: пути к файлам либо client_id */
    public array $queue = [];

    public int $total = 0;

    public int $done = 0;

    /** @var array<string, mixed>|null */
    public ?array $lastSummary = null;

    /** @var 'ingest'|'recompute'|null */
    public ?string $activeOperation = null;

    /** @var array<string, mixed> промежуточный результат текущей операции, копится по одному элементу за тик */
    public array $accumulator = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function startIngest(): void
    {
        $incomingPath = storage_path('app/spo/incoming');

        // Тот же набор файлов, что взяла бы Form101Parser-обработка через
        // SpoFileIngestionService::listXmlFiles() — только *.xml, папка может ещё не
        // существовать (File::files() иначе бросит исключение на отсутствующей директории).
        $files = File::isDirectory($incomingPath)
            ? collect(File::files($incomingPath))
                ->filter(static fn ($file) => strtolower($file->getExtension()) === 'xml')
                ->map(static fn ($file) => $file->getPathname())
                ->values()
                ->all()
            : [];

        $this->queue = $files;
        $this->total = count($files);
        $this->done = 0;
        $this->lastSummary = null;
        $this->activeOperation = 'ingest';
        $this->accumulator = [
            'processedCount' => 0,
            'skippedCount' => 0,
            'failedCount' => 0,
            'failures' => [],
            'cardFailures' => [],
        ];
    }

    public function startRecompute(ClientCardRecomputeService $service): void
    {
        $clientIds = $service->listClientIdsPendingRecompute();

        $this->queue = $clientIds;
        $this->total = count($clientIds);
        $this->done = 0;
        $this->lastSummary = null;
        $this->activeOperation = 'recompute';
        $this->accumulator = [
            'dispatchedCount' => 0,
            'failures' => [],
        ];
    }

    /**
     * Обрабатывает один файл из очереди за один poll-тик.
     *
     * Закрытие вкладки посреди прогресса безопасно: необработанные файлы остаются в
     * storage/app/spo/incoming и будут подхвачены при следующем запуске (повторным кликом
     * на эту кнопку или php artisan spo:ingest) — идемпотентность обеспечивается таблицей
     * spo_file_ingestions, как и раньше.
     */
    public function processNextIngestItem(SpoFileIngestionService $service): void
    {
        if ($this->queue === []) {
            return;
        }

        $filePath = array_shift($this->queue);
        $result = $service->ingestFile($filePath);

        match ($result->outcome) {
            SpoFileIngestOutcome::Processed => $this->accumulator['processedCount']++,
            SpoFileIngestOutcome::Skipped => $this->accumulator['skippedCount']++,
            SpoFileIngestOutcome::Failed => $this->accumulator['failedCount']++,
        };

        if ($result->outcome === SpoFileIngestOutcome::Failed) {
            $this->accumulator['failures'][$result->fileName] = $result->errorMessage;
        }

        if ($result->cardFailureMessage !== null) {
            $this->accumulator['cardFailures'][$result->clientId] = $result->cardFailureMessage;
        }

        $this->done++;

        if ($this->queue === []) {
            $this->lastSummary = $this->accumulator;
        }
    }

    /**
     * Обрабатывает одного клиента из очереди за один poll-тик.
     *
     * Закрытие вкладки посреди прогресса безопасно: пересчёт идемпотентен по
     * history_fingerprint (см. App\Jobs\ComputeClientCardJob) — недообработанные клиенты
     * просто пересчитаются заново при следующем клике на эту кнопку.
     */
    public function processNextRecomputeItem(ClientCardRecomputeService $service): void
    {
        if ($this->queue === []) {
            return;
        }

        $clientId = array_shift($this->queue);
        $error = $service->recomputeOne($clientId);

        $this->accumulator['dispatchedCount']++;

        if ($error !== null) {
            $this->accumulator['failures'][$clientId] = $error;
        }

        $this->done++;

        if ($this->queue === []) {
            $this->lastSummary = $this->accumulator;
        }
    }

    public function render(): View
    {
        $clients = Client::query()
            ->with('card')
            ->withCount('spoRaws')
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('full_name', 'like', "%{$this->search}%")
                        ->orWhere('doc_number', $this->search)
                        ->orWhere('tax_pay_number', $this->search);
                });
            })
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.client-registry', ['clients' => $clients]);
    }
}
