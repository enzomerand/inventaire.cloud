<?php

declare(strict_types=1);

use App\Signatures\DocumensoClient;
use App\Signatures\DocumensoException;
use App\Signatures\SignatureEnvelope;
use App\Signatures\SignedPdfTooLargeException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDocumenso;

require_once __DIR__.'/../../Support/versions.php';

/*
 * The calls the application makes to Documenso (API version 2, `/envelope/*`): their shape on the wire, how they fail, and the document and
 * the signers they are made of. Nothing leaves the process: the network is faked and any request that is not expected is refused.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    $this->useLanguage('en');
    versionDocumensoConfig();
});

/**
 * The client, with the network answering `$answer` to whatever it is asked (and `Http::preventStrayRequests()` on).
 *
 * @param  mixed  $answer  What the fake network answers: anything `Http::fake()` takes as a stub.
 */
function documensoClient(mixed $answer = null): DocumensoClient
{
    Http::fake(['*' => $answer ?? Http::response(['ok' => true])]);
    Http::preventStrayRequests();

    return app(DocumensoClient::class);
}

describe('creating an envelope', function (): void {
    it('posts a multipart request: the JSON payload in one part, the PDF in another, with the token as it is', function (): void {
        $recipients = [
            ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'fields' => [['page' => 3, 'positionX' => 12.5, 'positionY' => 40, 'width' => 30.25, 'height' => 10]]],
            ['name' => 'Grace Hopper', 'email' => 'grace@example.test', 'fields' => [['page' => 3, 'positionX' => 52.5, 'positionY' => 40, 'width' => 30.25, 'height' => 10]]],
        ];
        $meta = ['subject' => 'Sign', 'message' => 'Please', 'language' => 'fr', 'timezone' => 'Europe/Paris', 'signingOrder' => 'PARALLEL', 'distributionMethod' => 'EMAIL'];
        $client = documensoClient(Http::response(['id' => 'envelope_abc123']));

        $id = $client->createEnvelope('Tools — version 1', '01j0000000000000000000000x', "%PDF-1.7\nthe bytes", $recipients, $meta, 'Tools - version 1.pdf');

        expect($id)->toBe('envelope_abc123');
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $files = FakeDocumenso::part($request, 'files');

            expect($request->method())->toBe('POST')
                ->and($request->url())->toBe('https://sign.example.test/api/v2/envelope/create')
                // The token goes bare: no "Bearer".
                ->and($request->header('Authorization'))->toBe(['api_test_key'])
                ->and($request->header('Content-Type')[0])->toStartWith('multipart/form-data')
                ->and(count($request->data()))->toBe(2)
                ->and($files['contents'])->toBe("%PDF-1.7\nthe bytes")
                ->and($files['filename'])->toBe('Tools - version 1.pdf')
                ->and($files['headers']['Content-Type'])->toBe('application/pdf')
                ->and(FakeDocumenso::part($request, 'payload')['contents'])->toBeString();

            $payload = FakeDocumenso::payload($request);

            expect(array_keys($payload))->toEqualCanonicalizing(['type', 'title', 'externalId', 'recipients', 'meta'])
                ->and($payload['type'])->toBe('DOCUMENT')
                ->and($payload['title'])->toBe('Tools — version 1')
                ->and($payload['externalId'])->toBe('01j0000000000000000000000x')
                ->and($payload['meta'])->toBe([
                    'subject' => 'Sign', 'message' => 'Please', 'language' => 'fr', 'timezone' => 'Europe/Paris', 'signingOrder' => 'PARALLEL', 'distributionMethod' => 'EMAIL',
                ])
                ->and($payload['recipients'])->toHaveCount(2)
                ->and($payload['recipients'][1])->toBe([
                    'email' => 'grace@example.test',
                    'name' => 'Grace Hopper',
                    'role' => 'SIGNER',
                    'fields' => [[
                        'type' => 'SIGNATURE', 'identifier' => 0, 'page' => 3, 'positionX' => 52.5, 'positionY' => 40, 'width' => 30.25, 'height' => 10,
                        'fieldMeta' => ['type' => 'signature', 'required' => true],
                    ]],
                ]);

            return true;
        });
    });

    it('waits two minutes for the answer that takes a whole PDF, 5 seconds to connect, and follows no redirect', function (): void {
        $options = null;
        $client = documensoClient(function (Request $request, array $given) use (&$options) {
            $options = $given;

            return Http::response(['id' => 'envelope_abc123']);
        });

        $client->createEnvelope('T', 'x', '%PDF-', [], []);

        expect($options['timeout'])->toBe(120)
            ->and($options['connect_timeout'])->toBe(5)
            ->and($options['allow_redirects'])->toBeFalse();
    });

    it('refuses an answer that gives no usable id', function (mixed $body): void {
        $client = documensoClient(Http::response($body));

        try {
            $client->createEnvelope('T', 'x', '%PDF-', [], []);
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull()->and($thrown->retryable())->toBeFalse()->and($thrown->code())->toBe('invalid_response');
    })->with([
        'no id' => [[]],
        'a number' => [['id' => 12]],
        'an id of another shape' => [['id' => 'envelope with spaces']],
        'an id that does not fit the column' => [['id' => str_repeat('a', 65)]],
    ]);
});

