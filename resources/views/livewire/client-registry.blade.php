<div>
    <h1 class="text-2xl font-semibold mb-4">Реестр клиентов</h1>

    <div class="mb-4">
        @if ($queue !== [])
            <div wire:poll.200ms="{{ $activeOperation === 'ingest' ? 'processNextIngestItem' : 'processNextRecomputeItem' }}">
                <div class="w-full max-w-md bg-gray-200 rounded h-4 overflow-hidden">
                    <div class="bg-blue-600 h-4 transition-all" style="width: {{ $total > 0 ? (int) ($done / $total * 100) : 0 }}%"></div>
                </div>
                <p class="text-sm text-gray-600 mt-1">Обработано {{ $done }} из {{ $total }}</p>
            </div>
        @else
            <div class="flex flex-wrap gap-3">
                <button
                    type="button"
                    wire:click="startIngest"
                    class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
                >Загрузить новые СПО из папки</button>

                <button
                    type="button"
                    wire:click="startRecompute"
                    wire:confirm="Пересчитать карточки всех клиентов через Claude API?"
                    class="bg-gray-700 text-white px-4 py-2 rounded hover:bg-gray-800"
                >Пересчитать карточки клиентов</button>
            </div>

            @if ($lastSummary !== null)
                <div class="mt-3 text-sm text-gray-700">
                    @if ($activeOperation === 'ingest')
                        Обработано: {{ $lastSummary['processedCount'] }}.
                        Пропущено: {{ $lastSummary['skippedCount'] }}.
                        Ошибок: {{ $lastSummary['failedCount'] }}.

                        @if ($lastSummary['failures'] !== [])
                            <div class="mt-2 text-red-700">
                                Файлы, обработка которых завершилась ошибкой:
                                <ul class="list-disc list-inside">
                                    @foreach ($lastSummary['failures'] as $fileName => $errorMessage)
                                        <li>{{ $fileName }}: {{ $errorMessage }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($lastSummary['cardFailures'] !== [])
                            <div class="mt-2 text-amber-700">
                                XML сохранён, но пересчёт карточки через Claude API не удался:
                                <ul class="list-disc list-inside">
                                    @foreach ($lastSummary['cardFailures'] as $clientId => $errorMessage)
                                        <li>клиент #{{ $clientId }}: {{ $errorMessage }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @elseif ($activeOperation === 'recompute')
                        Запущен пересчёт для {{ $lastSummary['dispatchedCount'] }} клиент(ов).

                        @if ($lastSummary['failures'] !== [])
                            <div class="mt-2 text-red-700">
                                Не удалось пересчитать:
                                <ul class="list-disc list-inside">
                                    @foreach ($lastSummary['failures'] as $clientId => $errorMessage)
                                        <li>клиент #{{ $clientId }}: {{ $errorMessage }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </div>
            @endif
        @endif
    </div>

    <input
        type="text"
        wire:model.live.debounce.400ms="search"
        placeholder="Поиск по ФИО/названию, документу или ИНН"
        class="w-full max-w-md border border-gray-300 rounded px-3 py-2 mb-4"
    >

    <div class="overflow-x-auto bg-white rounded border border-gray-200">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-left text-gray-600">
                <tr>
                    <th class="px-4 py-2">ФИО / название</th>
                    <th class="px-4 py-2">Тип</th>
                    <th class="px-4 py-2">Документ</th>
                    <th class="px-4 py-2">Риск</th>
                    <th class="px-4 py-2">СПО</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($clients as $client)
                    <tr wire:key="client-{{ $client->id }}">
                        <td class="px-4 py-2">
                            <a href="{{ route('clients.show', $client) }}" class="text-blue-600 hover:underline">{{ $client->full_name }}</a>
                        </td>
                        <td class="px-4 py-2">{{ $client->party_type === \App\Enums\PartyType::Individual ? 'Физлицо' : 'Юрлицо' }}</td>
                        <td class="px-4 py-2">{{ $client->doc_number ?: $client->tax_pay_number ?: '—' }}</td>
                        <td class="px-4 py-2"><x-risk-badge :label="$this->riskLevel($client)" /></td>
                        <td class="px-4 py-2">{{ $client->spo_raws_count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-6 text-center text-gray-500">Клиенты не найдены</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $clients->links() }}
    </div>
</div>
