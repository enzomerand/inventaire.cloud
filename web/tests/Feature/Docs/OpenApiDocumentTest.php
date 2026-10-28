<?php

declare(strict_types=1);

use App\Auth\TokenAbility;
use App\Docs\OpenApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/contract.php';

/*
 * The document (resources/openapi/v1.yaml) is well formed OpenAPI 3.1, and App\Docs\OpenApi reads it, keeps it in the cache and
 * rewrites its servers. What the document says of the API is held to the routes (OpenApiRoutesTest.php) and to real answers
 * (ApiContractTest.php).
 */

/**
 * Every Response Object of the document, with where it is: those of the operations and the shared ones.
 *
 * @return array<string, array<string, mixed>>
 */
function contractResponseObjects(): array
{
    $found = [];

    foreach (contractOperations() as $id => $operation) {
        foreach ($operation['operation']['responses'] as $status => $response) {
            $found["{$id} {$status}"] = $response;
        }
    }

    foreach (contractSpec()['components']['responses'] ?? [] as $name => $response) {
        $found["components.responses.{$name}"] = $response;
    }

    return $found;
}

describe('the document', function (): void {
    it('is OpenAPI 3.1 with a bearer token as its default requirement', function (): void {
        $spec = contractSpec();

        expect($spec['openapi'])->toStartWith('3.1.')
            ->and($spec['info'])->toHaveKeys(['title', 'version', 'description'])
            ->and($spec['security'])->toBe([['bearer' => []]])
            ->and($spec['components']['securitySchemes']['bearer'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer']);
    });

    it('has no empty mapping, which PHP would write as a list in the JSON', function (): void {
        expect((string) file_get_contents(app(OpenApi::class)->path()))->not->toContain('{}');
    });

    it('gives every operation an id, a tag it declares, a summary and answers', function (): void {
        $tags = array_column(app(OpenApi::class)->tags(), 'name');
        $ids = [];

        foreach (app(OpenApi::class)->operations() as $operation) {
            $id = $operation['operationId'];

            expect($id)->toMatch('/^[a-z][A-Za-z0-9]+$/', "operationId {$id}")
                ->and($ids)->not->toContain($id)
                ->and(in_array($operation['tag'], $tags, true))->toBeTrue("the tag of {$id} is declared")
                ->and($operation['summary'])->not->toBe('', "the summary of {$id}")
                ->and($operation['operation']['responses'])->not->toBe([], "the answers of {$id}");

            $ids[] = $id;
        }

        expect($ids)->toHaveCount(60)->and($tags)->toHaveCount(count(array_unique($tags)));
    });

    it('names for each operation an ability that exists', function (): void {
        foreach (contractOperations() as $id => $operation) {
            if ($operation['ability'] !== null) {
                expect(TokenAbility::tryFrom($operation['ability']))->not->toBeNull("the ability of {$id}");
            }
        }
    });

    it('declares the parameters of the path of every operation, and no other path parameter', function (): void {
        foreach (contractOperations() as $id => $operation) {
            preg_match_all('/\{([^}]+)\}/', $operation['path'], $matches);
            $declared = array_column(array_filter($operation['parameters'], fn (array $parameter): bool => $parameter['in'] === 'path'), 'name');
            sort($declared);
            $named = $matches[1];
            sort($named);

            expect($declared)->toBe($named, "the path parameters of {$id}");

            foreach ($operation['parameters'] as $parameter) {
                expect($parameter['in'] !== 'path' || ($parameter['required'] ?? false) === true)->toBeTrue("{$parameter['name']} of {$id} is required");
            }
        }
    });

    it('documents the answers every operation can give besides its success', function (): void {
        foreach (contractOperations() as $id => $operation) {
            $statuses = array_map('strval', array_keys($operation['operation']['responses']));

            expect(in_array('429', $statuses, true))->toBeTrue("{$id} documents the pace limit");

            if ($operation['ability'] !== null) {
                expect(in_array('401', $statuses, true))->toBeTrue("{$id} documents the missing token")
                    ->and(in_array('403', $statuses, true))->toBeTrue("{$id} documents the missing ability");
            }

            expect(array_filter($statuses, fn (string $status): bool => $status[0] === '2'))->not->toBe([], "{$id} documents a success");
        }
    });

    it('resolves every reference inside itself', function (): void {
        $api = app(OpenApi::class);
        $unresolved = [];
        $walk = function (mixed $node, string $where) use (&$walk, $api, &$unresolved): void {
            if (! is_array($node)) {
                return;
            }

            if (isset($node['$ref']) && is_string($node['$ref'])) {
                try {
                    $api->resolve(['$ref' => $node['$ref']]);
                } catch (RuntimeException) {
                    $unresolved[] = "{$where} -> {$node['$ref']}";
                }
            }

            foreach ($node as $key => $child) {
                $walk($child, "{$where}/{$key}");
            }
        };

        $walk(contractSpec(), '');

        expect($unresolved)->toBe([]);
    });
});

describe('the answers of the document', function (): void {
    it('never put anything next to a $ref but a summary or a description (a Reference Object of OpenAPI 3.1)', function (): void {
        $wrong = [];

        foreach (contractResponseObjects() as $where => $response) {
            if (isset($response['$ref']) && array_diff(array_keys($response), ['$ref', 'summary', 'description']) !== []) {
                $wrong[] = $where;
            }
        }

        expect($wrong)->toBe([]);
    });

    it('write the codes they can carry in a Response Object of their own', function (): void {
        $withCodes = 0;

        foreach (contractResponseObjects() as $where => $response) {
            if (! array_key_exists('x-codes', $response)) {
                continue;
            }

            $withCodes++;
            $codes = array_column($response['x-codes'], 'code');

            expect($response)->not->toHaveKey('$ref')->toHaveKeys(['description', 'content'])
                ->and($response['x-codes'])->not->toBe([], $where)
                ->and($codes)->toHaveCount(count(array_unique($codes)), "{$where} lists a code twice");

            foreach ($response['x-codes'] as $code) {
                expect($code['code'])->toMatch('/^[a-z][a-z0-9_]*$/', $where)->and($code['description'])->toBeString()->not->toBe('', $where);
            }
        }

        expect($withCodes)->toBeGreaterThan(90);
    });

    it('carry the headers of a pace limit with the shared definitions', function (): void {
        foreach (contractOperations() as $id => $operation) {
            $response = $operation['operation']['responses']['429'];

            expect(array_keys($response['headers']))->toBe(['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset'], $id);

            foreach ($response['headers'] as $header) {
                expect(app(OpenApi::class)->resolve($header))->toHaveKeys(['description', 'schema']);
            }
        }
    });

    it('give their examples a shape their schema accepts', function (): void {
        $checked = 0;

        foreach (contractResponseObjects() as $where => $response) {
            foreach (app(OpenApi::class)->resolve($response)['content'] ?? [] as $content) {
                if (isset($content['example'], $content['schema'])) {
                    $checked++;
                    $example = json_decode((string) json_encode($content['example']), false);

                    expect(contractViolations($example, $content['schema']))->toBe([], "the example of {$where}");
                }
            }
        }

        expect($checked)->toBeGreaterThan(20);
    });
});

describe('the codes of the document', function (): void {
    /**
     * Every code the document lists, with the first answer that lists it.
     *
     * @return array<string, string>
     */
    function contractDocumentedCodes(): array
    {
        $codes = [];

        foreach (contractOperations() as $id => $operation) {
            foreach ($operation['operation']['responses'] as $status => $response) {
                foreach (app(OpenApi::class)->resolve($response)['x-codes'] ?? [] as $code) {
                    $codes[(string) $code['code']] ??= "{$id} {$status}";
                }
            }
        }

        return $codes;
    }

    it('are each written somewhere in the application: a code nobody sends is a code that was renamed or never was', function (): void {
        $source = '';

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $source .= file_get_contents($file->getPathname());
            }
        }

        $source .= (string) file_get_contents(base_path('bootstrap/app.php'));
        $unknown = array_filter(array_keys(contractDocumentedCodes()), fn (string $code): bool => ! str_contains($source, "'{$code}'"));

        expect(array_values($unknown))->toBe([], 'The document lists codes the application never sends: '.implode(', ', $unknown));
    });

    it('include every code the API writes as a literal, but those of the pages that are not part of it', function (): void {
        // Sent by the sign-in, the account, the installer and the guards of the web front: not operations of this document.
        // `token_with_session` is the guard of every operation that asks for an ability: the description of the document says it once, under "Authentication".
        // `invalid_body` and `invalid_signature` are the refusals of the webhook of Stripe (`/webhooks/stripe`), which Stripe calls and no client of the API does.
        // `already_subscribed`, `billing_disabled`, `billing_unavailable`, `interval_not_offered`, `no_billing_account` and `subscription_pending` are the refusals of the subscription of a
        // workspace (`/workspaces/{workspace}/billing/*`), which a person answers at their browser: a token cannot reach those routes, so the document has none of them.
        $outside = [
            'account_not_found', 'already_subscribed', 'billing_disabled', 'billing_unavailable', 'deletion_code_exhausted', 'deletion_code_expired',
            'deletion_code_wrong', 'interval_not_offered', 'invalid_body', 'invalid_signature', 'login_code_locked', 'no_billing_account', 'not_installed',
            'not_stateful', 'session_required', 'setup_token_required', 'subscription_pending', 'token_with_session',
        ];
        $emitted = contractEmittedCodes();
        $undocumented = array_diff(array_keys($emitted['literal']), array_keys(contractDocumentedCodes()), $outside);

        expect(array_values($undocumented))->toBe([], 'Document these codes in resources/openapi/v1.yaml (x-codes), or say here why they are not part of it: '.implode(', ', $undocumented))
            ->and(count($emitted['literal']))->toBeGreaterThan(10)
            ->and($emitted['literal'])->toHaveKeys(['token_ability_missing', 'too_many_requests', 'template_not_found']);
    });

    it('are documented on the status they come with', function (): void {
        $statuses = [];

        foreach (contractOperations() as $operation) {
            foreach ($operation['operation']['responses'] as $status => $response) {
                foreach (app(OpenApi::class)->resolve($response)['x-codes'] ?? [] as $code) {
                    $statuses[$code['code']][(int) $status] = true;
                }
            }
        }

        // One reason, one status: a code that is a 409 here and a 422 there would be a code nobody can switch on.
        $mixed = array_filter($statuses, fn (array $seen): bool => count($seen) > 1);

        expect(array_keys($mixed))->toBe([]);
    });
});

