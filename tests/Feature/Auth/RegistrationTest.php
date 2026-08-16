<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\NewUserRegistered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    // spec-089: User implements MustVerifyEmail, so the Registered event fires
    // Illuminate\Auth\Notifications\VerifyEmail (the on-ramp toward requiring a
    // verified email). The mailer is 'log'/'array', so this activates the
    // verification infrastructure rather than delivering to an inbox — real
    // verification (and any future favorites `verified`-gate) needs SMTP.
    public function test_registration_sends_email_verification_notification(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Verify Me',
            'email' => 'verifyme@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'verifyme@example.com')->first();
        $this->assertNotNull($user);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_notifies_admin_recipients_when_configured(): void
    {
        Notification::fake();
        config()->set('services.admin_notify_emails', ['admin@example.com', 'ops@example.com']);

        $this->post('/register', [
            'name' => 'Notify Admin',
            'email' => 'notify@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Notification::assertSentOnDemand(
            NewUserRegistered::class,
            function (NewUserRegistered $notification, array $channels, $notifiable) {
                return in_array('mail', $channels, true)
                    && $notification->name === 'Notify Admin'
                    && $notification->email === 'notify@example.com'
                    && $notifiable->routes['mail'] === ['admin@example.com', 'ops@example.com'];
            }
        );
    }

    public function test_registration_does_not_notify_admins_when_unconfigured(): void
    {
        Notification::fake();
        config()->set('services.admin_notify_emails', []);

        $this->post('/register', [
            'name' => 'Silent User',
            'email' => 'silent@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        Notification::assertSentOnDemandTimes(NewUserRegistered::class, 0);
    }

    public function test_duplicate_email_registration_is_rejected(): void
    {
        User::factory()->create(['email' => 'dupe@example.com']);

        $response = $this->post('/register', [
            'name' => 'Duplicate Email',
            'email' => 'dupe@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
    }

    // Decision (unchanged behavior, made explicit): registration auto-logs the
    // user in immediately, but protected routes are gated on `verified` — so an
    // unverified user reaching /dashboard is bounced to /verify-email.
    public function test_unverified_registered_user_is_redirected_to_verification_notice_from_dashboard(): void
    {
        $this->post('/register', [
            'name' => 'Unverified User',
            'email' => 'unverified@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();

        $this->get(route('dashboard', absolute: false))
            ->assertRedirect(route('verification.notice', absolute: false));
    }
}
