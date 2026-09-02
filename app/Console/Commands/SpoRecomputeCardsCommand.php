<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cards\ClientCardRecomputeService;
use Illuminate\Console\Command;

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
 *
 * Сама логика (список клиентов на пересчёт, dispatch с отловом исключения) вынесена в
 * App\Services\Cards\ClientCardRecomputeService — используется также Livewire-прогресс-баром
 * реестра клиентов (App\Livewire\ClientRegistry).
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

    public function handle(ClientCardRecomputeService $service): int
    {
        $clientIdArgument = $this->argument('client_id');

        if ($clientIdArgument !== null) {
            $clientId = (int) $clientIdArgument;
            $error = $service->recomputeOne($clientId);

            $this->info('Запущен пересчёт для 1 клиент(ов).');

            if ($error !== null) {
                $this->newLine();
                $this->error('Не удалось пересчитать:');
                $this->line(sprintf('  - клиент #%d: %s', $clientId, $error));

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        $summary = $service->recomputeAll();

        if ($summary->dispatchedCount === 0) {
            $this->info('Нет клиентов с СПО — пересчитывать нечего.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Запущен пересчёт для %d клиент(ов).', $summary->dispatchedCount));

        if ($summary->failures !== []) {
            $this->newLine();
            $this->error('Не удалось пересчитать:');

            foreach ($summary->failures as $clientId => $errorMessage) {
                $this->line(sprintf('  - клиент #%d: %s', $clientId, $errorMessage));
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
