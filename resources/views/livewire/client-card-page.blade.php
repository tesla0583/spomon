<div>
    <a href="{{ route('clients.index') }}" class="text-sm text-blue-600 hover:underline">&larr; К реестру клиентов</a>

    {{-- Данные клиента --}}
    <div class="bg-white rounded border border-gray-200 p-6 mt-3">
        <h1 class="text-2xl font-semibold">{{ $client->full_name }}</h1>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-1 mt-3 text-sm">
            <div>
                <dt class="text-gray-500">Тип</dt>
                <dd>{{ $client->party_type === \App\Enums\PartyType::Individual ? 'Физлицо' : 'Юрлицо' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Документ</dt>
                <dd>{{ $client->doc_number ?: $client->tax_pay_number ?: '—' }}</dd>
            </div>
            @if ($client->party_type === \App\Enums\PartyType::Individual)
                <div>
                    <dt class="text-gray-500">Дата рождения</dt>
                    <dd>{{ $client->dob?->format('d.m.Y') ?? '—' }}</dd>
                </div>
            @endif
        </dl>
    </div>

    {{-- Карточка LLM --}}
    <div class="bg-white rounded border border-gray-200 p-6 mt-4">
        <h2 class="text-lg font-semibold mb-3">Карточка риска</h2>

        <div class="flex items-center gap-3 mb-3">
            <x-risk-badge :label="$riskLevel" />
            @if ($card)
                <span class="text-xs text-gray-500">рассчитано {{ $card->computed_at?->format('d.m.Y H:i') ?? '—' }}</span>
            @endif
        </div>

        @if ($card)
            <p class="text-sm mb-3">{{ $card->summary }}</p>

            @if ($card->pattern_notes)
                <div class="text-sm mb-3">
                    <span class="font-medium">Паттерн:</span> {{ $card->pattern_notes }}
                </div>
            @endif

            @if ($card->network_signal)
                <div class="text-sm">
                    <span class="font-medium">Сигнал сети:</span> {{ $card->network_signal }}
                </div>
            @endif
        @else
            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded px-4 py-3 text-sm">
                Карточка ещё не рассчитана
            </div>
        @endif
    </div>

    {{-- Известные связи --}}
    @if (! empty($networkReferences))
        <div class="bg-white rounded border border-gray-200 p-6 mt-4">
            <h2 class="text-lg font-semibold mb-3">Известные связи</h2>
            <ul class="list-disc list-inside text-sm space-y-1">
                @foreach ($networkReferences as $reference)
                    <li>{{ $reference }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Граф связей --}}
    <div class="bg-white rounded border border-gray-200 p-6 mt-4">
        <h2 class="text-lg font-semibold mb-3">Граф связей</h2>
        <livewire:client-network-graph :client-id="$client->id" />
    </div>

    {{-- История СПО --}}
    <div class="bg-white rounded border border-gray-200 p-6 mt-4">
        <h2 class="text-lg font-semibold mb-3">История СПО</h2>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100 text-left text-gray-600">
                    <tr>
                        <th class="px-3 py-2">Дата</th>
                        <th class="px-3 py-2">Сумма</th>
                        <th class="px-3 py-2">Тип/подтип/детали</th>
                        <th class="px-3 py-2">Назначение</th>
                        <th class="px-3 py-2">Основание</th>
                        <th class="px-3 py-2">Контрагент</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 align-top">
                    @forelse ($spoHistory as $spo)
                        <tr wire:key="spo-{{ $spo->id }}">
                            <td class="px-3 py-2 whitespace-nowrap">{{ $spo->transaction_date?->format('d.m.Y') ?? '—' }}</td>
                            <td class="px-3 py-2 whitespace-nowrap">{{ $spo->amount !== null ? $spo->amount.' '.$spo->currency : '—' }}</td>
                            <td class="px-3 py-2">{{ implode(' / ', array_filter([$spo->transaction_type, $spo->transaction_subtype, $spo->details])) ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $spo->transaction_desc ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $spo->ground_text ?: '—' }}</td>
                            <td class="px-3 py-2">{{ $this->otherSideSummary($spo->other_side) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-6 text-center text-gray-500">СПО не найдены</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
