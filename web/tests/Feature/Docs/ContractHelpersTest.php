<?php

declare(strict_types=1);

use App\Docs\OpenApi;
use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\ExpectationFailedException;

require_once __DIR__.'/../../Support/contract.php';

/*
 * The helpers the contract tests hold the API to the document with (tests/Support/contract.php) are held to account here: a check that
 * lets anything pass would make the whole contract look kept. Each case below is a way for the document and the API to part that the
 * helpers must notice.
 */

/**
 * Puts a small document of operations in place of the real one for the rest of the test.
 *
 * @param  array<string, mixed>  $paths
 * @param  array<string, mixed>  $schemas
 */
function contractUseDocument(array $paths, array $schemas = []): void
{
    $document = json_encode(['openapi' => '3.1.0', 'info' => ['title' => 'Test', 'version' => '1'], 'paths' => $paths, 'components' => ['schemas' => $schemas]]);

    app()->instance(OpenApi::class, new OpenApi(contractScratchSpec((string) $document)));
}

/**
 * The operation of the small documents: `GET /thing` with the responses it is given.
 *
 * @param  array<string, mixed>  $responses
 * @return array<string, mixed>
 */
function contractThing(array $responses, ?array $requestBody = null): array
{
    return ['/thing' => ['post' => ['operationId' => 'thing', 'tags' => ['Things'], 'summary' => 'Thing', 'x-ability' => 'read', 'responses' => $responses] + ($requestBody === null ? [] : ['requestBody' => $requestBody])]];
}

/**
 * A response as the application would give it.
 *
 * @param  array<string, string>  $headers
 */
function contractAnswer(string $body, string $type = 'application/json', array $headers = [], int $status = 200): TestResponse
{
    return TestResponse::fromBaseResponse(new Response($body, $status, ['Content-Type' => $type] + $headers));
}

describe('the check of a body against a schema', function (): void {
    it('refuses a property the schema does not name', function (): void {
        $schema = contractSpec()['components']['schemas']['Workspace'];
        $workspace = '{"id":"01k8gv2d9m3x5r7qn4p6t8w0ya","name":"Shop","role":"owner","is_current":false,"created_at":"2026-10-15T12:30:00+00:00"';

        expect(contractViolations(json_decode($workspace.'}'), $schema))->toBe([])
            ->and(contractViolations(json_decode($workspace.',"secret":1}'), $schema))->not->toBe([]);
    });

    it('refuses it deep in the body: in an object of a list, behind a reference', function (): void {
        $schema = ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Workspace']]]];
        $workspace = ['id' => '01k8gv2d9m3x5r7qn4p6t8w0ya', 'name' => 'Shop', 'role' => 'owner', 'is_current' => false, 'created_at' => '2026-10-15T12:30:00+00:00'];

        expect(contractViolations(json_decode((string) json_encode(['data' => [$workspace]])), $schema))->toBe([])
            ->and(contractViolations(json_decode((string) json_encode(['data' => [$workspace + ['extra' => true]]])), $schema))->not->toBe([])
            ->and(contractViolations(json_decode((string) json_encode(['data' => [$workspace], 'extra' => true])), $schema))->not->toBe([]);
    });

    it('refuses it in a schema made with allOf, and in a schema that is a part of another one as well as alone', function (): void {
        // CodedError is Error + a required code; Error is a part of it and an answer by itself.
        $coded = ['$ref' => '#/components/schemas/CodedError'];
        $plain = ['$ref' => '#/components/schemas/Error'];

        expect(contractViolations(json_decode('{"message":"No","code":"x"}'), $coded))->toBe([])
            ->and(contractViolations(json_decode('{"message":"No","code":"x","extra":1}'), $coded))->not->toBe([])
            ->and(contractViolations(json_decode('{"message":"No","code":"x"}'), $plain))->toBe([])
            ->and(contractViolations(json_decode('{"message":"No","extra":1}'), $plain))->not->toBe([]);
    });

    it('leaves open an object whose schema says it is (additionalProperties)', function (): void {
        $schema = ['type' => 'object', 'additionalProperties' => ['type' => 'integer']];

        expect(contractViolations(json_decode('{"a":1,"b":2}'), $schema))->toBe([])
            ->and(contractViolations(json_decode('{"a":1,"b":"x"}'), $schema))->not->toBe([]);
    });

    it('does not touch the document, which is checked as it is written', function (): void {
        $before = json_encode(contractSpec());

        contractViolations(json_decode('{"id":"x"}'), contractSpec()['components']['schemas']['Workspace']);

        expect(json_encode(contractSpec()))->toBe($before)->and($before)->not->toContain('unevaluatedProperties');
    });

    it('refuses a schema that contains itself, which it cannot close', function (): void {
        contractUseDocument([], ['Loop' => ['type' => 'object', 'properties' => ['again' => ['$ref' => '#/components/schemas/Loop']]]]);

        expect(fn (): array => contractStrictSchema(['$ref' => '#/components/schemas/Loop']))->toThrow(RuntimeException::class, 'contains itself');
    });
});

