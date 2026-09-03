{{--
    Бейдж уровня риска клиента.

    @param ?\App\Enums\RiskLevel $label  null допускается форматом компонента, но
                                          RiskLevel вычислим всегда — на практике null
                                          сюда не передаётся
--}}
@props(['label' => null])

@if ($label === null)
    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500">—</span>
@else
    <span class="inline-block px-2 py-0.5 rounded text-xs font-medium {{ $label->badgeColor() }}">
        {{ $label->label() }}
    </span>
@endif
