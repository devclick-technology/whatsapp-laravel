# Laravel WhatsApp (text only)

A small Laravel package for the shared Node WhatsApp service. Install the package, configure your application's credentials, and use the helper from your own controller. Connection routes register automatically. No migrations, Laravel session table, message history, attachments, webhooks, delivery tracking, or frontend framework are required.

**Requires PHP 8.3+, Laravel 12 or 13, and a running compatible Node WhatsApp service.** This package is an HTTP adapter; it does not run WhatsApp or Chromium inside PHP. The Node service owns WhatsApp authentication and persistent sessions. Its WhatsApp Web client is unofficial and can be affected by upstream changes.

## 1. Install

The repository is installable directly through Composer; a Packagist listing is not required. Add this repository to your application's existing `composer.json` (preserve any existing repositories):

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/devclick-technology/whatsapp-laravel"
        }
    ]
}
```

Alternatively, register the repository from the command line:

```bash
composer config repositories.devclick-whatsapp vcs https://github.com/devclick-technology/whatsapp-laravel
```

Then:

```bash
composer require devclick/whatsapp-laravel:^1.0
```

If the repository is private, configure Composer's GitHub authentication privately. Never commit an access token. Laravel discovers the provider automatically.

## 2. Provision an application credential in Node

In your existing Node service directory:

```bash
npm run provision -- your-app
```

Read `data/credentials/your-app.token` privately and put its value in Laravel's server secrets. The Node registry stores the token digest. After provisioning, gracefully restart the **single** Node instance to load the registry. Keep its persistent storage intact; never remove an active storage lock.

Each independent Laravel application must use a different stable application ID and credential. These are application credentials, not individual WhatsApp accounts. Tokens have no automatic expiry in the existing service; revoke/rotate them in Node when necessary. Reusing an application ID across independent projects can merge matching user IDs and must be avoided.

## 3. Configure Laravel

For local development on the same machine:

```dotenv
WHATSAPP_NODE_URL=http://127.0.0.1:3001
WHATSAPP_APP_ID=your-app
WHATSAPP_NODE_TOKEN=your-private-provisioned-token
WHATSAPP_NODE_TIMEOUT=40
WHATSAPP_REQUIRE_HTTPS=false
```

For production, use a reachable HTTPS Node URL and keep `WHATSAPP_REQUIRE_HTTPS=true` (the default). Loopback refers to the Laravel server itself. Separate servers need a reachable service hostname; containers need the correct network/service hostname. A URL alone cannot start Node: run it continuously under a supervisor or container with persistent storage.

```bash
php artisan config:clear
```

Optional configuration publishing:

```bash
php artisan vendor:publish --tag=whatsapp-config
```

There are no database migrations. In production deploy configuration changes with your application's normal `config:cache` and worker-reload procedure.

## 4. Connect the logged-in user's WhatsApp

The package registers these JSON routes automatically:

| Method | Laravel path | Route name | Body |
| --- | --- | --- | --- |
| POST | `/whatsapp/connect` | `whatsapp.connect` | `{}` |
| GET | `/whatsapp/status` | `whatsapp.status` | none |
| GET | `/whatsapp/qr` | `whatsapp.qr` | none |
| POST | `/whatsapp/disconnect` | `whatsapp.disconnect` | `{}` |

Default middleware is `web` and `auth`: sessions, CSRF protection, authentication, and rate limits apply. User/application identity comes from Laravel authentication and server configuration. Browser-supplied identity overrides are rejected. Requests should send `Accept: application/json`; mutations must include your application's CSRF token. Routes return `Cache-Control: no-store`.

Your existing Blade, Vue, React, or Inertia page can call these named routes using its normal HTTP client. Use Wayfinder-generated route functions if your application uses Wayfinder. No package-specific UI installation is necessary.

Connection flow:

1. On **Connect** click, POST `whatsapp.connect`. `connecting` means startup is in progress, not that a QR is ready.
2. Poll `whatsapp.status` quietly every 5 seconds while the connection page is visible. Avoid toggling the whole page into a loading state on each poll.
3. On **Show QR / Refresh QR** click, GET `whatsapp.qr`. If Node is still starting, keep checking status and let the user request the QR when `qr_required` appears. Do not fetch QR automatically in background polling.
4. Display the returned PNG data URI only to that authenticated user. Hide it at `expires_at`, on connection, on errors, or when leaving the page. `QR_EXPIRED` calls for an explicit refresh, not an automatic reconnection loop.
5. Once status is `connected`, text sending is available. A `phone` field reports the actual linked account. QR scanning and browser readiness may take time.
6. **Disconnect** explicitly logs out and deletes the persisted Node session. Do not call it when a component unmounts or a user merely closes the page.

Typical status:

```json
{"success":true,"status":"connected","phone":"919876543210"}
```

Supported statuses: `disconnected`, `connecting`, `qr_required`, `connected`. Phone is null outside `connected`. Missing sessions are successfully `disconnected`. QR responses also include `qr` and `expires_at`, both null when scanning is not required.

## 5. Send text from your application

```php
use DevClick\WhatsApp\Facades\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

