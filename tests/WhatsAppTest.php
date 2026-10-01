<?php

namespace DevClick\WhatsApp\Tests;

use DevClick\WhatsApp\Facades\WhatsApp;
use DevClick\WhatsApp\WhatsAppException;
use DevClick\WhatsApp\WhatsAppServiceProvider;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class WhatsAppTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [WhatsAppServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('whatsapp.node_url', 'https://node.test');
        $app['config']->set('whatsapp.node_token', 'test-secret');
        $app['config']->set('whatsapp.app_id', 'first-app');
        $app['config']->set('whatsapp.send_route_enabled', true);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('session.driver', 'array');
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_guest_cannot_access_any_session_endpoint(): void
    {
        foreach (['status', 'qr'] as $action) {
            $this->getJson('/whatsapp/'.$action)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED')->assertHeader('Cache-Control', 'no-store, private');
        }
        foreach (['connect', 'disconnect', 'send-message'] as $action) {
            $this->postJson('/whatsapp/'.$action)->assertUnauthorized();
        }
        Http::assertNothingSent();
    }

    public function test_routes_use_logged_in_identity_and_reject_overrides(): void
    {
        $this->actingAs(new GenericUser(['id' => 'own/user ?']));
        Http::fake(['https://node.test/api/whatsapp/connect' => Http::response(['success' => true, 'status' => 'connecting', 'phone' => null])]);
        $this->postJson('/whatsapp/connect')->assertOk()->assertExactJson(['success' => true, 'status' => 'connecting', 'phone' => null])->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/whatsapp/connect', ['user_id' => 'other'])->assertUnprocessable()->assertJsonPath('code', 'INVALID_INPUT');
        Http::assertSent(fn (Request $request) => $request['user_id'] === 'own/user ?' && $request->hasHeader('Authorization', 'Bearer test-secret') && $request->hasHeader('X-WhatsApp-App-Id', 'first-app'));
        Http::assertSentCount(1);
    }

    public function test_application_and_user_identities_stay_separate(): void
    {
        Http::fake(['https://node.test/api/whatsapp/*/status' => Http::response(['success' => true, 'status' => 'disconnected', 'phone' => null])]);
        WhatsApp::forUserId('same/id')->status();
        config(['whatsapp.app_id' => 'second-app', 'whatsapp.node_token' => 'second-secret']);
        WhatsApp::forUserId('same/id')->status();
        WhatsApp::forUserId('other')->status();
        Http::assertSent(fn (Request $r) => $r->url() === 'https://node.test/api/whatsapp/same%2Fid/status' && $r->hasHeader('X-WhatsApp-App-Id', 'first-app') && $r->hasHeader('Authorization', 'Bearer test-secret'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://node.test/api/whatsapp/same%2Fid/status' && $r->hasHeader('X-WhatsApp-App-Id', 'second-app') && $r->hasHeader('Authorization', 'Bearer second-secret'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://node.test/api/whatsapp/other/status');
        Http::assertSentCount(3);
    }

    public function test_dot_identifiers_cannot_be_normalized_into_other_paths(): void
    {
        Http::fake(['https://node.test/api/whatsapp/%2E%2E/status' => Http::response(['success' => true, 'status' => 'disconnected', 'phone' => null])]);
        WhatsApp::forUserId('..')->status();
        Http::assertSent(fn (Request $request) => $request->url() === 'https://node.test/api/whatsapp/%2E%2E/status');
        Http::assertSentCount(1);
    }

    public function test_text_submission_returns_only_safe_acceptance_fields(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/7/send-message' => Http::response(['success' => true, 'message_id' => 'accepted-id', 'credentials' => 'private'])]);
        $this->postJson('/whatsapp/send-message', ['phone' => '919876543210', 'message' => 'Bill summary'])->assertExactJson(['success' => true, 'message_id' => 'accepted-id']);
        Http::assertSent(fn (Request $r) => $r['phone'] === '919876543210' && $r['message'] === 'Bill summary');
        Http::assertSentCount(1);
    }

    public function test_client_completion_without_id_requires_explicit_marker(): void
    {
        Http::fake(['https://node.test/api/whatsapp/7/send-message' => Http::sequence()->push(['success' => true, 'message_id' => null, 'confirmation' => 'client_completed'])->push(['success' => true, 'message_id' => null])]);
        $this->assertSame(['success' => true, 'message_id' => null, 'confirmation' => 'client_completed'], WhatsApp::forUserId('7')->sendText('919876543210', 'Hello'));
        try {
            WhatsApp::forUserId('7')->sendText('919876543210', 'Hello');
            $this->fail('An unconfirmed send must not become successful.');
        } catch (WhatsAppException $e) {
            $this->assertSame('INVALID_RESPONSE', $e->errorCode);
        }
        Http::assertSentCount(2);
    }

    public function test_invalid_text_phone_or_identity_cannot_submit(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        foreach ([['phone' => '+919876543210'], ['phone' => '01234567'], ['message' => '   '], ['message' => "\u{00A0}\u{FEFF}"], ['message' => str_repeat('🙂', 4097)], ['image' => 'attachment'], ['app_id' => 'other'], ['user_id' => 'other']] as $index => $invalid) {
            $this->actingAs(new GenericUser(['id' => 'validation-'.$index]));
            $this->postJson('/whatsapp/send-message', array_replace(['phone' => '919876543210', 'message' => 'Hello'], $invalid))->assertUnprocessable()->assertJsonPath('code', 'INVALID_INPUT');
        }
        Http::assertNothingSent();
    }

    public function test_helper_also_validates_text_and_user_id(): void
    {
        foreach (['', str_repeat('x', 257), "\xff"] as $userId) {
            try {
                WhatsApp::forUserId($userId);
                $this->fail('Invalid user ID accepted.');
            } catch (WhatsAppException $e) {
                $this->assertSame('INVALID_INPUT', $e->errorCode);
            }
        }
        try {
            WhatsApp::forUserId('7')->sendText('123', 'Hello');
            $this->fail('Invalid phone accepted.');
        } catch (WhatsAppException $e) {
            $this->assertSame(422, $e->httpStatus);
        }
        Http::assertNothingSent();
    }

    public function test_uncertain_failure_is_safe_and_never_automatically_retried(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/7/send-message' => Http::response(['success' => false, 'code' => 'SEND_FAILED', 'message' => 'secret upstream content'], 502)]);
        $this->postJson('/whatsapp/send-message', ['phone' => '919876543210', 'message' => 'Hello'])->assertStatus(502)->assertJsonPath('code', 'SEND_FAILED')->assertDontSee('secret upstream content')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSentCount(1);
    }

    public function test_connection_failure_is_unknown_send_outcome_and_no_retry(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/7/send-message' => Http::failedConnection()]);
        $this->postJson('/whatsapp/send-message', ['phone' => '919876543210', 'message' => 'Hello'])->assertStatus(503)->assertJsonPath('code', 'UNAVAILABLE')->assertJsonPath('message', 'Message submission outcome is unknown. Check before resubmitting; do not automatically retry.');
    }

    public function test_local_send_timeout_never_retries_or_leaks_exception_details(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        $attempts = 0;
        Http::fake(['https://node.test/api/whatsapp/7/send-message' => function () use (&$attempts): never {
            $attempts++;
            throw new ConnectionException('cURL error 28 private request contents');
        }]);
        $this->postJson('/whatsapp/send-message', ['phone' => '919876543210', 'message' => 'Hello'])->assertStatus(504)->assertJsonPath('code', 'TIMEOUT')->assertDontSee('private request contents');
        $this->assertSame(1, $attempts);
    }

    public function test_qr_expiry_is_enforced_and_payload_extras_are_removed(): void
    {
        $this->freezeTime();
        $this->actingAs(new GenericUser(['id' => '7']));
        $qr = 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR1EAAAAASUVORK5CYII=', true));
        $payload = ['success' => true, 'status' => 'qr_required', 'phone' => null, 'qr' => $qr, 'expires_at' => now()->addSeconds(20)->toISOString(), 'credentials' => 'private'];
        Http::fake(['https://node.test/api/whatsapp/7/qr' => Http::sequence()->push($payload)->push(array_replace($payload, ['expires_at' => now()->subSecond()->toISOString()]))]);
        $this->getJson('/whatsapp/qr')->assertOk()->assertJsonPath('qr', $qr)->assertJsonMissingPath('credentials')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson('/whatsapp/qr')->assertConflict()->assertJsonPath('code', 'QR_EXPIRED')->assertJsonMissingPath('qr');
        Http::assertSentCount(2);
    }

    public function test_truthful_phone_is_required_and_malformed_responses_are_rejected(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/7/status' => Http::sequence()->push(['success' => true, 'status' => 'connected', 'phone' => null])->push(['success' => true, 'status' => 'connecting', 'phone' => '919876543210'])->push('<html>private error</html>', 502)]);
        $this->getJson('/whatsapp/status')->assertStatus(502)->assertJsonPath('code', 'INVALID_RESPONSE');
        $this->getJson('/whatsapp/status')->assertStatus(502)->assertJsonPath('code', 'INVALID_RESPONSE');
        $this->getJson('/whatsapp/status')->assertStatus(503)->assertDontSee('private error');
        Http::assertSentCount(3);
    }

    public function test_disconnect_is_forwarded_once_per_explicit_request(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/7/disconnect' => Http::response(['success' => true, 'status' => 'disconnected', 'phone' => null])]);
        $this->postJson('/whatsapp/disconnect')->assertOk()->assertJsonPath('status', 'disconnected');
        $this->postJson('/whatsapp/disconnect')->assertOk()->assertJsonPath('status', 'disconnected');
        Http::assertSentCount(2);
    }

    public function test_connection_actions_are_rate_limited(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/connect' => Http::response(['success' => true, 'status' => 'connecting', 'phone' => null])]);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/whatsapp/connect')->assertOk();
        }
        $this->postJson('/whatsapp/connect')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSentCount(5);
    }

    public function test_csrf_is_required_outside_the_test_environment(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        $this->app->detectEnvironment(fn () => 'local');
        $this->postJson('/whatsapp/connect')->assertStatus(419)->assertJsonPath('code', 'REQUEST_REJECTED')->assertHeader('Cache-Control', 'no-store, private');
        Http::assertNothingSent();
    }

    public function test_upstream_credential_rejection_is_not_exposed(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/7/status' => Http::response(['success' => false, 'code' => 'FORBIDDEN', 'message' => 'private registry details'], 403)]);
        $this->getJson('/whatsapp/status')->assertStatus(502)->assertJsonPath('code', 'INVALID_RESPONSE')->assertDontSee('private registry details');
        Http::assertSentCount(1);
    }

    public function test_malformed_qr_and_redirect_responses_are_rejected(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        Http::fake(['https://node.test/api/whatsapp/7/qr' => Http::response(['success' => true, 'status' => 'qr_required', 'phone' => null, 'qr' => 'data:text/html;base64,PHNjcmlwdD4=', 'expires_at' => now()->addMinute()->toISOString()])]);
        $this->getJson('/whatsapp/qr')->assertStatus(502)->assertJsonPath('code', 'INVALID_RESPONSE')->assertJsonMissingPath('qr');
        Http::assertSentCount(1);
        Http::fake(['https://node.test/api/whatsapp/7/status' => Http::response(['success' => true, 'status' => 'connected', 'phone' => '919876543210'], 302, ['Location' => 'https://other.test'])]);
        $this->getJson('/whatsapp/status')->assertStatus(502)->assertJsonPath('code', 'INVALID_RESPONSE');
        Http::assertSentCount(1);
    }

    public function test_bad_configuration_cannot_expose_credentials_or_send_requests(): void
    {
        $this->actingAs(new GenericUser(['id' => '7']));
        config(['whatsapp.node_url' => 'https://untrusted.test?token=secret']);
        $this->getJson('/whatsapp/status')->assertStatus(503)->assertJsonPath('code', 'CONFIGURATION_ERROR')->assertDontSee('secret');
        config(['whatsapp.node_url' => 'http://node.test', 'whatsapp.require_https' => true]);
        $this->getJson('/whatsapp/status')->assertStatus(503);
        Http::assertNothingSent();
    }
}
