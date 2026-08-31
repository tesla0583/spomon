<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Stats\SpoStatisticsService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Автоматическая статистика по СПО за выбранный период.
 */
final class SpoStatisticsPage extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = Carbon::now()->startOfMonth()->toDateString();
        $this->dateTo = Carbon::now()->toDateString();
    }

    public function render(): View
    {
        $summary = app(SpoStatisticsService::class)->summarize(
            Carbon::parse($this->dateFrom),
            Carbon::parse($this->dateTo),
        );

        return view('livewire.spo-statistics-page', ['summary' => $summary]);
    }
}