describe('the check of an answer', function (): void {
    it('fails for a JSON answer the document gives no schema', function (): void {
        contractUseDocument(contractThing(['200' => ['description' => 'Fine', 'content' => ['application/json' => ['example' => ['a' => 1]]]]]));

        expect(fn () => contractCheck(contractAnswer('{"a":1}'), 'thing', 200))->toThrow(ExpectationFailedException::class, 'no schema');
    });

    it('fails for a JSON answer that has a property the schema does not name, and passes one that fits', function (): void {
        contractUseDocument(contractThing(['200' => ['description' => 'Fine', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'integer']]]]]]]));

        contractCheck(contractAnswer('{"a":1}'), 'thing', 200);

        expect(fn () => contractCheck(contractAnswer('{"a":1,"b":2}'), 'thing', 200))->toThrow(ExpectationFailedException::class, 'does not fit its schema');
    });

    it('fails for a status the document does not give, a body it does not expect and a type it does not list', function (): void {
        contractUseDocument(contractThing([
            '200' => ['description' => 'Fine', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
            '204' => ['description' => 'Nothing'],
        ]));

        expect(fn () => contractCheck(contractAnswer('{}', status: 201), 'thing', 201))->toThrow(ExpectationFailedException::class, 'does not document the status')
            ->and(fn () => contractCheck(contractAnswer('{}', status: 204), 'thing', 204))->toThrow(ExpectationFailedException::class, 'documents no body')
            ->and(fn () => contractCheck(contractAnswer('x', 'text/plain'), 'thing', 200))->toThrow(ExpectationFailedException::class, 'does not document');
    });

    it('holds the Content-Disposition of a file to the document both ways', function (): void {
        $file = ['type' => 'string', 'format' => 'binary'];
        $disposition = ['Content-Disposition' => ['description' => 'The name.', 'schema' => ['type' => 'string']]];

        contractUseDocument(contractThing([
            '200' => ['description' => 'Listed', 'headers' => $disposition, 'content' => ['text/csv' => ['schema' => $file]]],
            '201' => ['description' => 'Not listed', 'content' => ['text/csv' => ['schema' => $file]]],
        ]));

        contractCheck(contractAnswer('a,b', 'text/csv', ['Content-Disposition' => 'attachment; filename=a.csv']), 'thing', 200);
        contractCheck(contractAnswer('a,b', 'text/csv', status: 201), 'thing', 201);

        // Sent and not listed.
        expect(fn () => contractCheck(contractAnswer('a,b', 'text/csv', ['Content-Disposition' => 'attachment; filename=a.csv'], 201), 'thing', 201))
            ->toThrow(ExpectationFailedException::class, 'Content-Disposition')
            // Listed and not sent.
            ->and(fn () => contractCheck(contractAnswer('a,b', 'text/csv'), 'thing', 200))->toThrow(ExpectationFailedException::class, 'Content-Disposition')
            // Not a disposition.
            ->and(fn () => contractCheck(contractAnswer('a,b', 'text/csv', ['Content-Disposition' => 'nonsense']), 'thing', 200))->toThrow(ExpectationFailedException::class, 'Content-Disposition');
    });

    it('does not read the body of a file, even when it is JSON', function (): void {
        $file = ['type' => 'string', 'format' => 'binary'];

        contractUseDocument(contractThing(['200' => ['description' => 'Listed', 'headers' => ['Content-Disposition' => ['schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => $file]]]]));

        contractCheck(contractAnswer('{"export":true}', 'application/json', ['Content-Disposition' => 'attachment; filename=a.json']), 'thing', 200);
    });
});

describe('the check of a request', function (): void {
    it('accepts a body that fits the document and refuses one that does not', function (): void {
        expect(contractRequestViolations('createWorkspace', ['name' => 'Garage']))->toBe([])
            ->and(contractRequestViolations('createWorkspace', ['name' => 'Garage', 'color' => 'red']))->not->toBe([])
            ->and(contractRequestViolations('createWorkspace', ['name' => 5]))->not->toBe([])
            ->and(contractRequestViolations('createWorkspace', ['name' => '']))->not->toBe([])
            ->and(contractRequestViolations('createWorkspace', []))->not->toBe([]);
    });

    it('refuses a body for an operation that documents none, and an operation the document does not have', function (): void {
        expect(contractRequestViolations('listWorkspaces', []))->toBe([])
            ->and(contractRequestViolations('listWorkspaces', ['name' => 'x']))->not->toBe([])
            ->and(contractRequestViolations('nothing', []))->not->toBe([]);
    });

    it('reads a body deep down: an option a field does not have is a violation, and so is a choice with no label', function (): void {
        expect(contractRequestViolations('createField', ['type' => 'select', 'name' => 'Condition', 'options' => ['choices' => [['label' => 'New'], ['label' => 'Used', 'color' => 'red']]]]))->toBe([])
            ->and(contractRequestViolations('createField', ['type' => 'text', 'name' => 'Brand', 'options' => ['colour' => 'red']]))->not->toBe([])
            ->and(contractRequestViolations('createField', ['type' => 'select', 'name' => 'Condition', 'options' => ['choices' => [['color' => 'red']]]]))->not->toBe([]);
    });

    it('reads the fields of a form other than its file', function (): void {
        $file = ['file' => 'the bytes'];

        expect(contractRequestViolations('uploadAttachment', $file, 'multipart/form-data'))->toBe([])
            ->and(contractRequestViolations('uploadAttachment', $file + ['colour' => 'red'], 'multipart/form-data'))->not->toBe([])
            ->and(contractRequestViolations('uploadAttachment', $file))->not->toBe([]);
    });

    it('sees a query parameter the operation does not list, and reads the brackets of the ones it does', function (): void {
        expect(contractUndocumentedQuery('listItems', '/api/v1/inventories/x/items'))->toBe([])
            ->and(contractUndocumentedQuery('listItems', '/api/v1/inventories/x/items?page=2&q=drill&sort=name&direction=asc&totals[]=a&totals[]=b&filter[a][min]=1'))->toBe([])
            ->and(contractUndocumentedQuery('listItems', '/api/v1/inventories/x/items?page=2&limit=5&order=name'))->toBe(['limit', 'order'])
            ->and(contractUndocumentedQuery('getItem', '/api/v1/inventories/x/items/y?page=2'))->toBe(['page']);
    });
});
