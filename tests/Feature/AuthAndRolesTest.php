<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuthAndRolesTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/workspace')->assertRedirect('/login');
    }

    public function test_same_tenant_owner_and_admin_can_access_dashboard_and_workspace_while_analyst_gets_403_on_manager_route(): void
    {
        $group = $this->makeTenantWithUsers('Dhaka Apparel');

        $this->actingAs($group['owner'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Dhaka Apparel');

        $this->actingAs($group['owner'])
            ->get('/workspace')
            ->assertOk();

        $this->actingAs($group['admin'])
            ->get('/workspace')
            ->assertOk();

        $this->actingAs($group['analyst'])
            ->get('/dashboard')
            ->assertOk();

        $this->actingAs($group['analyst'])
            ->get('/workspace')
            ->assertForbidden();
    }

    public function test_registration_creates_tenant_and_owner_and_ignores_injected_role_and_tenant_id(): void
    {
        $otherTenant = Tenant::create(['name' => 'Victim Corp', 'slug' => 'victim-corp']);

        $response = $this->post('/register', [
            'company_name' => 'New Fashion BD',
            'name' => 'Tariq Hasan',
            'email' => 'tariq@newfashion.test',
            'password' => 'SecretPass123',
            'password_confirmation' => 'SecretPass123',
            'role' => 'admin',
            'tenant_id' => $otherTenant->id,
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticated();

        $user = User::where('email', 'tariq@newfashion.test')->firstOrFail();
        $this->assertSame(User::ROLE_OWNER, $user->role);
        $this->assertNotSame($otherTenant->id, $user->tenant_id);
        $this->assertSame('New Fashion BD', $user->tenant->name);
    }

    public function test_weak_password_is_rejected_on_registration(): void
    {
        $this->post('/register', [
            'company_name' => 'Weak Pass Store',
            'name' => 'Owner',
            'email' => 'weak@example.test',
            'password' => 'short123',
            'password_confirmation' => 'short123',
        ])->assertSessionHasErrors('password');

        $this->post('/register', [
            'company_name' => 'No Digits Store',
            'name' => 'Owner',
            'email' => 'nodigits@example.test',
            'password' => 'OnlyLettersPassword',
            'password_confirmation' => 'OnlyLettersPassword',
        ])->assertSessionHasErrors('password');

        $this->post('/register', [
            'company_name' => 'No Letters Store',
            'name' => 'Owner',
            'email' => 'noletters@example.test',
            'password' => '12345678901',
            'password_confirmation' => '12345678901',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_login_regenerates_session_id_and_logout_invalidates_session(): void
    {
        $group = $this->makeTenantWithUsers('Session Co');
        $user = $group['owner'];
        $user->forceFill(['password' => Hash::make('ValidPass123')])->save();

        $this->get('/login')->assertOk();
        $oldSessionId = session()->getId();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'ValidPass123',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $newSessionId = session()->getId();
        $this->assertNotSame($oldSessionId, $newSessionId);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotSame($newSessionId, session()->getId());
    }

    public function test_invalid_login_returns_generic_error_and_is_throttled_after_six_attempts(): void
    {
        $group = $this->makeTenantWithUsers('Throttle Co');
        $user = $group['owner'];

        for ($i = 1; $i <= 6; $i++) {
            $resp = $this->post('/login', [
                'email' => $user->email,
                'password' => 'WrongPassword1',
            ]);
            $resp->assertStatus(302);
            $resp->assertSessionHasErrors('email');
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'WrongPassword1',
        ])->assertStatus(429);
    }

    public function test_security_headers_and_csrf_protection_are_enforced_on_web_routes(): void
    {
        $response = $this->get('/login');
        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->assertStringContainsString("default-src 'self'", (string) $response->headers->get('Content-Security-Policy'));

        $loginRoute = app('router')->getRoutes()->getByName('login.post');
        $resolvedMiddleware = app('router')->gatherRouteMiddleware($loginRoute);
        $this->assertContains(ValidateCsrfToken::class, $resolvedMiddleware);

        // Exercise ValidateCsrfToken directly with runningUnitTests disabled to prove TokenMismatchException fires.
        $strictCsrf = new class(app(), app('encrypter')) extends ValidateCsrfToken {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };

        $req = Request::create('/login', 'POST', ['email' => 'a@b.test', 'password' => 'x']);
        $session = app('session')->driver();
        $session->start();
        $req->setLaravelSession($session);

        $this->expectException(TokenMismatchException::class);
        $strictCsrf->handle($req, fn () => new Response('ok'));
    }
}
