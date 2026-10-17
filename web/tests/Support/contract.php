<?php

declare(strict_types=1);

// Helpers shared by the tests that hold the OpenAPI document to the API (tests/Feature/Docs). Each test file loads them with
// require_once, so a file can be run alone. Their names all start with "contract" so that they never collide with the helpers of
// tests/Pest.php or of other test files.

use App\Docs\OpenApi;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * The OpenAPI document as it is written in the file (what the page and the JSON are drawn from).
 *
 * @return array<string, mixed>
 */
function contractSpec(): array
{
    return app(OpenApi::class)->raw();
}

/**
 * The operations of the document, by `operationId`.
 *
 * @return array<string, array{method: string, path: string, operationId: string, tag: string, ability: string|null, summary: string, operation: array<string, mixed>, parameters: list<array<string, mixed>>}>
 */
function contractOperations(): array
{
    $operations = [];

    foreach (app(OpenApi::class)->operations() as $operation) {
        $operations[$operation['operationId']] = $operation;
    }

    return $operations;
}

/**
 * A Schema Object of the document the way the tests validate with it: every `$ref` followed, and every object it describes closed.
 *
 * JSON Schema lets an object carry any property it does not mention, so a key a response has and the document does not know would
 * pass. The test-side copy of the schema forbids it (`unevaluatedProperties: false`, which counts what `allOf` and `oneOf` evaluate), on
 * every object schema that has `properties` (or gets them from `allOf`, `anyOf` or `oneOf`) and says nothing of `additionalProperties`
 * or `unevaluatedProperties` itself. The file is left as it is written.
 *
 * A schema that is a branch of `allOf`, `anyOf`, `oneOf`, `not`, `if`, `then` or `else` is left open: it describes a part of the object,
 * and the schema that holds the branches closes it. That is why the `$ref` are followed instead of being left to the validator: the
 * same component is a whole object in one place (closed) and a part of another in the next (open).
 *
 * @param  array<string, mixed>  $schema  The Schema Object, its `$ref` not followed.
 * @param  list<string>  $following  The references being followed, from the root down to this node.
 * @return array<string, mixed>
 */
function contractStrictSchema(array $schema, bool $branch = false, array $following = []): array
{
    if (isset($schema['$ref']) && is_string($schema['$ref'])) {
        if (in_array($schema['$ref'], $following, true)) {
            throw new RuntimeException('The schema '.$schema['$ref'].' contains itself, which the contract tests cannot close.');
        }

        $following[] = $schema['$ref'];
        $schema = app(OpenApi::class)->resolve($schema);
    }

    foreach (['items', 'additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'contains', 'propertyNames'] as $keyword) {
        if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
            $schema[$keyword] = contractStrictSchema($schema[$keyword], false, $following);
        }
    }

    foreach (['not', 'if', 'then', 'else'] as $keyword) {
        if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
            $schema[$keyword] = contractStrictSchema($schema[$keyword], true, $following);
        }
    }

    foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
        if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
            $schema[$keyword] = array_map(fn (mixed $part): mixed => is_array($part) ? contractStrictSchema($part, true, $following) : $part, $schema[$keyword]);
        }
    }

    foreach (['properties', 'patternProperties', '$defs', 'dependentSchemas'] as $keyword) {
        if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
            $schema[$keyword] = array_map(fn (mixed $part): mixed => is_array($part) ? contractStrictSchema($part, false, $following) : $part, $schema[$keyword]);
        }
    }

    if (! $branch && ! array_key_exists('additionalProperties', $schema) && ! array_key_exists('unevaluatedProperties', $schema) && contractDeclaresProperties($schema)) {
        $schema['unevaluatedProperties'] = false;
    }

    return $schema;
}

/**
 * Whether a schema (its `$ref` already followed) names the properties of an object, itself or through its `allOf`, `anyOf` or `oneOf`.
 *
 * @param  array<string, mixed>  $schema
 */