public function send(Request $request): JsonResponse
{
    // Authorize the bill/invoice here and read its current recipient server-side.
    $result = WhatsApp::forUser($request->user())->sendText(
        phone: '919876543210',
        message: 'Bill INV-123\nTotal: INR 1,250.00',
    );

    return response()->json($result)->header('Cache-Control', 'no-store');
}
```

Put that action behind your normal authentication, authorization, CSRF protection, and rate limits. Keep domain-specific recipient lookup and bill formatting in your application. Do not accept an arbitrary sender user ID from a browser.

Phone must contain **7–15 international digits**, begin with 1–9, and include its country code; do not include `+`, spaces, or punctuation. Text must be nonblank and at most **4,096 Unicode characters**. The package does not truncate or split long text. PDF/image sending is not supported.

Other helper methods:

```php
$session = WhatsApp::forUser($request->user());
$session->connect();
$session->status();
$session->qr();
$session->disconnect();
```

For trusted server-side jobs or UUID/custom identifiers:

```php
WhatsApp::forUserId((string) $trustedUserId)->sendText($phone, $message);
```

User IDs are opaque UTF-8 strings, at most 256 bytes, encoded safely as URL path segments. Node namespaces every session by `(application ID, user ID)`. Identical user IDs across different application IDs remain isolated. The helper object keeps the selected user's ID; the package does not hold a global mutable current user.

### Optional generic text route

The browser text-send route is **disabled by default**. Prefer your own authorized business controller. To allow any signed-in user to submit text to a chosen recipient, explicitly set this in published `config/whatsapp.php`:

```php
'send_route_enabled' => true,
```

This adds POST `/whatsapp/send-message`, named `whatsapp.send-message`, accepting:

```json
{"phone":"919876543210","message":"Hello"}
```

It sends through the logged-in user's account and is limited to 5 requests/minute/user. Add your active-account, license, role, or permission middleware to the package's `middleware` configuration as needed.

## Send results and errors

Normal success:

```json
{"success":true,"message_id":"accepted-reference"}
```

The current Node service may fulfill its send operation without returning a message reference. The package accepts this only with Node's explicit completion marker:

```json
{"success":true,"message_id":null,"confirmation":"client_completed"}
```

That second result means **the client completed without an error**; it does not independently verify a WhatsApp message record. Neither success response confirms recipient delivery or reading. Disable duplicate clicks after submission.

Errors return safe JSON and throw `DevClick\WhatsApp\WhatsAppException` from helpers:

```json
{"success":false,"code":"SEND_FAILED","message":"Submission could not be confirmed. Check before resubmitting; do not automatically retry."}
```

| HTTP | Code | Action |
| --- | --- | --- |
| 409 | `SESSION_DISCONNECTED` | Connect again |
| 409 | `NOT_CONNECTED` | Wait for actual readiness |
| 409 | `QR_EXPIRED` | Explicitly refresh the QR |
| 409 | `SEND_IN_PROGRESS` | Check the previous submission; do not resubmit |
| 422 | `INVALID_INPUT` | Correct fields/phone/text |
| 429 | `RATE_LIMITED` | Wait |
| 502 | `SEND_FAILED`, `INVALID_RESPONSE` | Send outcome may be unknown; check WhatsApp |
| 503/504 | `UNAVAILABLE`, `TIMEOUT` | For sends, outcome may be unknown; check WhatsApp |
| 503 | `CONFIGURATION_ERROR` | Administrator must fix configuration |
| 401 | `UNAUTHENTICATED` | Sign in |

Upstream 401/403 indicates an application credential problem; the package returns a safe `INVALID_RESPONSE` rather than exposing the Node payload. It never forwards raw library exceptions or credentials.

**Never automatically retry a send** in your HTTP client, queue job, reverse proxy, or UI. A timeout/failure can occur after WhatsApp has sent the message. If using a queue, set a single attempt and avoid redispatching uncertain jobs. Configure PHP/worker/proxy timeouts to exceed the package timeout (default 40 seconds; Node submission is bounded to 30 seconds plus readiness).

## Custom routes and integration with existing applications

Publish configuration to change route prefix/name or middleware, or disable automatic routes entirely:

```php
'routes_enabled' => false,
'route_prefix' => 'account/whatsapp',
'route_name_prefix' => 'account.whatsapp.',
'middleware' => ['web', 'auth'],
```

With routes disabled, use the facade in your own controllers. Keep authentication and owner authorization; never replace middleware with an unauthenticated public QR page. After changing route settings, rebuild or clear your application route cache (`php artisan route:clear`). No browser receives the Node bearer token. Your application should set no-store on custom connection/send responses as well.

**Existing Transport integration:** it already defines `config/whatsapp.php` and `whatsapp.*` routes. Before adopting this package, remove/replace the old integration or disable package routes and resolve the shared configuration deliberately. Do not install it blindly alongside the old controllers and routes. This repository does not modify Transport or any other Laravel application.

## Operations and verification

- Run one Node session-owning instance with persistent, protected storage. Horizontal scaling requires session ownership coordination.
- Normal Node shutdown preserves linked accounts; explicit disconnect removes them. Status after restart is `connecting` until the client is actually ready.
- Keep app tokens and Node browser profiles out of Git, public storage, browser props, logs, and telemetry. Disable/redact request-body logging for these endpoints. The package itself logs no tokens, QR payloads, recipients, or message contents.
- Protect the Node network endpoint. HTTPS is required by default; disable it only intentionally for local/private networking.
- The package does not bundle Node. It expects its `/api/whatsapp/*` contract and application registry provisioning command as described above.

Manual two-application/two-user check:

1. Provision distinct app IDs/tokens and configure two Laravel applications against the same Node service.
2. In each application, sign in as two users (matching IDs across apps are useful for this check).
3. Connect each user and scan their own QR. Verify status reports the intended linked phone for each `(app, user)` pair.
4. Explicitly send one short text per user to a consenting test recipient. Verify the sender account matches that user's connection.
5. Disconnect one user; verify the other three remain connected.
6. Gracefully restart Node; verify saved accounts restore and status becomes connected after readiness, without falsely reporting connected during initialization.

## Development

```bash
composer install
composer test
composer check
composer audit
```

Tests use HTTP fakes and send no real WhatsApp messages. CI checks Laravel 12/13 on PHP 8.3/8.4. Tests cover authenticated ownership, app/user separation, opaque IDs, validation, QR expiry, safe responses, rate limits, uncertain sends, and default route configuration. Node's own tests remain responsible for browser lifecycle, storage, and restart restoration.

MIT license. See [LICENSE](LICENSE).
