<?php

declare(strict_types=1);

use App\Http\Controllers\Webhooks\DocumensoWebhookController;
use App\Inventories\Versions\SignatureStatus;
use App\Jobs\SyncSignature;
use App\Signatures\SignatureSync;
use App\Support\PublicUrls;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/../../Support/versions.php';

/*
 * `POST /webhooks/documenso`: Documenso saying that something happened to an envelope. It proves who it is with a secret in a header, is
 * believed about nothing, and only wakes up a synchronisation, which reads the envelope again through the API.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
    versionDocumenso();
});

/**
 * Calls the webhook the way Documenso does: a JSON body and the secret in its header.
 *
 * @param  array<string, mixed>|string  $body  The event, or the raw body.
 * @param  array<string, string>|null  $headers  The headers; the right secret when null.
 */
function webhookCall(object $test, array|string $body, ?array $headers = null): TestResponse
{
    $headers ??= ['X-Documenso-Secret' => 'secret-test'];
    $server = [];

    foreach ($headers + ['Content-Type' => 'application/json', 'Accept' => '*/*'] as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    $server['CONTENT_TYPE'] = 'application/json';

    return $test->call('POST', '/webhooks/documenso', [], [], [], $server, is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
}

/**
 * An event as Documenso sends it.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function webhookEvent(string $event, array $payload): array
{
    return ['event' => $event, 'payload' => $payload + ['id' => 7, 'status' => 'COMPLETED', 'recipients' => []], 'createdAt' => '2026-10-15T12:30:00.000Z', 'webhookEndpoint' => 'https://app.example.test/webhooks/documenso'];
}

/**
 * The versions the jobs of the queue are about.
 *
 * @return list<int>
 */
function webhookQueued(): array
{
    return Queue::pushed(SyncSignature::class)->map(fn (SyncSignature $job): int => $job->versionId)->values()->all();
}

/**
 * The envelopes the jobs of the queue were handed to look at, by version: what a webhook announced for a request that had none.
 *
 * @return list<string|null>
 */
function webhookCandidates(): array
{
    return Queue::pushed(SyncSignature::class)->map(fn (SyncSignature $job): ?string => $job->envelopeId)->values()->all();
}

describe('who may call it', function (): void {
    it('does not exist while the instance has no secret', function (mixed $secret): void {
        config(['services.documenso.webhook_secret' => $secret]);

        $response = webhookCall($this, webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']), ['X-Documenso-Secret' => 'secret-test']);

        $response->assertNotFound()->assertJsonPath('message', 'Not found.');
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        Queue::assertNothingPushed();
    })->with([[null], [''], ['   ']]);

    it('refuses a call without the secret, or with another one: 401 in JSON', function (?array $headers): void {
        $version = versionAwaiting(1)['version'];

        $response = webhookCall($this, webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => $version->documenso_envelope_id]), $headers);

        $response->assertUnauthorized()->assertJsonPath('message', 'The secret is missing or wrong.')->assertJsonMissingPath('received');
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        Queue::assertNotPushed(SyncSignature::class);
    })->with([
        'no header' => [[]],
        'an empty secret' => [['X-Documenso-Secret' => '']],
        'another secret' => [['X-Documenso-Secret' => 'secret-other']],
        'the start of the right one' => [['X-Documenso-Secret' => 'secret-tes']],
        'the right one and more' => [['X-Documenso-Secret' => 'secret-test-and-more']],
        'the right one in the wrong case' => [['X-Documenso-Secret' => 'SECRET-TEST']],
        'the right one in the wrong header' => [['Authorization' => 'secret-test']],
    ]);

    it('compares the secrets in constant time', function (): void {
        $source = (string) file_get_contents((new ReflectionClass(DocumensoWebhookController::class))->getFileName());

        expect($source)->toContain('hash_equals(Documenso::webhookSecret(), $secret)')->not->toMatch('/\$secret\s*[!=]==?\s*Documenso::webhookSecret/');
    });

    it('accepts the secret the administrator typed, spaces around it aside', function (): void {
        config(['services.documenso.webhook_secret' => '  secret-test  ']);

        webhookCall($this, webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']))->assertOk();
    });

    it('is a POST: nothing else is answered', function (): void {
        $this->getJson('/webhooks/documenso')->assertStatus(405);
    });
});

describe('what it says', function (): void {
    it('says it in the language the call asks for, in French by default of the instance', function (): void {
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']);
        $large = $event + ['padding' => str_repeat('a', DocumensoWebhookController::MAX_BYTES)];

        webhookCall($this, $event, ['X-Documenso-Secret' => 'nope', 'Accept-Language' => 'fr'])->assertUnauthorized()->assertJsonPath('message', 'Le secret est absent ou erroné.');
        webhookCall($this, $large, ['X-Documenso-Secret' => 'secret-test', 'Accept-Language' => 'fr'])->assertStatus(413)->assertJsonPath('message', 'Le corps de la requête est trop volumineux.');

        config(['services.documenso.webhook_secret' => null]);

        webhookCall($this, $event, ['X-Documenso-Secret' => 'secret-test', 'Accept-Language' => 'fr'])->assertNotFound()->assertJsonPath('message', 'Introuvable.');
        webhookCall($this, $event, ['X-Documenso-Secret' => 'secret-test', 'Accept-Language' => 'en'])->assertNotFound()->assertJsonPath('message', 'Not found.');
    });

    it('asks the translator for every message it answers with', function (): void {
        $source = (string) file_get_contents((new ReflectionClass(DocumensoWebhookController::class))->getFileName());

        expect($source)->toContain("__('Not found.')")->toContain("__('The secret is missing or wrong.')")->toContain("__('The body is too large.')");
    });
});

describe('the size of the call', function (): void {
    it('refuses a body larger than a megabyte, with a 413 in JSON, once the secret is proven', function (): void {
        $version = versionAwaiting(1)['version'];
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => $version->documenso_envelope_id]);
        $event['padding'] = str_repeat('a', DocumensoWebhookController::MAX_BYTES);

        $response = webhookCall($this, $event);

        $response->assertStatus(413)->assertJsonPath('message', 'The body is too large.');
        Queue::assertNotPushed(SyncSignature::class);
    });

    it('refuses on the length it is announced with, without reading the body', function (): void {
        $response = $this->call('POST', '/webhooks/documenso', [], [], [], [
            'HTTP_X_DOCUMENSO_SECRET' => 'secret-test', 'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) (DocumensoWebhookController::MAX_BYTES + 1),
        ], '{}');

        $response->assertStatus(413);
    });

    it('answers 401 before 413: a stranger learns nothing of the limits', function (): void {
        webhookCall($this, str_repeat('a', DocumensoWebhookController::MAX_BYTES + 10), ['X-Documenso-Secret' => 'nope'])->assertUnauthorized();
    });

    it('reads a body of exactly a megabyte', function (): void {
        $version = versionAwaiting(1)['version'];
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => $version->documenso_envelope_id]);
        $event['padding'] = '';
        $event['padding'] = str_repeat('a', DocumensoWebhookController::MAX_BYTES - strlen(json_encode($event, JSON_THROW_ON_ERROR)));
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        expect(strlen($body))->toBe(DocumensoWebhookController::MAX_BYTES);

        webhookCall($this, $body)->assertOk();
        expect(webhookQueued())->toBe([$version->id]);
    });
});