/**
 * The text of a section of the introduction, from its heading to the next one.
 */
function openApiSection(string $heading): string
{
    $description = (string) contractSpec()['info']['description'];
    $start = strpos($description, "## {$heading}");

    expect($start)->not->toBeFalse("no section {$heading}");

    $rest = substr($description, (int) $start + strlen($heading) + 3);
    $end = strpos($rest, "\n## ");

    return trim(preg_replace('/\s+/', ' ', $end === false ? $rest : substr($rest, 0, $end)) ?? '');
}

describe('the introduction of the document', function (): void {
    it('says what the abilities are not: they are independent, `write` answers what it wrote, and `manage` deletes data', function (): void {
        $abilities = openApiSection('Abilities');

        expect($abilities)->toContain('`write` and `manage` do not include `read`')
            ->and($abilities)->toContain('can hardly be used')
            ->and($abilities)->toContain('found with `GET`')
            ->and($abilities)->toContain('is the resource it wrote')
            ->and($abilities)->toContain('sees what it writes')
            ->and($abilities)->toContain('can delete a workspace or an inventory with all the data in it');
    });

    it('says which limit the rate-limit headers describe, and that the limits of the endpoints answer with Retry-After', function (): void {
        $limits = openApiSection('Rate limits');

        expect($limits)->toContain('Every request made with a token counts, those that are refused too')
            ->and($limits)->toContain('does not use it up')
            ->and($limits)->toContain('the headers describe the one with the fewest requests left')
            ->and($limits)->toContain('On a `429` they are those of the limit that was exceeded, with `Retry-After`')
            ->and($limits)->toContain('Past it the answer is `429` as well, with `Retry-After`');
    });
});

