<?php

declare(strict_types=1);

require_once __DIR__.'/../../Support/templates.php';

/*
 * The public endpoints of the catalogue of templates. The fixtures of tests/Fixtures/templates are the catalogue.
 */

beforeEach(fn () => templateFixtures());

describe('the list of templates', function (): void {
    it('is public and in JSON, even without an Accept header', function (): void {
        $this->call('GET', '/api/v1/templates')->assertOk()->assertHeader('Content-Type', 'application/json')->assertJsonCount(4, 'data');
    });

    it('gives a card for each template, by category and then by name, in the language of the request', function (): void {
        $english = $this->getJson('/api/v1/templates', ['Accept-Language' => 'en'])->assertOk();
        $french = $this->getJson('/api/v1/templates', ['Accept-Language' => 'fr'])->assertOk();

        expect($english->json('data.*.slug'))->toBe(['sample-home', 'sample-shop', 'sample-records', 'sample-tools'])
            ->and($french->json('data.*.slug'))->toBe(['sample-home', 'sample-shop', 'sample-tools', 'sample-records'])
            ->and($english->json('data.1'))->toBe([
                'slug' => 'sample-shop',
                'category' => 'business',
                'category_label' => 'Business and retail',
                'icon' => 'building-store',
                'name' => 'Shop stock',
                'summary' => 'Track your products, quantities and prices, with the barcode of each product.',
                'fields_count' => 8,
                'url' => 'http://localhost/en/templates/sample-shop',
            ])
            ->and($french->json('data.1'))->toBe([
                'slug' => 'sample-shop',
                'category' => 'business',
                'category_label' => 'Commerce et entreprise',
                'icon' => 'building-store',
                'name' => 'Stock de boutique',
                'summary' => 'Suivez vos références, vos quantités et vos prix, avec le code-barres de chaque produit.',
                'fields_count' => 8,
                'url' => 'http://localhost/templates/sample-shop',
            ]);
    });

    it('is empty when the catalogue is', function (): void {
        $folder = templateFolder();
        templateCatalogueFrom($folder);

        $this->getJson('/api/v1/templates')->assertOk()->assertExactJson(['data' => []]);

        templateRemoveFolder($folder);
    });

    it('answers in the language of the instance when the request asks for none that is supported', function (): void {
        expect($this->getJson('/api/v1/templates', ['Accept-Language' => 'de'])->json('data.0.name'))->toBe('Inventaire du logement');
    });

    it('builds the addresses from APP_URL, never from the Host header', function (): void {
        config(['app.url' => 'https://inventaire.example/']);

        $response = $this->getJson('/api/v1/templates', ['Host' => 'evil.example', 'Accept-Language' => 'fr']);

        expect($response->json('data.0.url'))->toBe('https://inventaire.example/templates/sample-home');
    });
});

describe('one template', function (): void {
    it('gives its description, its fields and its examples in the language of the request', function (): void {
        $response = $this->getJson('/api/v1/templates/sample-tools', ['Accept-Language' => 'en'])->assertOk();

        expect($response->json('data'))->toBe([
            'slug' => 'sample-tools',
            'category' => 'hobby',
            'category_label' => 'Hobbies and workshop',
            'icon' => 'tools',
            'name' => 'Tools',
            'summary' => 'Find every tool, its condition and who you lent it to.',
            'description' => [
                'A workshop inventory so you never buy a tool you already own.',
                'Write loans in the field provided: no need to remember who has what.',
            ],
            'fields' => [
                ['key' => 'name', 'name' => 'Tool', 'type' => 'text', 'type_label' => 'Text', 'required' => true, 'options' => []],
                ['key' => 'brand', 'name' => 'Brand', 'type' => 'text', 'type_label' => 'Text', 'required' => false, 'options' => ['max_length' => 80]],
                ['key' => 'power', 'name' => 'Power', 'type' => 'select', 'type_label' => 'Choice', 'required' => false, 'options' => ['choices' => [
                    ['key' => 'hand', 'label' => 'Hand', 'color' => 'gray'],
                    ['key' => 'corded', 'label' => 'Corded', 'color' => 'blue'],
                    ['key' => 'battery', 'label' => 'Battery', 'color' => 'yellow'],
                ]]],
                ['key' => 'lent_to', 'name' => 'Lent to', 'type' => 'text', 'type_label' => 'Text', 'required' => false, 'options' => []],
                ['key' => 'condition', 'name' => 'Condition', 'type' => 'rating', 'type_label' => 'Rating', 'required' => false, 'options' => []],
                ['key' => 'photo', 'name' => 'Photo', 'type' => 'photo', 'type_label' => 'Photo', 'required' => false, 'options' => []],
            ],
            'examples' => [
                ['name' => 'Jigsaw', 'brand' => 'Bosch', 'power' => 'corded', 'condition' => 4],
                ['name' => 'Phillips screwdriver', 'power' => 'hand', 'lent_to' => 'Marie', 'condition' => 2],
            ],
            'url' => 'http://localhost/en/templates/sample-tools',
        ]);
    });

    it('writes the options of a field without options as an empty object', function (): void {
        $body = $this->getJson('/api/v1/templates/sample-tools', ['Accept-Language' => 'en'])->assertOk()->getContent();

        expect($body)->toContain('"key":"name","name":"Tool","type":"text","type_label":"Text","required":true,"options":{}');
    });

    it('is in French when the request asks for it', function (): void {
        $response = $this->getJson('/api/v1/templates/sample-tools', ['Accept-Language' => 'fr'])->assertOk();

        expect($response->json('data.name'))->toBe('Outils')
            ->and($response->json('data.fields.2.options.choices.2'))->toBe(['key' => 'battery', 'label' => 'Sur batterie', 'color' => 'yellow'])
            ->and($response->json('data.fields.2.type_label'))->toBe('Choix')
            ->and($response->json('data.examples.1.lent_to'))->toBe('Marie')
            ->and($response->json('data.url'))->toBe('http://localhost/templates/sample-tools');
    });

    it('is a 404 in JSON for a slug that does not exist', function (string $slug): void {
        $this->getJson('/api/v1/templates/'.$slug)->assertNotFound()->assertJson(['code' => 'template_not_found']);
    })->with(['unknown' => 'nothing', 'capitals' => 'SAMPLE-TOOLS', 'a file name' => 'sample-tools.json']);

    it('is a 404 for a slug that is a path', function (): void {
        $this->getJson('/api/v1/templates/..%2Fsample-tools')->assertNotFound();
    });
});

describe('the catalogue endpoints', function (): void {
    it('answer without a session', function (): void {
        $response = $this->getJson('/api/v1/templates')->assertOk();

        expect($response->headers->getCookies())->toBe([]);
    });
});
