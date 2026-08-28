<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ComputeClientCardJob;
use App\Models\Client;
use Illuminate\Console\Command;
use Throwable;

/**
 * Пересчитывает карточки клиентов через Claude API без повторной загрузки XML.
 *
 * Нужна как способ восстановления после сбоя именно на этапе вызова LLM: сбой
 * ComputeClientCardJob внутри SpoFileIngestionService не переводит уже сохранённый XML
 * в failed (см. докблок сервиса), поэтому единственный способ повторить сам пересчёт
 * карточки — эта команда, а не повторная загрузка файла.
 *
 * Идемпотентна: ComputeClientCardJob сам пропускает клиента, если history_fingerprint
 * не изменился с последнего успешного вызова — лишний вызов Claude API не произойдёт.
 */
class SpoRecomputeCardsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'spo:recompute-cards {client_id? : ID конкретного клиента; без аргумента — все клиенты, у которых есть СПО}';

    /**
     * @var string
     */
    protected $description = 'Пересчитать карточки клиентов через Claude API, не трогая уже загруженные XML-файлы';

    public function handle(): int
    {
        $clientIdArgument = $this->argument('client_id');

        $clientIds = $clientIdArgument !== null
            ? [(int) $clientIdArgument]
            : Client::query()->whereHas('spoRaws')->pluck('id')->all();

        if ($clientIds === []) {
            $this->info('Нет клиентов с СПО — пересчитывать нечего.');

            return self::SUCCESS;
        }

        $failures = [];

        foreach ($clientIds as $clientId) {
            try {
                ComputeClientCardJob::dispatch($clientId);
            } catch (Throwable $e) {
                $failures[$clientId] = $e->getMessage();
            }
        }

        $this->info(sprintf('Запущен пересчёт для %d клиент(ов).', count($clientIds)));

        if ($failures !== []) {
            $this->newLine();
            $this->error('Не удалось пересчитать:');

            foreach ($failures as $clientId => $errorMessage) {
                $this->line(sprintf('  - клиент #%d: %s', $clientId, $errorMessage));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
