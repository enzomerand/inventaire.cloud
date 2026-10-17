<?php

declare(strict_types=1);

use App\Docs\CurlExample;
use App\Docs\OpenApi;
use App\Docs\SchemaDescriber;

require_once __DIR__.'/../../Support/contract.php';

/*
 * What the page draws from the schemas and the operations of the document: the label of a type, the facts about a value, the table
 * of the properties, and the command that calls an operation.
 */

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * The types, as the text a person reads, of a schema written here (a link to a schema is written `[Name]`).
 *
 * @param  array<string, mixed>  $schema
 */
function docsTypeOf(array $schema): string
{
    $tokens = (new SchemaDescriber(app(OpenApi::class)))->type($schema);

    return trim((string) preg_replace('/\s+/', ' ', implode('', array_map(fn (array $token): string => $token['schema'] !== null ? '['.$token['text'].']' : $token['text'], $tokens))));
}

describe('the type of a value', function (): void {
    it('is the word of its type', function (array $schema, string $expected): void {
        expect(docsTypeOf($schema))->toBe($expected);
    })->with([
        'a string' => [['type' => 'string'], 'string'],
        'a string with a format' => [['type' => 'string', 'format' => 'date-time'], 'string (date-time)'],
        'an integer' => [['type' => 'integer', 'minimum' => 1], 'integer'],
        'a boolean' => [['type' => 'boolean'], 'boolean'],
        'an object' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], 'object'],
        'something with properties and no type' => [['properties' => ['a' => ['type' => 'string']]], 'object'],
        'an enumeration with no type' => [['enum' => ['a', 'b']], 'string'],
        'nothing in particular' => [[], 'any'],
        'a string or null' => [['type' => ['string', 'null']], 'string | null'],
        'a list of strings' => [['type' => 'array', 'items' => ['type' => 'string']], 'array of string'],
        'a list' => [['type' => 'array'], 'array'],
        'a list or null' => [['type' => ['array', 'null'], 'items' => ['type' => 'integer']], 'array of integer | null'],
        'a map of numbers' => [['type' => 'object', 'additionalProperties' => ['type' => 'integer']], 'map of integer'],
        'one of several' => [['oneOf' => [['type' => 'string'], ['type' => 'boolean'], ['type' => 'null']]], 'string | boolean | null'],
        'any of several' => [['anyOf' => [['type' => 'null'], ['type' => 'number']]], 'null | number'],
    ]);

    it('is a link to the schema it names', function (): void {
        expect(docsTypeOf(['$ref' => '#/components/schemas/Workspace']))->toBe('[Workspace]')
            ->and(docsTypeOf(['$ref' => '#/components/schemas/Workspace', 'description' => 'Mine']))->toBe('[Workspace]')
            ->and(docsTypeOf(['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Item']]))->toBe('array of [Item]')
            ->and(docsTypeOf(['oneOf' => [['$ref' => '#/components/schemas/FieldRef'], ['type' => 'null']]]))->toBe('[FieldRef] | null')
            ->and(docsTypeOf(['type' => 'object', 'additionalProperties' => ['$ref' => '#/components/schemas/ItemValue']]))->toBe('map of [ItemValue]');
    });

    it('is the schema an allOf is made of when it names one: the rest only adds facts', function (): void {
        expect(docsTypeOf(['allOf' => [['$ref' => '#/components/schemas/Inventory'], ['type' => 'object', 'required' => ['fields']]]]))->toBe('[Inventory]')
            ->and(docsTypeOf(['allOf' => [['type' => 'object'], ['type' => 'object']]]))->toBe('object & object')
            ->and(docsTypeOf(['allOf' => [['$ref' => '#/components/schemas/A'], ['$ref' => '#/components/schemas/B']]]))->toBe('[A] & [B]');
    });
});

