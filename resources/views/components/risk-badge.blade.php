{{--
    Бейдж риск-метки клиента.

    @param ?\App\Enums\RiskLabel $label  null — карточка ещё не рассчитана LLM
--}}
@props(['label' => null])

@if ($label === null)
    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">—</span>
@else
    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $label->badgeColor() }}">
        {{ $label->label() }}
    </span>
@endif
