<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    public function test_swagger_ui_is_available(): void
    {
        $this->get('/api/documentation')
            ->assertOk()
            ->assertSee('FON Banking API')
            ->assertSee('SwaggerUIBundle', escape: false);
    }

    public function test_openapi_yaml_is_available(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml')
            ->assertSee('openapi: 3.1.0', escape: false)
            ->assertSee('title: FON Banking REST API', escape: false);
    }
}
