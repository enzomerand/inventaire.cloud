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
 * The `components` of the document as JSON Schema reads them (objects, not arrays), decoded once: every schema validated below
 * carries them, so that its `#/components/...` references find their target.
 */
function contractComponents(): stdClass
{
    static $components = null;

    return $components ??= json_decode((string) json_encode(contractSpec()['components'], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
}

/**
 * Checks a decoded JSON value against a Schema Object of the document (OpenAPI 3.1 schemas are JSON Schema 2020-12).
 *
 * @param  array<string, mixed>  $schema  The Schema Object, its `$ref` not followed.
 * @return list<string> What is wrong with the value, empty when it fits.
 */
function contractViolations(mixed $value, array $schema): array
{
    $root = json_decode((string) json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

    if (! $root instanceof stdClass) {
        $root = new stdClass;
    }

    $root->components = contractComponents();

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
 * body is of a documented media type and, for JSON, fits the schema the document gives for that status.
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

    $schema = $content[$actual]['schema'] ?? null;

    if ($actual !== 'application/json' || ! is_array($schema)) {
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
