<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200)
            ->assertSee('https://github.com/Nenad005/fon-banking-backend', escape: false)
            ->assertSee('https://github.com/Nenad005/fon-banking-frontend', escape: false)
            ->assertSee('href="/api/documentation"', escape: false)
            ->assertSee('href="/laravel-erd/fon-banking"', escape: false);
    }
}
