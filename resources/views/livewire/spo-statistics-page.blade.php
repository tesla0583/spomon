<div>
    <h1 class="text-2xl font-semibold mb-4">Статистика по СПО</h1>

    <div class="flex items-end gap-4 mb-6">
        <div>
            <label class="block text-sm text-gray-600 mb-1">С</label>
            <input type="date" wire:model.live="dateFrom" class="border border-gray-300 rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1">По</label>
            <input type="date" wire:model.live="dateTo" class="border border-gray-300 rounded px-3 py-2">
        </div>
    </div>

    <div class="bg-white rounded border border-gray-200 p-6 mb-4">
        <div class="text-sm text-gray-500">Всего СПО за период</div>
        <div class="text-4xl font-semibold">{{ $summary->totalCount }}</div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach (\App\Enums\RiskLabel::cases() as $case)
            <div class="bg-white rounded border border-gray-200 p-4">
                <x-risk-badge :label="$case" />
                <div class="text-2xl font-semibold mt-2">{{ $summary->countsByRiskLabel[$case->value] }}</div>
            </div>
        @endforeach
    </div>
</div>