describe('OpenApi', function (): void {
    it('lists the operations in the order of the file, with their parameters resolved', function (): void {
        $operations = app(OpenApi::class)->operations();

        expect($operations)->toHaveCount(60)
            ->and($operations[0])->toMatchArray(['method' => 'get', 'path' => '/me', 'operationId' => 'getCurrentUser', 'tag' => 'Account', 'ability' => 'read']);

        $listItems = collect($operations)->firstWhere('operationId', 'listItems');

        expect(array_column($listItems['parameters'], 'name'))->toContain('inventory', 'page')
            ->and(collect($listItems['parameters'])->firstWhere('name', 'inventory'))->toHaveKey('schema');
    });

    it('groups the operations under the tags, in the order they are declared', function (): void {
        $groups = app(OpenApi::class)->operationsByTag();

        expect(array_keys($groups))->toBe(array_column(app(OpenApi::class)->tags(), 'name'))
            ->and(count(array_merge(...array_values($groups))))->toBe(60);
    });

    it('follows a reference, what is written next to it winning over what it points to', function (): void {
        $api = app(OpenApi::class);

        expect($api->resolve(['$ref' => '#/components/schemas/Ulid'])['type'])->toBe('string')
            ->and($api->resolve(['$ref' => '#/components/schemas/Ulid', 'description' => 'Mine'])['description'])->toBe('Mine')
            ->and($api->schemaName(['$ref' => '#/components/schemas/Ulid']))->toBe('Ulid')
            ->and($api->schemaName(['$ref' => '#/components/responses/NotFound']))->toBeNull()
            ->and($api->schemaName(['type' => 'string']))->toBeNull();
    });

    it('refuses a reference that points to nothing, outside the document, or round in circles', function (): void {
        $api = new OpenApi(contractScratchSpec("a: 1\nb:\n  \$ref: '#/b'\n"));

        expect(fn () => $api->resolve(['$ref' => '#/components/schemas/Nothing']))->toThrow(RuntimeException::class)
            ->and(fn () => $api->resolve(['$ref' => 'https://example.com/other.yaml#/a']))->toThrow(RuntimeException::class)
            ->and(fn () => $api->resolve(['$ref' => '#/b']))->toThrow(RuntimeException::class, 'loops');
    });

    it('keeps the parsed document in the cache next to the hash of its content, in one entry that a new content replaces', function (): void {
        Cache::flush();

        $one = "openapi: 3.1.0\ninfo:\n  title: One\n";
        $two = "openapi: 3.1.0\ninfo:\n  title: Two\n";
        $first = new OpenApi(contractScratchSpec($one));
        $second = new OpenApi(contractScratchSpec($two));

        expect($first->raw()['info']['title'])->toBe('One')
            ->and(Cache::get('openapi:v1'))->toBe(['hash' => hash('xxh128', $one), 'document' => ['openapi' => '3.1.0', 'info' => ['title' => 'One']]]);

        // The other content is read and parsed again, not taken for this one, and takes the place of the entry.
        expect($second->raw()['info']['title'])->toBe('Two')
            ->and(Cache::get('openapi:v1'))->toBe(['hash' => hash('xxh128', $two), 'document' => ['openapi' => '3.1.0', 'info' => ['title' => 'Two']]])
            ->and(array_filter(array_keys(Cache::getStore()->all()), fn (string $key): bool => str_starts_with($key, 'openapi')))->toBe(['openapi:v1']);

        // A file that is gone is read from the cache no more: the content is what is asked, so it is read first.
        expect(fn () => (new OpenApi('/no/such/file.yaml'))->raw())->toThrow(RuntimeException::class);
    });

    it('parses the file again when the entry of the cache is damaged, and replaces it', function (): void {
        $content = "openapi: 3.1.0\ninfo:\n  title: One\n";

        foreach (['plain text', ['hash' => hash('xxh128', $content)], ['hash' => hash('xxh128', $content), 'document' => 'no']] as $damaged) {
            Cache::forever('openapi:v1', $damaged);

            expect((new OpenApi(contractScratchSpec($content)))->raw()['info']['title'])->toBe('One')
                ->and(Cache::get('openapi:v1')['document']['info']['title'])->toBe('One');
        }
    });

    it('is parsed once by a process, however many times it is asked', function (): void {
        $api = app(OpenApi::class);
        $api->raw();

        Cache::flush();

        // The cache is not asked again.
        expect($api->raw())->toBe($api->raw())->and(Cache::get('openapi:v1'))->toBeNull();
    });

    it('goes on without the cache when it cannot be used, and says so', function (): void {
        Log::spy();
        Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('down'));
        Cache::shouldReceive('forever')->once()->andThrow(new RuntimeException('down'));

        $document = (new OpenApi(contractScratchSpec("openapi: 3.1.0\n")))->raw();

        expect($document)->toBe(['openapi' => '3.1.0']);
        Log::shouldHaveReceived('warning')->twice();
    });

    it('refuses a file that is not a mapping', function (): void {
        expect(fn () => (new OpenApi(contractScratchSpec('plain text')))->raw())->toThrow(RuntimeException::class, 'not a mapping');
    });
});