describe('what it does with an event', function (): void {
    it('queues the synchronisation of the version whose envelope it is about, for every event about a document', function (string $event): void {
        $version = versionAwaiting(1)['version'];

        $response = webhookCall($this, webhookEvent($event, ['envelopeId' => $version->documenso_envelope_id, 'externalId' => $version->ulid]));

        $response->assertOk()->assertExactJson(['received' => true]);
        expect(webhookQueued())->toBe([$version->id]);
    })->with([
        'DOCUMENT_CREATED', 'DOCUMENT_SENT', 'DOCUMENT_OPENED', 'DOCUMENT_SIGNED', 'DOCUMENT_RECIPIENT_COMPLETED',
        'DOCUMENT_COMPLETED', 'DOCUMENT_REJECTED', 'DOCUMENT_CANCELLED', 'DOCUMENT_REMINDER_SENT',
    ]);

    it('does nothing for the events that are not about a document', function (string $event): void {
        $version = versionAwaiting(1)['version'];

        webhookCall($this, webhookEvent($event, ['envelopeId' => $version->documenso_envelope_id]))->assertOk()->assertExactJson(['received' => true]);

        Queue::assertNotPushed(SyncSignature::class);
    })->with(['TEMPLATE_CREATED', 'TEMPLATE_UPDATED', 'TEMPLATE_DELETED', 'TEMPLATE_USED', 'RECIPIENT_EXPIRED', 'DOCUMENT_SOMETHING_NEW', '', 'document_completed']);

    it('answers 200 all the same for an envelope nobody knows, and for what it cannot read', function (mixed $body): void {
        versionAwaiting(1);

        $response = webhookCall($this, $body);

        $response->assertOk()->assertExactJson(['received' => true]);
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        Queue::assertNotPushed(SyncSignature::class);
    })->with([
        'an envelope nobody knows' => [webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_nobody'])],
        'no payload' => [['event' => 'DOCUMENT_COMPLETED']],
        'a payload that is not an object' => [['event' => 'DOCUMENT_COMPLETED', 'payload' => 'envelope_test1']],
        'an event that is not text' => [['event' => ['DOCUMENT_COMPLETED'], 'payload' => ['envelopeId' => 'envelope_test1']]],
        'an id that is not text' => [['event' => 'DOCUMENT_COMPLETED', 'payload' => ['envelopeId' => 12]]],
        'an id of another shape' => [['event' => 'DOCUMENT_COMPLETED', 'payload' => ['envelopeId' => "envelope_test1'; drop table inventory_versions; --"]]],
        'an id too long' => [['event' => 'DOCUMENT_COMPLETED', 'payload' => ['envelopeId' => str_repeat('a', 65)]]],
        'the legacy number of the document only' => [['event' => 'DOCUMENT_COMPLETED', 'payload' => ['id' => 7]]],
        'a body that is not JSON' => ['<xml>no</xml>'],
        'a body that is a number' => ['12'],
        'a body that is a string' => ['"DOCUMENT_COMPLETED"'],
        'an empty body' => [''],
    ]);

    it('finds a request that is being sent by our own identifier, and hands the envelope it announces to the job: it records nothing itself', function (): void {
        $made = versionAwaiting(1);
        $version = $made['version'];
        $version->forceFill(['signature_status' => SignatureStatus::Sending, 'documenso_envelope_id' => null])->save();

        webhookCall($this, webhookEvent('DOCUMENT_CREATED', ['envelopeId' => 'envelope_test1', 'externalId' => strtoupper($version->ulid)]))->assertOk();

        expect($version->fresh()->documenso_envelope_id)->toBeNull()
            ->and(webhookQueued())->toBe([$version->id])
            ->and(webhookCandidates())->toBe(['envelope_test1']);
        // Not a call to Documenso either: the job reads the envelope, the webhook is fast.
        expect($made['fake']->count('GET /envelope/{id}'))->toBe(1);
    });

    it('records the id of the envelope it announced only once the job has read it and found our identifier on it', function (): void {
        $made = versionAwaiting(1);
        $version = $made['version'];
        $version->forceFill(['signature_status' => SignatureStatus::Sending, 'documenso_envelope_id' => null])->save();
        $made['fake']->status = 'PENDING';

        webhookCall($this, webhookEvent('DOCUMENT_CREATED', ['envelopeId' => 'envelope_test1', 'externalId' => $version->ulid]))->assertOk();
        Queue::pushed(SyncSignature::class)->each(fn (SyncSignature $job) => $job->handle(app(SignatureSync::class)));

        expect($version->fresh()->documenso_envelope_id)->toBe('envelope_test1')
            ->and($version->fresh()->signature_status)->toBe(SignatureStatus::Awaiting);
    });

    it('records nothing for an envelope that is not ours, or says nothing of whose it is: the call is not believed', function (?string $externalId): void {
        $made = versionAwaiting(1);
        $version = $made['version'];
        $version->forceFill(['signature_status' => SignatureStatus::Sending, 'documenso_envelope_id' => null])->save();
        // What the API says of the envelope the call announces: another document's, or none.
        $made['fake']->externalId = $externalId;

        webhookCall($this, webhookEvent('DOCUMENT_CREATED', ['envelopeId' => 'envelope_test1', 'externalId' => $version->ulid]))->assertOk();
        Queue::pushed(SyncSignature::class)->each(fn (SyncSignature $job) => $job->handle(app(SignatureSync::class)));

        expect($version->fresh()->documenso_envelope_id)->toBeNull()
            ->and($version->fresh()->signature_status)->toBe(SignatureStatus::Sending);
    })->with(['the identifier of another version' => ['01j00000000000000000000000'], 'no identifier' => [null], 'the identifier in another case' => ['SOMETHING']]);

    it('does not adopt an envelope for anything but a request that is being sent and has none', function (SignatureStatus $status, ?string $envelope): void {
        $version = versionAwaiting(1)['version'];
        $version->forceFill(['signature_status' => $status, 'documenso_envelope_id' => $envelope])->save();

        webhookCall($this, webhookEvent('DOCUMENT_CREATED', ['envelopeId' => 'envelope_forged', 'externalId' => $version->ulid]))->assertOk();

        expect($version->fresh()->documenso_envelope_id)->toBe($envelope);
        Queue::assertNotPushed(SyncSignature::class);
    })->with([
        'asked for' => [SignatureStatus::Requested, null],
        'awaiting' => [SignatureStatus::Awaiting, 'envelope_test1'],
        'being sent, with an envelope' => [SignatureStatus::Sending, 'envelope_test1'],
        'failed' => [SignatureStatus::Failed, null],
        'cancelled' => [SignatureStatus::Cancelled, null],
    ]);

    it('does not adopt an envelope without our identifier of it, nor with the identifier of nothing', function (?string $externalId): void {
        $version = versionAwaiting(1)['version'];
        $version->forceFill(['signature_status' => SignatureStatus::Sending, 'documenso_envelope_id' => null])->save();

        webhookCall($this, webhookEvent('DOCUMENT_CREATED', ['envelopeId' => 'envelope_forged'] + ($externalId === null ? [] : ['externalId' => $externalId])))->assertOk();

        expect($version->fresh()->documenso_envelope_id)->toBeNull();
        Queue::assertNotPushed(SyncSignature::class);
    })->with([[null], ['01j00000000000000000000000'], ['not-a-ulid'], [str_repeat('x', 26)]]);

    it('does not adopt an envelope without its id: the identifier alone finds nothing to follow', function (): void {
        $version = versionAwaiting(1)['version'];
        $version->forceFill(['signature_status' => SignatureStatus::Sending, 'documenso_envelope_id' => null])->save();

        webhookCall($this, webhookEvent('DOCUMENT_CREATED', ['externalId' => $version->ulid]))->assertOk();

        expect($version->fresh()->documenso_envelope_id)->toBeNull();
        Queue::assertNotPushed(SyncSignature::class);
    });

    it('leaves the id of the envelope alone when the request already has one, whatever the call says', function (): void {
        $version = versionAwaiting(1)['version'];

        webhookCall($this, webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_other', 'externalId' => $version->ulid]))->assertOk();

        expect($version->fresh()->documenso_envelope_id)->toBe('envelope_test1');
        Queue::assertNotPushed(SyncSignature::class);
    });

    it('costs one read for a burst of events about one envelope, and one for each other envelope', function (): void {
        $first = versionAwaiting(1);
        $second = versionRun(versionFreeze($first['case']['inventory'], $first['case']['team']['owner'], null, versionSigners(1)));
        $second->forceFill(['signature_status' => SignatureStatus::Awaiting, 'documenso_envelope_id' => 'envelope_other'])->save();

        foreach (['DOCUMENT_SENT', 'DOCUMENT_OPENED', 'DOCUMENT_SIGNED', 'DOCUMENT_RECIPIENT_COMPLETED', 'DOCUMENT_COMPLETED'] as $event) {
            webhookCall($this, webhookEvent($event, ['envelopeId' => 'envelope_test1']))->assertOk();
        }

        webhookCall($this, webhookEvent('DOCUMENT_SIGNED', ['envelopeId' => 'envelope_other']))->assertOk();

        expect(webhookQueued())->toBe([$first['version']->id, $second->id]);
    });

    it('is a call to a queue that is down, answered all the same, and written in the log', function (): void {
        Log::spy();
        $version = versionAwaiting(1)['version'];
        $this->mock(Dispatcher::class, fn ($mock) => $mock->shouldReceive('dispatch')->andThrow(new RuntimeException('The queue is down.')));

        webhookCall($this, webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => $version->documenso_envelope_id]))->assertOk()->assertExactJson(['received' => true]);

        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not be followed'))->once();
    });
});