describe('the facts about a value', function (): void {
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    function docsDetailsOf(array $schema): array
    {
        return (new SchemaDescriber(app(OpenApi::class)))->details($schema);
    }

    it('are its allowed values, its default and its limits', function (): void {
        expect(docsDetailsOf(['type' => 'string', 'enum' => ['comma', 'tab', null], 'default' => 'comma']))->toBe(['One of: comma, tab, null', 'Default: comma'])
            ->and(docsDetailsOf(['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20]))->toBe(['Default: 20', 'Minimum: 1', 'Maximum: 50'])
            ->and(docsDetailsOf(['type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^a+$', 'examples' => ['aa']]))
            ->toBe(['Minimum length: 1', 'Maximum length: 100', 'Pattern: ^a+$', 'Example: aa'])
            ->and(docsDetailsOf(['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string', 'enum' => ['a', 'b']]]))
            ->toBe(['Minimum items: 1', 'Maximum items: 3', 'Each item is one of: a, b'])
            ->and(docsDetailsOf(['const' => 'new']))->toBe(['Always: new'])
            ->and(docsDetailsOf(['type' => 'boolean', 'default' => false]))->toBe(['Default: false']);
    });

    it('are in the language of the request', function (): void {
        $this->useLanguage('fr');

        expect(docsDetailsOf(['type' => 'string', 'enum' => ['a', 'b'], 'default' => 'a', 'maxLength' => 5]))->toBe(['Une valeur parmi : a, b', 'Par défaut : a', 'Longueur maximale : 5']);
    });

    it('come from the schema a name points to for its allowed values only: the limits are on the entry of that schema', function (): void {
        expect(docsDetailsOf(['$ref' => '#/components/schemas/ExportFormat']))->toBe(['One of: xlsx, ods, csv, json, pdf, zip'])
            ->and(docsDetailsOf(['$ref' => '#/components/schemas/Ulid']))->toBe([])
            ->and(docsDetailsOf(['$ref' => '#/components/schemas/ExportScope', 'default' => 'all']))->toContain('Default: all')
            ->and(docsDetailsOf(['allOf' => [['$ref' => '#/components/schemas/ExportScope']], 'default' => 'all']))->toContain('Default: all')->toContain('One of: all, view');
    });
});

describe('the description of a value', function (): void {
    it('is its own, else the one of the schema it names', function (): void {
        $describer = new SchemaDescriber(app(OpenApi::class));

        expect($describer->description(['type' => 'string', 'description' => 'Mine']))->toBe('Mine')
            ->and($describer->description(['$ref' => '#/components/schemas/Role']))->toContain('What a user may do')
            ->and($describer->description(['$ref' => '#/components/schemas/Role', 'description' => 'Own']))->toBe('Own')
            ->and($describer->description(['allOf' => [['$ref' => '#/components/schemas/Role']]]))->toContain('What a user may do')
            ->and($describer->description(['type' => 'string']))->toBe('');
    });
});

describe('the table of the properties of an object', function (): void {
    it('has a row for each property, with its type, whether it is required and what it is', function (): void {
        $rows = (new SchemaDescriber(app(OpenApi::class)))->rows(['type' => 'object', 'required' => ['name'], 'properties' => [
            'name' => ['type' => 'string', 'description' => 'The name.', 'maxLength' => 10],
            'role' => ['$ref' => '#/components/schemas/Role'],
        ]]);

        expect($rows)->toHaveCount(2)
            ->and($rows[0])->toMatchArray(['name' => 'name', 'required' => true, 'description' => 'The name.', 'details' => ['Maximum length: 10']])
            ->and($rows[1])->toMatchArray(['name' => 'role', 'required' => false])
            ->and($rows[1]['description'])->toContain('What a user may do')
            ->and($rows[1]['type'])->toBe([['text' => 'Role', 'schema' => 'Role']]);
    });

    it('lists the properties of the schemas it is made of, the required ones of every part', function (): void {
        $rows = (new SchemaDescriber(app(OpenApi::class)))->rows(['$ref' => '#/components/schemas/TemplateSummary']);
        $names = array_column($rows, 'name');

        expect($names)->toContain('slug', 'category', 'fields_count')
            ->and(collect($rows)->firstWhere('name', 'fields_count')['required'])->toBeTrue();
    });

    it('goes one level into what is written in place, and no further, and only links the schemas that have a name', function (): void {
        $rows = (new SchemaDescriber(app(OpenApi::class)))->rows(['type' => 'object', 'properties' => [
            'options' => ['type' => 'object', 'required' => ['max'], 'properties' => [
                'max' => ['type' => 'integer'],
                'deep' => ['type' => 'object', 'properties' => ['deeper' => ['type' => 'string']]],
            ]],
            'mapping' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['column' => ['type' => 'integer']]]],
            'owner' => ['$ref' => '#/components/schemas/User'],
        ]]);

        expect(array_column($rows, 'name'))->toBe(['options', 'options.max', 'options.deep', 'mapping', 'mapping[].column', 'owner'])
            ->and(collect($rows)->firstWhere('name', 'options.max')['required'])->toBeTrue();
    });

    it('is empty for a schema that has no properties', function (): void {
        $describer = new SchemaDescriber(app(OpenApi::class));

        expect($describer->rows(['type' => 'string']))->toBe([])
            ->and($describer->rows(['$ref' => '#/components/schemas/Ulid']))->toBe([])
            ->and($describer->rows(['type' => 'object', 'additionalProperties' => ['type' => 'string']]))->toBe([]);
    });
});