describe('the other calls', function (): void {
    it('reads an envelope by its id', function (): void {
        $client = documensoClient(Http::response(['id' => 'envelope_abc', 'status' => 'PENDING', 'recipients' => [], 'envelopeItems' => []]));

        $envelope = $client->getEnvelope('envelope_abc');

        expect($envelope['status'])->toBe('PENDING');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://sign.example.test/api/v2/envelope/envelope_abc'
            && $request->header('Authorization') === ['api_test_key']
            && $request->header('Accept') === ['application/json']);
    });

    it('refuses an answer that is not an envelope', function (mixed $body): void {
        $client = documensoClient(Http::response($body));

        try {
            $client->getEnvelope('envelope_abc');
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull()->and($thrown->code())->toBe('invalid_response')->and($thrown->retryable())->toBeFalse();
    })->with([
        'a page' => ['<html>Sign in</html>'],
        'no status' => [['id' => 'envelope_abc']],
        'a status that is not text' => [['status' => 3]],
    ]);

    it('distributes an envelope with a JSON body', function (): void {
        $client = documensoClient(Http::response(['success' => true]));

        $client->distribute('envelope_abc');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sign.example.test/api/v2/envelope/distribute'
            && $request->header('Authorization') === ['api_test_key']
            && $request->data() === ['envelopeId' => 'envelope_abc']);
    });

    it('cancels an envelope, with the reason when there is one', function (): void {
        $client = documensoClient(Http::response(['success' => true]));

        $client->cancel('envelope_abc', 'No longer needed');
        $client->cancel('envelope_abc');

        $sent = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();

        expect($sent)->toBe([['envelopeId' => 'envelope_abc', 'reason' => 'No longer needed'], ['envelopeId' => 'envelope_abc']]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sign.example.test/api/v2/envelope/cancel' && $request->method() === 'POST');
    });

    it('downloads the signed PDF of an item into a file as it is, asking for the signed version, and gives its size', function (): void {
        $client = documensoClient(Http::response("%PDF-1.7\nsigned", 200, ['Content-Type' => 'application/pdf']));
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            $size = $client->downloadSigned('envelope_item_abc', $file);

            expect($size)->toBe(strlen("%PDF-1.7\nsigned"))
                ->and(file_get_contents($file))->toBe("%PDF-1.7\nsigned");
        } finally {
            @unlink($file);
        }

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://sign.example.test/api/v2/envelope/item/envelope_item_abc/download?version=signed'
            && $request->header('Authorization') === ['api_test_key']
            && $request->header('Accept') === ['application/pdf']);
    });

    it('refuses a download that is not a PDF', function (): void {
        $client = documensoClient(Http::response(['error' => 'x']));
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            expect(fn () => $client->downloadSigned('item', $file))->toThrow(DocumensoException::class, 'not a PDF');
        } finally {
            @unlink($file);
        }
    });

    it('waits two minutes for the download, as for the creation, and 20 seconds for every other call', function (): void {
        $fake = FakeDocumenso::install();
        $fake->envelopeId = 'envelope_abc';
        $fake->exists = true;
        $fake->status = 'COMPLETED';
        $client = app(DocumensoClient::class);
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        // The answers do not matter here, the options of each call do.
        $try = function (callable $call): void {
            try {
                $call();
            } catch (DocumensoException) {
                // Refused or not, the call was made.
            }
        };

        try {
            $try(fn () => $client->createEnvelope('T', 'x', '%PDF-', [], []));
            $try(fn () => $client->getEnvelope('envelope_test1'));
            $try(fn () => $client->downloadSigned('item', $file));
            $try(fn () => $client->distribute('envelope_test1'));
            $try(fn () => $client->cancel('envelope_test1'));
            $try(fn () => $client->deleteEnvelope('envelope_test1'));
        } finally {
            @unlink($file);
        }

        expect($fake->timeoutOf('POST /envelope/create'))->toBe(120)
            ->and($fake->timeoutOf('GET /envelope/item/{id}/download'))->toBe(120)
            ->and($fake->timeoutOf('GET /envelope/{id}'))->toBe(20)
            ->and($fake->timeoutOf('POST /envelope/distribute'))->toBe(20)
            ->and($fake->timeoutOf('POST /envelope/cancel'))->toBe(20)
            ->and($fake->timeoutOf('POST /envelope/delete'))->toBe(20);
    });

    it('deletes an envelope with a JSON body', function (): void {
        $client = documensoClient(Http::response(['success' => true]));

        $client->deleteEnvelope('envelope_abc');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://sign.example.test/api/v2/envelope/delete'
            && $request->header('Authorization') === ['api_test_key']
            && $request->data() === ['envelopeId' => 'envelope_abc']);
    });
});