describe('what Documenso says is never believed', function (): void {
    it('does not complete a request because the call says so: the envelope is read again', function (): void {
        $made = versionAwaiting(2);
        $version = $made['version'];
        // The call claims everything is done, signed and completed; the API says it is not.
        $event = webhookEvent('DOCUMENT_COMPLETED', [
            'envelopeId' => $version->documenso_envelope_id,
            'status' => 'COMPLETED',
            'recipients' => [['email' => 'signer1@example.test', 'signingStatus' => 'SIGNED'], ['email' => 'signer2@example.test', 'signingStatus' => 'SIGNED']],
        ]);

        webhookCall($this, $event)->assertOk();
        Queue::pushed(SyncSignature::class)->each(fn (SyncSignature $job) => $job->handle(app(SignatureSync::class)));
        $row = $version->fresh();

        expect($row->signature_status)->toBe(SignatureStatus::Awaiting)
            ->and(collect($row->signers)->pluck('status')->unique()->all())->toBe(['pending'])
            ->and($row->signed_pdf_path)->toBeNull()
            ->and($made['fake']->count('GET /envelope/{id}'))->toBeGreaterThan(0);
    });

    it('wakes up the read that completes the request when the API agrees', function (): void {
        $made = versionAwaiting(1);
        $version = $made['version'];
        $made['fake']->complete();

        webhookCall($this, webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => $version->documenso_envelope_id]))->assertOk();
        Queue::pushed(SyncSignature::class)->each(fn (SyncSignature $job) => $job->handle(app(SignatureSync::class)));

        expect($version->fresh()->signature_status)->toBe(SignatureStatus::Completed)
            ->and($version->fresh()->signed_pdf_sha256)->toBe(hash('sha256', $made['fake']->signedPdf));
    });
});

