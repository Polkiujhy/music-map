<?php

namespace Tests\Feature\StreamingAccounts;

use App\Http\Controllers\StreamingAccountController;
use App\Models\StreamingAccount;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use ReflectionMethod;
use Tests\TestCase;

class StreamingAccountAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_routes_have_stable_methods_paths_and_middleware(): void
    {
        $index = $this->route('integrations.index');
        $destroy = $this->route('integrations.destroy');

        $this->assertSame(['GET', 'HEAD'], $index->methods());
        $this->assertSame('integrations', $index->uri());
        $this->assertContains('auth', $index->gatherMiddleware());
        $this->assertContains('verified', $index->gatherMiddleware());

        $this->assertSame(['DELETE'], $destroy->methods());
        $this->assertSame('integrations/{streamingAccount}', $destroy->uri());
        $this->assertContains('auth', $destroy->gatherMiddleware());
        $this->assertContains('verified', $destroy->gatherMiddleware());

        $parameter = (new ReflectionMethod(StreamingAccountController::class, 'destroy'))->getParameters()[1];
        $this->assertSame('streamingAccount', $parameter->getName());
        $this->assertSame('string', (string) $parameter->getType());
    }

    public function test_guest_and_unverified_users_cannot_manage_integrations(): void
    {
        $account = StreamingAccount::factory()->spotify()->create();

        $this->get(route('integrations.index'))->assertRedirect(route('login'));
        $this->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes'])
            ->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get(route('integrations.index'))
            ->assertRedirect(route('verification.notice'));
        $this->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes'])
            ->assertRedirect(route('verification.notice'));
        $this->assertDatabaseHas('streaming_accounts', ['id' => $account->id]);
    }

    public function test_user_cannot_disconnect_another_users_account(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $account = StreamingAccount::factory()->for($owner)->youtube()->create();

        $this->actingAs($otherUser)
            ->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes'])
            ->assertNotFound();

        $this->assertDatabaseHas('streaming_accounts', ['id' => $account->id]);
    }

    public function test_disconnect_requires_an_explicit_confirmation(): void
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create();

        $this->actingAs($user)
            ->from(route('integrations.index'))
            ->delete(route('integrations.destroy', $account))
            ->assertRedirect(route('integrations.index'))
            ->assertSessionHasErrors('confirm_disconnect');

        $this->assertDatabaseHas('streaming_accounts', ['id' => $account->id]);
    }

    public function test_disconnect_route_requires_a_valid_csrf_token(): void
    {
        $user = User::factory()->create();
        $account = StreamingAccount::factory()->for($user)->spotify()->create();
        $middleware = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $this->app->instance(PreventRequestForgery::class, $middleware);

        $this->actingAs($user)
            ->delete(route('integrations.destroy', $account), ['confirm_disconnect' => 'yes'])
            ->assertStatus(419);

        $this->assertDatabaseHas('streaming_accounts', ['id' => $account->id]);
    }

    private function route(string $name): Route
    {
        $route = app('router')->getRoutes()->getByName($name);

        $this->assertInstanceOf(Route::class, $route);

        return $route;
    }
}