describe('a schema of the list', function (): void {
    it('says what it is made of and holds its properties', function (): void {
        $schema = (new SchemaDescriber(app(OpenApi::class)))->describe('Template', contractSpec()['components']['schemas']['Template']);

        expect($schema)->toMatchArray(['name' => 'Template', 'extends' => ['TemplateBase']])
            ->and(array_column($schema['rows'], 'name'))->toContain('slug', 'description', 'fields', 'examples');
    });

    it('gives each shape of a schema that is one of several', function (): void {
        $schema = (new SchemaDescriber(app(OpenApi::class)))->describe('RevisionChange', contractSpec()['components']['schemas']['RevisionChange']);

        expect($schema['variants'])->toHaveCount(2)
            ->and($schema['variants'][0]['title'])->not->toBe('')
            ->and(array_column($schema['variants'][0]['rows'], 'name'))->toContain('field', 'before', 'after');
    });

    it('is a type and its facts when it has no properties', function (): void {
        $schema = (new SchemaDescriber(app(OpenApi::class)))->describe('Ulid', contractSpec()['components']['schemas']['Ulid']);

        expect($schema['rows'])->toBe([])->and($schema['variants'])->toBe([])
            ->and($schema['type'])->toBe([['text' => 'string', 'schema' => null]])
            ->and($schema['details'])->toContain('Pattern: ^[0-9a-z]{26}$');
    });
});

describe('the command of an operation', function (): void {
    /**
     * The command for an operation of the document.
     */
    function docsCurlFor(string $operationId): string
    {
        return (new CurlExample(app(OpenApi::class)))->for(contractOperations()[$operationId]);
    }

    it('is one line for a call with nothing to send', function (): void {
        expect(docsCurlFor('getCurrentUser'))->toBe('curl -H "Authorization: Bearer inv_…" http://localhost/api/v1/me')
            ->and(docsCurlFor('deleteWorkspace'))->toBe('curl -X DELETE -H "Authorization: Bearer inv_…" http://localhost/api/v1/workspaces/WORKSPACE_ID');
    });

    it('names a public operation with no token', function (): void {
        expect(docsCurlFor('listTemplates'))->toBe('curl http://localhost/api/v1/templates')
            ->and(docsCurlFor('getTemplate'))->toBe('curl http://localhost/api/v1/templates/SLUG');
    });

    it('has the identifiers of the path as placeholders without braces, which curl would read as a list', function (): void {
        foreach (contractOperations() as $id => $operation) {
            $command = (new CurlExample(app(OpenApi::class)))->for($operation);
            $address = trim((string) preg_replace('/^.*\s/s', '', $command));

            expect($command)->toStartWith('curl')
                ->and($address)->toStartWith('http://localhost/api/v1/')->not->toContain('{')->not->toContain('}');
        }

        expect(docsCurlFor('getAttachmentThumbnail'))->toContain('/inventories/INVENTORY_ID/items/ITEM_ID/attachments/ATTACHMENT_ID/thumbnail');
    });

    it('sends a skeleton of the body made of the required properties, on several lines when it is long', function (): void {
        expect(docsCurlFor('createWorkspace'))->toBe("curl \\\n  -X POST \\\n  -H \"Authorization: Bearer inv_…\" \\\n  -H \"Content-Type: application/json\" \\\n  -d '{\"name\":\"…\"}' \\\n  http://localhost/api/v1/workspaces")
            ->and(docsCurlFor('createField'))->toContain('-d \'{"type":"text","name":"…"}\'')
            ->and(docsCurlFor('startImport'))->toContain('"mapping":[{"column"')
            ->and(docsCurlFor('createExport'))->toContain('-d \'{"format":"xlsx"}\'');
    });

    it('sends a file for a form', function (): void {
        expect(docsCurlFor('uploadAttachment'))->toContain("-F 'file=@FILE'")->not->toContain('Content-Type')
            ->and(docsCurlFor('createImport'))->toContain("-F 'file=@FILE'");
    });

    it('quotes each field of a form as one word for the shell, whatever the value holds', function (): void {
        $operation = [
            'method' => 'post', 'path' => '/things', 'ability' => 'write', 'parameters' => [],
            'operation' => ['requestBody' => ['content' => ['multipart/form-data' => ['schema' => [
                'type' => 'object',
                'required' => ['note'],
                'properties' => ['note' => ['type' => 'string', 'example' => 'say "hi" $HOME `id` it\'s'], 'file' => ['type' => 'string', 'format' => 'binary']],
            ]]]]],
        ];

        $command = (new CurlExample(app(OpenApi::class)))->for($operation);

        expect($command)->toContain("-F 'note=say \"hi\" \$HOME `id` it'\\''s'");
    });

    it('is a command the shell reads as one: no quote is left open', function (): void {
        foreach (contractOperations() as $id => $operation) {
            $command = (new CurlExample(app(OpenApi::class)))->for($operation);

            expect(substr_count($command, '"') % 2)->toBe(0, $id)->and(substr_count($command, "'") % 2)->toBe(0, $id);
        }
    });
});