describe('the weight of the signed PDF', function (): void {
    it('is 100 MB at the most by default', function (): void {
        expect(config('inventories.max_signed_pdf_bytes'))->toBe(100 * 1048576);
    });

    it('is refused when Documenso says it is bigger than the limit, and nothing of it is kept', function (): void {
        config(['inventories.max_signed_pdf_bytes' => 20]);
        $client = documensoClient(Http::response("%PDF-1.7\nsigned", 200, ['Content-Type' => 'application/pdf', 'Content-Length' => '21']));
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            expect(fn () => $client->downloadSigned('item', $file))->toThrow(DocumensoException::class, 'bigger than the limit');
        } finally {
            @unlink($file);
        }
    });

    it('is refused when it really is bigger than the limit, whatever Documenso says (or does not say)', function (): void {
        config(['inventories.max_signed_pdf_bytes' => 10]);
        $client = documensoClient(Http::response("%PDF-1.7\nsigned, and some more", 200, ['Content-Type' => 'application/pdf']));
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            $thrown = null;

            try {
                $client->downloadSigned('item', $file);
            } catch (DocumensoException $exception) {
                $thrown = $exception;
            }

            expect($thrown?->code())->toBe('invalid_response')->and($thrown?->retryable())->toBeFalse();
        } finally {
            @unlink($file);
        }
    });

    it('is accepted at the limit, to the byte', function (): void {
        config(['inventories.max_signed_pdf_bytes' => strlen("%PDF-1.7\nsigned")]);
        $client = documensoClient(Http::response("%PDF-1.7\nsigned", 200, ['Content-Type' => 'application/pdf', 'Content-Length' => (string) strlen("%PDF-1.7\nsigned")]));
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            expect($client->downloadSigned('item', $file))->toBe(strlen("%PDF-1.7\nsigned"));
        } finally {
            @unlink($file);
        }
    });

    it('is read into a file, not into memory: the sink is what the request is given', function (): void {
        $options = null;
        $client = documensoClient(function (Request $request, array $given) use (&$options) {
            $options = $given;

            return Http::response("%PDF-1.7\nsigned", 200, ['Content-Type' => 'application/pdf']);
        });
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            $client->downloadSigned('item', $file);
        } finally {
            @unlink($file);
        }

        expect($options['sink'])->toBe($file)->and($options['on_headers'])->toBeCallable()->and($options['progress'])->toBeCallable();
    });

    it('is refused, not retried, when the transfer was stopped for its size, whatever the HTTP client made of what stopped it', function (): void {
        $client = documensoClient(function (): never {
            // The HTTP client wraps what its callbacks throw, twice: the cause is at the bottom of the chain.
            throw new ConnectionException('An error was encountered during the progress event', 0, new RuntimeException('wrapper', 0, new SignedPdfTooLargeException('too big')));
        });
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            $thrown = null;

            try {
                $client->downloadSigned('item', $file);
            } catch (DocumensoException $exception) {
                $thrown = $exception;
            }

            expect($thrown?->code())->toBe('invalid_response')->and($thrown?->retryable())->toBeFalse()->and($thrown?->getMessage())->toContain('bigger than the limit');
        } finally {
            @unlink($file);
        }
    });

    it('is retried like any other call when the connection is lost for another reason', function (): void {
        $client = documensoClient(Http::failedConnection('cURL error 28: Operation timed out'));
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            $thrown = null;

            try {
                $client->downloadSigned('item', $file);
            } catch (DocumensoException $exception) {
                $thrown = $exception;
            }

            expect($thrown?->code())->toBe('unreachable')->and($thrown?->retryable())->toBeTrue();
        } finally {
            @unlink($file);
        }
    });

    it('is cut at the headers when Documenso announces more than the limit, and while it comes when the body outgrows it', function (): void {
        // The callbacks the HTTP client calls as soon as the headers are in, and as the body comes: they throw before more is written.
        config(['inventories.max_signed_pdf_bytes' => 100]);
        $callback = null;
        $progress = null;
        $client = documensoClient(function (Request $request, array $given) use (&$callback, &$progress) {
            $callback = $given['on_headers'];
            $progress = $given['progress'];

            return Http::response("%PDF-1.7\nsigned", 200, ['Content-Type' => 'application/pdf']);
        });
        $file = tempnam(sys_get_temp_dir(), 'signed-');

        try {
            $client->downloadSigned('item', $file);
        } finally {
            @unlink($file);
        }

        $headers = fn (string $length, int $status = 200) => new Response($status, ['Content-Length' => $length]);

        expect(fn () => $callback($headers('101')))->toThrow(SignedPdfTooLargeException::class)
            ->and($callback($headers('100')))->toBeNull()
            ->and($callback($headers('5000', 404)))->toBeNull()
            ->and($callback(new Response(200)))->toBeNull()
            ->and($progress(0, 100))->toBeNull()
            ->and(fn () => $progress(0, 101))->toThrow(SignedPdfTooLargeException::class);
    });
});

