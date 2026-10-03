<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\MockScreening;
use Tests\TestCase;

class ResearcherAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_all_research_pages_and_submission_require_sign_in(): void
    {
        foreach (['/admin', '/admin/screenings', '/admin/screenings/create', '/admin/screenings/missing-session'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
        $this->post('/admin/screenings', MockScreening::input())->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
    }

    public function test_researcher_can_sign_in_and_sign_out(): void
    {
        User::factory()->create(['email' => 'researcher@example.test', 'password' => Hash::make('synthetic-test-password')]);
        $this->post('/login', ['email' => 'researcher@example.test', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', ['email' => 'researcher@example.test', 'password' => 'synthetic-test-password'])->assertRedirect('/admin');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'none@example.test', 'password' => 'invalid']);
        }
        $this->post('/login', ['email' => 'none@example.test', 'password' => 'invalid'])->assertStatus(429);
    }
}
