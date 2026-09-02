<?php

declare(strict_types=1);

namespace App\Support;

use Livewire\Mechanisms\FrontendAssets\FrontendAssets;

/**
 * Обходит баг относительной генерации URL Laravel/Livewire под поддиректорией.
 *
 * Локально (OpenServer) приложение отдаётся из `http://localhost/spomon/public`, а не
 * с корня домена. `Livewire\Mechanisms\HandleRequests\HandleRequests::getUpdateUri()`
 * строит `data-update-uri` через `app('url')->toRoute($route, [], false)` — режим
 * "относительного" URL. `Illuminate\Routing\RouteUrlGenerator::to()` в этом режиме
 * СНАЧАЛА строит полный абсолютный URL (корректно учитывая `URL::forceRootUrl()` из
 * {@see \App\Providers\AppServiceProvider::boot()}), а ЗАТЕМ вычитает из него
 * `$request->getBaseUrl()` — базовый путь ТЕКУЩЕГО HTTP-запроса, вычисленный Symfony по
 * SCRIPT_NAME/REQUEST_URI. Диагностика (public/index.php?__debug_script_name=1)
 * подтвердила, что SCRIPT_NAME корректен (`/spomon/public/index.php`) — именно поэтому
 * `getBaseUrl()` тоже корректно возвращает `/spomon/public`, и Laravel вычитает этот
 * префикс из итогового URL, оставляя `/livewire/update` вместо `/spomon/public/livewire/update`.
 * Браузер резолвит путь с ведущим `/` от КОРНЯ ДОМЕНА, а не от `/spomon/public` — отсюда
 * 404 от веб-сервера на каждый Livewire AJAX-запрос (поиск, пагинация, любой wire:model).
 *
 * Патчить `$_SERVER['SCRIPT_NAME']` в public/index.php (как предполагалось изначально)
 * не помогло бы: занижение SCRIPT_NAME чинит эту генерацию URL, но ЛОМАЕТ маршрутизацию
 * (Laravel матчит роуты по тому же самому base-path-вычитанию из pathInfo) — роуты
 * зарегистрированы без префикса (`/clients`, не `/spomon/public/clients`), так что
 * "исправленный" SCRIPT_NAME привёл бы к 404 уже на все обычные страницы.
 *
 * Правится через переопределение Blade-директивы `@livewireScripts` (см.
 * AppServiceProvider::boot()) — единственное место, где этот некорректный относительный
 * URL реально попадает в HTML (`<script data-update-uri="...">`), без изменения
 * маршрутизации и без патчей vendor/. Не-операция (возвращает оригинальный HTML без
 * изменений), если `config('app.url')` не содержит путь-префикс — т.е. само отключается
 * при нормальном деплое (сайт на корне домена) или в тестовом окружении.
 */
final class LivewireUpdateUriFix
{
    /**
     * @param  array<string, mixed>  $options
     */
    public static function scripts(array $options = []): string
    {
        $html = FrontendAssets::scripts($options);

        $prefix = rtrim((string) parse_url((string) config('app.url'), PHP_URL_PATH), '/');

        if ($prefix === '') {
            return $html;
        }

        return preg_replace_callback(
            '/data-update-uri="(\/[^"]*)"/',
            static function (array $matches) use ($prefix): string {
                $uri = $matches[1];

                if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
                    return $matches[0];
                }

                return 'data-update-uri="'.$prefix.$uri.'"';
            },
            $html,
            1,
        );
    }
}
