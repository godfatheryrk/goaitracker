<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_users_can_authenticate_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_repeated_failed_logins_trip_the_throttle(): void
    {
        Event::fake([Lockout::class]);
        $user = User::factory()->create();

        // 5 failed attempts saturates the limiter; the 6th attempt should lock out
        // before Auth::attempt is even called.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        Event::assertDispatched(Lockout::class);
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_successful_login_clears_the_rate_limiter(): void
    {
        $user = User::factory()->create();

        // 4 failed attempts (still under the 5-attempt cap)
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        // A valid login afterwards must clear the limiter — without
        // RateLimiter::clear() in LoginRequest::authenticate(), the next
        // login after a few bad ones could trip the lockout.
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);
        $this->assertAuthenticated();

        $key = Str::transliterate(Str::lower($user->email).'|127.0.0.1');
        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_guests_are_redirected_to_login_when_accessing_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_reach_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
