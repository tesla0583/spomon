<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Корень приложения — редирект на реестр клиентов (см. routes/web.php).
     */
    public function test_the_application_redirects_to_the_client_registry(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/clients');
    }
}
