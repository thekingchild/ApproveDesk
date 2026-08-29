<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_login_page_as_the_default_home(): void
    {
        $response = $this->get(route('home'));

        $response->assertRedirect(route('login'));
    }
}