describe('the route', function (): void {
    it('is named, a POST only, without session nor CSRF check, and paced by the controller (no throttle middleware counts every call)', function (): void {
        $route = Route::getRoutes()->getByName('webhooks.documenso');

        expect($route)->not->toBeNull()
            ->and($route->uri())->toBe('webhooks/documenso')
            ->and($route->methods())->toBe(['POST'])
            ->and($route->excludedMiddleware())->toContain(StartSession::class, PreventRequestForgery::class)
            ->and(collect($route->gatherMiddleware())->filter(fn (mixed $middleware): bool => is_string($middleware) && str_starts_with($middleware, 'throttle')))->toHaveCount(0)
            ->and(route('webhooks.documenso', [], false))->toBe('/webhooks/documenso');
    });

    it('sets no cookie and starts no session', function (): void {
        $response = webhookCall($this, webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']));

        $response->assertOk();
        expect($response->headers->getCookies())->toBe([]);
    });

    it('is slowed down after 120 calls a minute from one address, in JSON, and not the other addresses', function (): void {
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']);

        for ($call = 1; $call <= 120; $call++) {
            webhookCall($this, $event, ['X-Documenso-Secret' => 'wrong'])->assertUnauthorized();
        }

        $slowed = webhookCall($this, $event);
        $other = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->call('POST', '/webhooks/documenso', [], [], [], ['HTTP_X_DOCUMENSO_SECRET' => 'secret-test', 'CONTENT_TYPE' => 'application/json'], json_encode($event));

        $slowed->assertStatus(429)->assertJsonStructure(['message', 'code']);
        expect($slowed->headers->get('Content-Type'))->toContain('application/json')
            ->and($slowed->headers->has('Retry-After'))->toBeTrue()
            ->and($other->status())->toBe(200);
    });

    it('counts only the calls refused with a 401: any number of calls that authenticate is answered', function (): void {
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']);

        for ($call = 1; $call <= 150; $call++) {
            webhookCall($this, $event)->assertOk();
        }

        // The 120 that fail are what it takes, though 150 went through before them.
        for ($call = 1; $call <= 119; $call++) {
            webhookCall($this, $event, ['X-Documenso-Secret' => 'wrong'])->assertUnauthorized();
        }

        webhookCall($this, $event)->assertOk();
        webhookCall($this, $event, ['X-Documenso-Secret' => 'wrong'])->assertUnauthorized();
        webhookCall($this, $event)->assertStatus(429);
    });

    it('looks at the count before it looks at the secret: an address that failed too often is not told whether its guess was right', function (): void {
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']);

        for ($call = 1; $call <= 120; $call++) {
            webhookCall($this, $event, ['X-Documenso-Secret' => 'wrong'])->assertUnauthorized();
        }

        // Neither a wrong secret nor the right one: the same refusal.
        $wrong = webhookCall($this, $event, ['X-Documenso-Secret' => 'wrong']);
        $right = webhookCall($this, $event);

        $wrong->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        $right->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        expect($right->headers->get('Retry-After'))->toBeNumeric()->and((int) $right->headers->get('Retry-After'))->toBeLessThanOrEqual(60)
            ->and($right->headers->get('X-RateLimit-Limit'))->toBe('120')
            ->and($right->headers->get('X-RateLimit-Remaining'))->toBe('0')
            ->and($right->headers->get('Content-Type'))->toContain('application/json');
        Queue::assertNotPushed(SyncSignature::class);
    });

    it('lets the address in again a minute after its last failure', function (): void {
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']);

        for ($call = 1; $call <= 120; $call++) {
            webhookCall($this, $event, ['X-Documenso-Secret' => 'wrong'])->assertUnauthorized();
        }

        webhookCall($this, $event)->assertStatus(429);
        $this->travel(61)->seconds();

        webhookCall($this, $event)->assertOk();
    });

    it('does not count what is not a failure to authenticate: no secret on the instance, a body that is too large', function (): void {
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']);
        $large = $event + ['padding' => str_repeat('a', DocumensoWebhookController::MAX_BYTES)];

        for ($call = 1; $call <= 130; $call++) {
            webhookCall($this, $large)->assertStatus(413);
        }

        config(['services.documenso.webhook_secret' => null]);

        for ($call = 1; $call <= 130; $call++) {
            webhookCall($this, $event)->assertNotFound();
        }

        config(['services.documenso.webhook_secret' => 'secret-test']);

        webhookCall($this, $event, ['X-Documenso-Secret' => 'wrong'])->assertUnauthorized();
        webhookCall($this, $event)->assertOk();
    });

    it('has a generous cap of its own for the calls that do authenticate, in JSON, for that address only', function (): void {
        $event = webhookEvent('DOCUMENT_COMPLETED', ['envelopeId' => 'envelope_x']);

        for ($call = 1; $call <= DocumensoWebhookController::CALLS_PER_MINUTE; $call++) {
            RateLimiter::hit('documenso-webhook-calls:127.0.0.1', 60);
        }

        $slowed = webhookCall($this, $event);
        $other = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->call('POST', '/webhooks/documenso', [], [], [], ['HTTP_X_DOCUMENSO_SECRET' => 'secret-test', 'CONTENT_TYPE' => 'application/json'], json_encode($event));

        $slowed->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
        expect($slowed->headers->has('Retry-After'))->toBeTrue()
            ->and($other->status())->toBe(200)
            ->and(DocumensoWebhookController::CALLS_PER_MINUTE)->toBeGreaterThanOrEqual(600);
    });

    it('is told to Documenso with an address built from APP_URL, never from the request', function (): void {
        config(['app.url' => 'https://inventaire.example.test/']);

        expect(PublicUrls::documensoWebhook())->toBe('https://inventaire.example.test/webhooks/documenso');
    });
});
