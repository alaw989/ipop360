<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureUserHasRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureUserHasRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_allows_user_with_matching_role(): void
    {
        $user = User::factory()->editor()->create();
        $request = $this->requestFor($user);

        $response = (new EnsureUserHasRole)->handle($request, fn () => new Response('next'), 'admin', 'editor');

        $this->assertSame('next', $response->getContent());
    }

    public function test_denies_user_without_matching_role(): void
    {
        $user = User::factory()->user()->create();
        $request = $this->requestFor($user);

        $this->assertDenied($request, 'admin');
    }

    public function test_denies_guest(): void
    {
        $request = $this->requestFor(null);

        $this->assertDenied($request, 'admin', 'editor');
    }

    private function assertDenied(Request $request, string ...$roles): void
    {
        try {
            (new EnsureUserHasRole)->handle($request, fn () => new Response('next'), ...$roles);
            $this->fail('Expected a 403 HttpException.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function requestFor(?User $user): Request
    {
        $request = Request::create('/admin/blog');
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
