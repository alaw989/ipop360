<?php

namespace Tests\Feature;

use App\Http\Middleware\LogApiRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * spec-103: LogApiRequest must not `json_decode` every JSON response just to
 * read `is_live` — cache-warm live-search payloads are multi-MB, so decoding
 * them O(n) per request (plus a log line) is pure waste. The controller now
 * stamps the request attribute, which the middleware reads directly.
 *
 * The discriminating case: a response body that DECODES to `{"is_live": true}`
 * with no attribute set must still log false — proving the body is ignored.
 */
class LogApiRequestTest extends TestCase
{
    private function invoke(Request $request, string $body, int $status = 200): Response
    {
        return (new LogApiRequest)->handle(
            $request,
            fn () => new Response($body, $status, ['Content-Type' => 'application/json'])
        );
    }

    public function test_body_is_not_decoded_to_determine_is_live(): void
    {
        $log = Log::spy();

        $request = Request::create('/api/restaurants', 'GET');
        // No attribute set, yet the body claims live — the old middleware would
        // have logged true. Reading the body must NOT drive the flag.
        $this->invoke($request, '{"is_live":true}');

        $log->shouldHaveReceived('info')
            ->once()
            ->with('API Request', \Mockery::on(fn (array $ctx) => $ctx['is_live'] === false));
    }

    public function test_request_attribute_marks_live(): void
    {
        $log = Log::spy();

        $request = Request::create('/api/restaurants', 'GET');
        $request->attributes->set('is_live', true);
        // Body disagrees; the attribute must win.
        $this->invoke($request, '{"is_live":false}');

        $log->shouldHaveReceived('info')
            ->once()
            ->with('API Request', \Mockery::on(fn (array $ctx) => $ctx['is_live'] === true));
    }

    public function test_non_json_response_is_not_logged(): void
    {
        $log = Log::spy();

        $request = Request::create('/api/restaurants', 'GET');
        (new LogApiRequest)->handle(
            $request,
            fn () => new Response('not json', 200, ['Content-Type' => 'text/html'])
        );

        $log->shouldNotHaveReceived('info');
    }
}