function contractDeclaresProperties(array $schema): bool
{
    if (isset($schema['properties']) && is_array($schema['properties'])) {
        return true;
    }

    foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
        foreach (is_array($schema[$keyword] ?? null) ? $schema[$keyword] : [] as $part) {
            if (is_array($part) && contractDeclaresProperties($part)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Checks a decoded JSON value against a Schema Object of the document (OpenAPI 3.1 schemas are JSON Schema 2020-12), closed as
 * {@see contractStrictSchema()} says: a property the schema does not name is a violation.
 *
 * @param  array<string, mixed>  $schema  The Schema Object, its `$ref` not followed.
 * @return list<string> What is wrong with the value, empty when it fits.
 */
function contractViolations(mixed $value, array $schema): array
{
    $root = json_decode((string) json_encode(contractStrictSchema($schema), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    $result = (new Validator(max_errors: 20))->validate($value, $root);

    if ($result->isValid() || $result->error() === null) {
        return [];
    }

    $violations = [];

    foreach ((new ErrorFormatter)->format($result->error(), true) as $path => $messages) {
        foreach ((array) $messages as $message) {
            $violations[] = ($path === '' ? '/' : $path).': '.$message;
        }
    }

    return $violations;
}

/**
 * What is wrong with the body a scenario sends, held to the `requestBody` the operation documents: the operation documents one, in that
 * media type, and the payload fits its schema (closed, see {@see contractStrictSchema()}). A call with no payload is fine unless the
 * document says the body is required.
 *
 * @param  array<string, mixed>  $payload  The JSON body; for a form, its fields other than the file.
 * @return list<string>
 */
function contractRequestViolations(string $operationId, array $payload, string $mediaType = 'application/json'): array
{
    $operation = contractOperations()[$operationId] ?? null;

    if ($operation === null) {
        return ["{$operationId} is not an operation of the document"];
    }

    $body = $operation['operation']['requestBody'] ?? null;

    if (! is_array($body)) {
        return $payload === [] ? [] : ["{$operationId} documents no request body, and the scenario sends ".json_encode($payload)];
    }

    $body = app(OpenApi::class)->resolve($body);

    if ($payload === [] && ($body['required'] ?? false) !== true) {
        return [];
    }

    $schema = $body['content'][$mediaType]['schema'] ?? null;

    if (! is_array($schema)) {
        return ["{$operationId} documents no {$mediaType} request body with a schema"];
    }

    return contractViolations(json_decode((string) json_encode($payload === [] ? new stdClass : $payload, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), $schema);
}

/**
 * The query parameters a scenario sends that the operation does not document (the names of `totals[]`, `filter[...]` and the like are
 * read without their brackets, as the document names them).
 *
 * @return list<string>
 */
function contractUndocumentedQuery(string $operationId, string $uri): array
{
    $operation = contractOperations()[$operationId] ?? null;
    $query = (string) parse_url($uri, PHP_URL_QUERY);

    if ($operation === null || $query === '') {
        return [];
    }

    $documented = [];

    foreach ($operation['parameters'] as $parameter) {
        if (($parameter['in'] ?? '') === 'query') {
            $documented[] = (string) preg_replace('/\[.*$/', '', (string) $parameter['name']);
        }
    }

    parse_str($query, $sent);

    return array_values(array_diff(array_map(strval(...), array_keys($sent)), $documented));
}

/**
 * The Response Object an operation documents for a status, its `$ref` followed; null when the status is not documented.
 *
 * @param  array<string, mixed>  $operation  As {@see contractOperations()} gives it.
 * @return array<string, mixed>|null
 */
function contractResponse(array $operation, int|string $status): ?array
{
    $response = $operation['operation']['responses'][(string) $status] ?? $operation['operation']['responses'][(int) $status] ?? null;

    return is_array($response) ? app(OpenApi::class)->resolve($response) : null;
}

/**
 * Holds a real answer to what the document says of it: the status is the one that was expected and the operation documents it, and the
 * body is of a documented media type that has a schema and, for JSON, fits it (closed: a property the schema does not name is a
 * violation, see {@see contractStrictSchema()}). A file (`type: string, format: binary`) is not read, but its `Content-Disposition` is
 * held to the document both ways: sent only if the response documents it, and documented only if it is sent.
 *
 * @param  string|null  $mediaType  The media type the body is expected to have (the first documented one when null).
 */
function contractCheck(TestResponse $response, string $operationId, int $status, ?string $mediaType = null): void
{
    $operation = contractOperations()[$operationId] ?? null;

    expect($operation)->not->toBeNull("{$operationId} is not an operation of the document");

    $response->assertStatus($status);

    $documented = contractResponse($operation, $status);

    expect($documented)->not->toBeNull("{$operationId} does not document the status {$status}");

    $content = is_array($documented['content'] ?? null) ? $documented['content'] : [];

    if ($content === []) {
        expect($response->getContent())->toBe('', "{$operationId} documents no body for {$status}");

        return;
    }

    $actual = strtolower(trim(explode(';', (string) $response->headers->get('Content-Type'))[0]));

    expect(in_array($actual, array_keys($content), true))->toBeTrue("{$operationId} {$status} answers {$actual}, which it does not document");

    if ($mediaType !== null) {
        expect($actual)->toBe($mediaType);
    }

    $schema = app(OpenApi::class)->resolve(is_array($content[$actual]['schema'] ?? null) ? $content[$actual]['schema'] : []);

    expect($schema)->not->toBe([], "no schema: {$operationId} {$status} documents {$actual} without saying what it looks like");

    if (($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) === 'binary') {
        $sent = $response->headers->has('Content-Disposition');
        $documents = in_array('content-disposition', array_map('strtolower', array_keys($documented['headers'] ?? [])), true);

        expect($documents)->toBe($sent, "{$operationId} {$status}: the file ".($sent ? 'is sent with a Content-Disposition, which the document does not list' : 'is documented with a Content-Disposition, which is not sent'));

        if ($sent) {
            expect((string) $response->headers->get('Content-Disposition'))->toMatch('/^(attachment|inline)(;|$)/', "{$operationId} {$status} Content-Disposition");
        }

        return;
    }

    if ($actual !== 'application/json') {
        return;
    }

    $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
    $violations = contractViolations($body, $schema);

    expect($violations)->toBe([], "{$operationId} {$status} does not fit its schema:\n".json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * The codes an operation says a status can carry (`x-codes`).
 *
 * @return list<string>
 */
function contractCodes(string $operationId, int $status): array
{
    $response = contractResponse(contractOperations()[$operationId], $status);

    return array_values(array_map(strval(...), array_column($response['x-codes'] ?? [], 'code')));
}

/**
 * The headers a response is documented to carry, by name.
 *
 * @return list<string>
 */
function contractHeaders(string $operationId, int $status): array
{
    return array_keys(contractResponse(contractOperations()[$operationId], $status)['headers'] ?? []);
}

/**
 * The error codes the application writes in its answers, found in its source: the literal second argument (third for `invalid()`) of
 * every `ApiError::` call, and every `'code' => '...'` of an array. A code that is not a literal (a variable, a constant) is listed
 * apart, with where it is, so that a test can say what it does not see.
 *
 * @return array{literal: array<string, string>, dynamic: list<string>} The codes with the first file that writes each; the places that write one the scan cannot read.
 */
function contractEmittedCodes(): array
{
    $literal = [];
    $dynamic = [];
    $positions = ['conflict' => 1, 'forbidden' => 1, 'notFound' => 1, 'unavailable' => 1, 'invalid' => 2];
    $files = [base_path('bootstrap/app.php')];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    foreach ($files as $path) {
        $name = str_replace(base_path().'/', '', $path);
        $tokens = array_values(array_filter(PhpToken::tokenize((string) file_get_contents($path)), fn (PhpToken $token): bool => ! $token->isIgnorable()));

        foreach ($tokens as $index => $token) {
            // 'code' => 'literal'
            if ($token->text === "'code'" && ($tokens[$index + 1]->text ?? '') === '=>') {
                $value = $tokens[$index + 2] ?? null;

                if ($value !== null && $value->id === T_CONSTANT_ENCAPSED_STRING) {
                    $literal[trim($value->text, "'\"")] ??= $name;
                } else {
                    $dynamic[] = $name.':'.$token->line;
                }
            }

            // ApiError::method(arguments)
            if ($token->text === 'ApiError' && ($tokens[$index + 1]->text ?? '') === '::' && isset($positions[$tokens[$index + 2]->text ?? '']) && ($tokens[$index + 3]->text ?? '') === '(') {
                $position = $positions[$tokens[$index + 2]->text];
                $arguments = [[]];
                $depth = 0;

                for ($cursor = $index + 4; $cursor < count($tokens); $cursor++) {
                    $text = $tokens[$cursor]->text;

                    if (in_array($text, ['(', '[', '{'], true)) {
                        $depth++;
                    } elseif (in_array($text, [')', ']', '}'], true)) {
                        if ($depth === 0) {
                            break;
                        }

                        $depth--;
                    } elseif ($text === ',' && $depth === 0) {
                        $arguments[] = [];

                        continue;
                    }

                    $arguments[array_key_last($arguments)][] = $tokens[$cursor];
                }

                $code = $arguments[$position] ?? [];

                if (count($code) === 1 && $code[0]->id === T_CONSTANT_ENCAPSED_STRING) {
                    $literal[trim($code[0]->text, "'\"")] ??= $name;
                } else {
                    $dynamic[] = $name.':'.$token->line;
                }
            }
        }
    }

    ksort($literal);

    return ['literal' => $literal, 'dynamic' => $dynamic];
}
