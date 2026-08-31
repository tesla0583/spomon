<div>
    <h1 class="text-2xl font-semibold mb-4">Реестр клиентов</h1>

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
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($clients as $client)
                    <tr wire:key="client-{{ $client->id }}">
                        <td class="px-4 py-2">{{ $client->full_name }}</td>
                        <td class="px-4 py-2">{{ $client->party_type === \App\Enums\PartyType::Individual ? 'Физлицо' : 'Юрлицо' }}</td>
                        <td class="px-4 py-2">{{ $client->doc_number ?: $client->tax_pay_number ?: '—' }}</td>
                        <td class="px-4 py-2"><x-risk-badge :label="$client->card?->risk_label" /></td>
                        <td class="px-4 py-2">{{ $client->spo_raws_count }}</td>
                        <td class="px-4 py-2">
                            <a href="{{ route('clients.show', $client) }}" class="text-blue-600 hover:underline">Открыть</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-gray-500">Клиенты не найдены</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $clients->links() }}
    </div>
</div>
