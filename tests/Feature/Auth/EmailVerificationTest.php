<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_can_be_rendered_for_unverified_users(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.notice'))
            ->assertOk()
            ->assertSee('Potwierdź adres e-mail');
    }

    public function test_email_can_be_verified_with_a_valid_signed_link(): void
    {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(10), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/bank?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    public function test_invalid_verification_link_does_not_verify_an_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('verification.verify', [
            'id' => $user->id,
            'hash' => sha1('different@example.com'),
        ]))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
