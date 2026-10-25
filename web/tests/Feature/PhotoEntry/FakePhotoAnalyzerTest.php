<?php

declare(strict_types=1);

use App\PhotoEntry\ClaudePhotoAnalyzer;
use App\PhotoEntry\FakePhotoAnalyzer;
use App\PhotoEntry\PhotoAnalyzer;
use App\PhotoEntry\PhotoFields;
use App\PhotoEntry\Proposals;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/../../Support/photo-entry.php';

/*
 * The analyzer that makes proposals up (the tests, and a development machine without a key), and which one the application is given.
 */

uses(RefreshDatabase::class);

describe('the fake analyzer', function (): void {
    it('makes up a proposal for every field of every type, and all of them survive the checks of the fields', function (): void {
        $case = photoCase();
        $inventory = $case['inventory'];
        fieldIn($inventory, 'text', 'Name');
        fieldIn($inventory, 'text', 'Tiny', ['max_length' => 4]);
        fieldIn($inventory, 'textarea', 'Notes');
        fieldIn($inventory, 'number', 'Count');
        fieldIn($inventory, 'number', 'Weight', ['decimals' => 2, 'min' => '10', 'max' => '20']);
        fieldIn($inventory, 'money', 'Price', ['currency' => 'EUR']);
        fieldIn($inventory, 'money', 'Yen', ['currency' => 'JPY']);
        fieldIn($inventory, 'percentage', 'Share');
        fieldIn($inventory, 'percentage', 'Whole share', ['decimals' => 0]);
        fieldIn($inventory, 'boolean', 'Working');
        fieldIn($inventory, 'date', 'Bought');
        fieldIn($inventory, 'datetime', 'Seen');
        fieldIn($inventory, 'time', 'Opening');
        fieldIn($inventory, 'select', 'Condition', ['choices' => [['label' => 'New'], ['label' => 'Used']]]);
        fieldIn($inventory, 'multiselect', 'Extras', ['choices' => [['label' => 'Case'], ['label' => 'Charger'], ['label' => 'Cable']]]);
        fieldIn($inventory, 'tags', 'Tags');
        fieldIn($inventory, 'url', 'Page');
        fieldIn($inventory, 'email', 'Mail');
        fieldIn($inventory, 'phone', 'Phone');
        fieldIn($inventory, 'rating', 'Grade', ['max' => 3]);
        fieldIn($inventory, 'color', 'Color');
        foreach (['any', 'ean13', 'ean8', 'upca', 'isbn10', 'isbn13'] as $format) {
            fieldIn($inventory, 'barcode', "Code {$format}", ['format' => $format]);
        }
        fieldIn($inventory, 'photo', 'Pictures');

        $fields = $inventory->fields()->get();
        $eligible = app(PhotoFields::class)->eligible($fields);
        $reading = (new FakePhotoAnalyzer)->analyze(['jpeg'], $eligible, 'en');
        $normalized = app(Proposals::class)->normalize($fields, $reading->proposals);

        expect($reading->proposals)->toHaveCount(count($eligible))
            ->and($normalized)->toHaveCount(count($eligible))
            ->and($reading->model)->toBe('fake')
            ->and($reading->inputTokens)->toBe(0)
            ->and($reading->outputTokens)->toBe(0);
    });

    it('always makes up the same, and goes round the three levels of confidence', function (): void {
        $case = photoCase();
        foreach (['A', 'B', 'C', 'D'] as $name) {
            fieldIn($case['inventory'], 'text', $name);
        }
        $eligible = app(PhotoFields::class)->eligible($case['inventory']->fields()->get());

        $first = (new FakePhotoAnalyzer)->analyze(['jpeg'], $eligible, 'en');
        $second = (new FakePhotoAnalyzer)->analyze(['jpeg', 'jpeg'], $eligible, 'fr');

        expect(array_column($first->proposals, 'values'))->toBe(array_column($second->proposals, 'values'))
            ->and(array_column($first->proposals, 'confidence'))->toBe(['high', 'medium', 'low', 'high'])
            ->and($first->proposals[0]['evidence'])->toContain('Made up')
            ->and($second->proposals[0]['evidence'])->toContain('2 photos');
    });

    it('leaves out a field it has nothing to say for, such as a choice field with no choice', function (): void {
        $case = photoCase();
        $select = fieldIn($case['inventory'], 'select', 'Condition', ['choices' => [['label' => 'New']]]);
        $select->forceFill(['options' => ['choices' => []]]);

        $reading = (new FakePhotoAnalyzer)->analyze(['jpeg'], [$select], 'en');

        expect($reading->proposals)->toBe([]);
    });
});

describe('the analyzer the application is given', function (): void {
    it('is Claude', function (): void {
        expect(app(PhotoAnalyzer::class))->toBeInstanceOf(ClaudePhotoAnalyzer::class);
    });

    it('is still Claude when the fake is asked for outside a development machine', function (): void {
        config(['services.anthropic.fake' => true]);

        foreach (['testing', 'production', 'staging'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            expect(app(PhotoAnalyzer::class))->toBeInstanceOf(ClaudePhotoAnalyzer::class);
        }
    });

    it('is the fake on a local machine that asks for it, and Claude when it does not', function (): void {
        app()->detectEnvironment(fn (): string => 'local');

        config(['services.anthropic.fake' => false]);
        expect(app(PhotoAnalyzer::class))->toBeInstanceOf(ClaudePhotoAnalyzer::class);

        config(['services.anthropic.fake' => true]);
        expect(app(PhotoAnalyzer::class))->toBeInstanceOf(FakePhotoAnalyzer::class);
    });
});
