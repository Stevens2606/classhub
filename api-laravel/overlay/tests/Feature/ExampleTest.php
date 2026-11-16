<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_page_redirects_to_the_web_app(): void
    {
        $this->get('/')->assertRedirect('/app/index.html');
    }

    public function test_health_endpoint_is_up(): void
    {
        $this->get('/up')->assertOk();
    }
}
