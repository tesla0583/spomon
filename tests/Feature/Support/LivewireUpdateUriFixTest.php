<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use Tests\TestCase;

/**
 * Регрессия для App\Support\LivewireUpdateUriFix — см. подробный докблок класса и
 * App\Providers\AppServiceProvider::boot() (переопределение @livewireScripts).
 *
 * Баг: под OpenServer приложение отдаётся из поддиректории (http://localhost/spomon/public),
 * а Livewire строит data-update-uri в режиме "относительного" URL, который вычитает
 * базовый путь запроса и теряет префикс — AJAX-запросы (поиск, пагинация, любой
 * wire:model) 404-ят на уровне веб-сервера, не долетая до Laravel.
 */
final class LivewireUpdateUriFixTest extends TestCase
{
    public function test_update_uri_gets_app_url_path_prefix_when_hosted_under_a_subdirectory(): void
    {
        config(['app.url' => 'http://example.com/spomon/public']);

        $this->get('/clients')
            ->assertOk()
            ->assertSee('data-update-uri="/spomon/public/livewire/update"', false);
    }

    public function test_update_uri_is_left_untouched_when_app_is_hosted_at_domain_root(): void
    {
        config(['app.url' => 'http://example.com']);

        $this->get('/clients')
            ->assertOk()
            ->assertSee('data-update-uri="/livewire/update"', false);
    }
}
