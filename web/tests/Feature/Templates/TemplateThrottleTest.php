<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/inventories.php';
require_once __DIR__.'/../../Support/templates.php';

uses(RefreshDatabase::class);

/*
 * The pace of the public side of the catalogue: the pages (every language), the sitemap and the endpoints of the API share
 * one limit, counted for each signed-in person on their own and for each network when nobody is signed in.
 */

beforeEach(function (): void {
    templateFixtures();
    config(['app.indexable' => true, 'inventories.templates_per_minute' => 2]);
});

afterEach(function (): void {
    foreach (glob(storage_path('framework/testing/templates-*')) ?: [] as $folder) {
        templateRemoveFolder($folder);
    }
});

describe('the public pages and the sitemap', function (): void {
    it('are limited in pace, like the endpoints of the API', function (string $path): void {
        $this->get($path)->assertOk();
        $this->get($path)->assertOk();
        $this->get($path)->assertStatus(429);
    })->with(['/templates', '/en/templates', '/templates/sample-tools', '/en/templates/sample-tools', '/sitemap.xml']);

    it('share their limit with each other and with the API, for a network', function (): void {
        $this->get('/templates')->assertOk();
        $this->getJson('/api/v1/templates')->assertOk();

        foreach (['/templates', '/en/templates/sample-home', '/sitemap.xml'] as $path) {
            $this->get($path)->assertStatus(429);
        }

        $this->getJson('/api/v1/templates/sample-tools')->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get('/templates')->assertOk();
    });

    it('do not limit what the pace of the visitors has nothing to do with: the other pages, and the robots', function (): void {
        foreach (range(1, 5) as $_) {
            $this->get('/')->assertOk();
            $this->get('/robots.txt')->assertOk();
        }
    });
});

describe('the pace', function (): void {
    it('is counted for each person who is signed in, and not for the network they share with others', function (): void {
        $ada = inventoryPerson('Ada');
        $bob = inventoryPerson('Bob');

        $this->actingAs($ada)->get('/templates')->assertOk();
        $this->actingAs($ada)->getJson('/api/v1/templates')->assertOk();
        $this->actingAs($ada)->get('/templates')->assertStatus(429);

        // Same network, another person: their own count.
        $this->actingAs($bob)->get('/templates')->assertOk();
        $this->actingAs($bob)->get('/en/templates')->assertOk();
        $this->actingAs($bob)->get('/templates')->assertStatus(429);
    });

    it('does not stop a person who is signed in when the network they are on is used up by visitors', function (): void {
        foreach (range(1, 2) as $_) {
            $this->get('/templates')->assertOk();
        }

        $this->get('/templates')->assertStatus(429);

        $this->actingAs(inventoryPerson())->get('/templates')->assertOk();
    });
});