describe('the rest', function (): void {
    it('does not even try when the instance has no Documenso', function (): void {
        config(['services.documenso.url' => null, 'services.documenso.api_key' => '']);
        $client = documensoClient();

        try {
            $client->getEnvelope('envelope_abc');
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->code())->toBe('not_configured')->and($thrown?->retryable())->toBeFalse();
        Http::assertNothingSent();
    });

    it('uses the address however the administrator typed it', function (string $typed): void {
        config(['services.documenso.url' => $typed]);
        $client = documensoClient(Http::response(['status' => 'PENDING']));

        $client->getEnvelope('envelope_abc');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sign.example.test/api/v2/envelope/envelope_abc');
    })->with(['https://sign.example.test', 'https://sign.example.test/', 'https://sign.example.test/api/v2', ' https://sign.example.test/api ']);
});

describe('a call that fails', function (): void {
    it('says whether trying again can help, with the status and a code', function (int $status, bool $retryable): void {
        $client = documensoClient(Http::response(['code' => 'INVALID_REQUEST', 'message' => 'Secret details of the failure'], $status));

        try {
            $client->distribute('envelope_abc');
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->retryable())->toBe($retryable)
            ->and($thrown->status())->toBe($status)
            ->and($thrown->code())->toBe('INVALID_REQUEST')
            // The message names the call and the status; neither the body nor the token is in it.
            ->and($thrown->getMessage())->toBe("Documenso answered {$status} to POST /envelope/distribute (INVALID_REQUEST).")
            ->and($thrown->getMessage())->not->toContain('Secret details')->not->toContain('api_test_key');
    })->with([
        'bad request' => [400, false],
        'unauthorized' => [401, false],
        'forbidden' => [403, false],
        'not found' => [404, false],
        'conflict' => [409, false],
        'unprocessable' => [422, false],
        'too many requests' => [429, true],
        'server error' => [500, true],
        'bad gateway' => [502, true],
        'unavailable' => [503, true],
    ]);

    it('is retryable when the connection is lost or times out, without a status', function (): void {
        $client = documensoClient(Http::failedConnection('cURL error 28: Operation timed out'));

        try {
            $client->getEnvelope('envelope_abc');
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        expect($thrown)->not->toBeNull()
            ->and($thrown->retryable())->toBeTrue()
            ->and($thrown->status())->toBeNull()
            ->and($thrown->code())->toBe('unreachable')
            ->and($thrown->notFound())->toBeFalse()
            ->and($thrown->unauthorized())->toBeFalse();
    });

    it('reads nothing of the body but a code in the shape of one', function (mixed $body, string $code): void {
        $client = documensoClient(Http::response($body, 400));

        try {
            $client->cancel('envelope_abc');
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->code())->toBe($code);
    })->with([
        'a code' => [['code' => 'MISSING_SIGNATURE_FIELD'], 'MISSING_SIGNATURE_FIELD'],
        'no code' => [['message' => 'Only pending documents can be cancelled'], 'http_400'],
        'a code of another shape' => [['code' => 'drop table; --'], 'http_400'],
        'a code that is not text' => [['code' => ['x']], 'http_400'],
        'a page' => ['<html>Bad request</html>', 'http_400'],
        'nothing' => ['', 'http_400'],
    ]);

    it('tells a missing envelope and a refused token apart', function (): void {
        $missing = null;
        $refused = null;
        $client = documensoClient(Http::sequence()->push(['code' => 'NOT_FOUND'], 404)->push([], 403));

        try {
            $client->getEnvelope('envelope_abc');
        } catch (DocumensoException $exception) {
            $missing = $exception;
        }

        try {
            $client->getEnvelope('envelope_abc');
        } catch (DocumensoException $exception) {
            $refused = $exception;
        }

        expect($missing?->notFound())->toBeTrue()->and($missing->unauthorized())->toBeFalse()
            ->and($refused?->unauthorized())->toBeTrue()->and($refused->notFound())->toBeFalse();
    });

    it('treats a redirect as a failure it does not follow', function (): void {
        $client = documensoClient(Http::response('', 302, ['Location' => 'https://elsewhere.example.test/']));

        try {
            $client->getEnvelope('envelope_abc');
            $thrown = null;
        } catch (DocumensoException $exception) {
            $thrown = $exception;
        }

        expect($thrown?->status())->toBe(302)->and($thrown->retryable())->toBeFalse();
        Http::assertSentCount(1);
    });

    it('never writes the token or the document in what it throws or logs', function (): void {
        Log::spy();
        $client = documensoClient(Http::failedConnection('cURL error 7: Failed to connect'));

        try {
            $client->createEnvelope('Title', 'x', "%PDF-1.7\nTHE-DOCUMENT-BYTES", [], []);
        } catch (DocumensoException $exception) {
            $text = $exception->getMessage().' '.$exception->getTraceAsString();
        }

        expect($text)->not->toContain('api_test_key')->not->toContain('THE-DOCUMENT-BYTES');
        Log::shouldNotHaveReceived('error');
    });
});

describe('the envelope of a version', function (): void {
    it('puts each signature box where the page was measured, on the last page, for each signer', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], 'Year end', versionSigners(3)));
        $fake = FakeDocumenso::install();

        versionSend($version);

        $sent = FakeDocumenso::payload($fake->requestsOf('POST /envelope/create')[0]);
        $signers = $version->refresh()->signers;

        expect($version->pdf_pages)->toBeGreaterThan(1)
            ->and($sent['recipients'])->toHaveCount(3)
            ->and($fake->pdf)->toBe(versionFile($version, (string) $version->pdf_path));

        foreach ($sent['recipients'] as $index => $recipient) {
            $field = $signers[$index]['field'];

            expect($recipient['email'])->toBe('signer'.($index + 1).'@example.test')
                ->and($recipient['name'])->toBe('Signer '.($index + 1))
                ->and($recipient['role'])->toBe('SIGNER')
                ->and($recipient['fields'])->toHaveCount(1)
                ->and($recipient['fields'][0]['type'])->toBe('SIGNATURE')
                ->and($recipient['fields'][0]['page'])->toBe($field['page'])
                ->and($recipient['fields'][0]['page'])->toBe($version->pdf_pages)
                ->and($recipient['fields'][0]['positionX'])->toEqual($field['x'])
                ->and($recipient['fields'][0]['positionY'])->toEqual($field['y'])
                ->and($recipient['fields'][0]['width'])->toEqual($field['width'])
                ->and($recipient['fields'][0]['height'])->toEqual($field['height'])
                // Percentages of the page, counted from the top: nothing outside it.
                ->and($recipient['fields'][0]['positionX'] + $recipient['fields'][0]['width'])->toBeLessThanOrEqual(100)
                ->and($recipient['fields'][0]['positionY'] + $recipient['fields'][0]['height'])->toBeLessThanOrEqual(100);
        }
    });

    it('is titled and worded in the language of the version, whatever the language of the worker', function (string $language, array $expected): void {
        $this->useLanguage($language);
        $case = versionTableCase();
        $case['team']['owner']->forceFill(['name' => 'Ada Lovelace'])->save();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
        $this->useLanguage($language === 'fr' ? 'en' : 'fr');

        $envelope = SignatureEnvelope::forVersion($version->refresh());

        expect($envelope->title)->toBe($expected['title'])
            ->and($envelope->fileName)->toBe($expected['file'])
            ->and($envelope->externalId)->toBe($version->ulid)
            ->and($envelope->meta)->toBe([
                'subject' => $expected['subject'],
                'message' => $expected['message'],
                'language' => $language,
                'timezone' => 'Europe/Paris',
                'signingOrder' => 'PARALLEL',
                'distributionMethod' => 'EMAIL',
            ])
            // The worker is left in the language it had.
            ->and(app()->getLocale())->toBe($language === 'fr' ? 'en' : 'fr');
    })->with([
        'French' => ['fr', [
            'title' => 'Tools — version 1',
            'file' => 'Tools - version 1.pdf',
            'subject' => 'Signature demandée : Tools — version 1',
            'message' => 'Ada Lovelace vous invite à signer la version officielle n° 1 de l\'inventaire « Tools » (Shop).',
        ]],
        'English' => ['en', [
            'title' => 'Tools — version 1',
            'file' => 'Tools - version 1.pdf',
            'subject' => 'Signature requested: Tools — version 1',
            'message' => 'Ada Lovelace invites you to sign official version no. 1 of the inventory “Tools” (Shop).',
        ]],
    ]);

    it('keeps the subject and the message within what Documenso accepts', function (): void {
        $case = versionTableCase();
        $case['inventory']->forceFill(['name' => str_repeat('é', 120)])->save();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));

        $envelope = SignatureEnvelope::forVersion($version->refresh());

        expect(mb_strlen($envelope->meta['subject']))->toBeLessThanOrEqual(254)
            ->and(mb_strlen($envelope->meta['message']))->toBeLessThanOrEqual(5000)
            ->and(mb_strlen($envelope->title))->toBeLessThan(180);
    });

    it('speaks English to Documenso in a language it does not know', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(1)));
        $version->forceFill(['locale' => 'de'])->save();

        expect(SignatureEnvelope::forVersion($version->refresh())->meta['language'])->toBe('en');
    });

    it('cannot be built for a signer without a signature box, or for nobody', function (): void {
        $case = versionTableCase();
        $version = versionRun(versionFreeze($case['inventory'], $case['team']['owner'], null, versionSigners(2)));
        $signers = $version->refresh()->signers;
        unset($signers[1]['field']);
        $version->forceFill(['signers' => $signers])->save();

        expect(fn () => SignatureEnvelope::forVersion($version->refresh()))->toThrow(DocumensoException::class, 'no signature area');

        $version->forceFill(['signers' => null])->save();

        expect(fn () => SignatureEnvelope::forVersion($version->refresh()))->toThrow(DocumensoException::class, 'no signer');
    });
});
