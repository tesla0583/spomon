<?php

declare(strict_types=1);

namespace App\Services\Cards;

use App\DTOs\RecomputeSummaryDto;
use App\Jobs\ComputeClientCardJob;
use App\Models\Client;
use Throwable;

/**
 * Пересчёт карточек клиентов через Claude API без повторной загрузки XML — см.
 * App\Console\Commands\SpoRecomputeCardsCommand и Livewire-прогресс-бар реестра клиентов
 * (App\Livewire\ClientRegistry).
 */
final class ClientCardRecomputeService
{
    /**
     * @return array<int, int> ID клиентов, у которых есть хотя бы одно СПО
     */
    public function listClientIdsPendingRecompute(): array
    {
        return Client::query()->whereHas('spoRaws')->pluck('id')->all();
    }

    /**
     * Пересчитывает карточку одного клиента, отлавливая исключение вместо того, чтобы дать
     * ему прервать пересчёт остальных клиентов в {@see self::recomputeAll()}.
     *
     * @return string|null текст ошибки, либо null при успешном dispatch
     */
    public function recomputeOne(int $clientId): ?string
    {
        try {
            ComputeClientCardJob::dispatch($clientId);

            return null;
        } catch (Throwable $e) {
            report($e);

            return $e->getMessage();
        }
    }

    public function recomputeAll(): RecomputeSummaryDto
    {
        $clientIds = $this->listClientIdsPendingRecompute();

        $failures = [];

        foreach ($clientIds as $clientId) {
            $error = $this->recomputeOne($clientId);

            if ($error !== null) {
                $failures[$clientId] = $error;
            }
        }

        return new RecomputeSummaryDto(
            dispatchedCount: count($clientIds),
            failures: $failures,
        );
    }
}
