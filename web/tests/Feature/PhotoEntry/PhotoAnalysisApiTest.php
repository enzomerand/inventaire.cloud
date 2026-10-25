<?php

declare(strict_types=1);

use App\Jobs\AnalyzePhotos;
use App\Models\Inventory;
use App\Models\InventoryField;
use App\Models\PhotoAnalysis;
use App\PhotoEntry\Exceptions\InstanceQuotaReachedException;
use App\PhotoEntry\FakePhotoAnalyzer;
use App\PhotoEntry\PhotoAnalysisError;
use App\PhotoEntry\PhotoAnalysisQuota;
use App\PhotoEntry\PhotoAnalysisStatus;
use App\PhotoEntry\PhotoAnalyzer;
use App\Settings\InstanceSettings;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/photo-entry.php';
require_once __DIR__.'/../../Support/imagejpeg-failure.php';
require_once __DIR__.'/../../Support/tokens.php';

/*
 * The API of the photo entry: sending the photos of an object and what comes back, the refusals (the feature off, nothing to fill, the
 * limits, a photo that cannot be read), reading an analysis, and who may do what. The job that does the analysis is in AnalyzePhotosJobTest.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->useLanguage('en');
});

/**
 * A team and an inventory with fields to fill, in a workspace that uses the photo entry.
 *
 * @return array{team: array<string, mixed>, inventory: Inventory, name: InventoryField, weight: InventoryField}
 */
function photoReady(): array
{
    $case = photoCase();
    photoTurnedOn($case['team']['workspace']);

    return $case + [
        'name' => fieldIn($case['inventory'], 'text', 'Name'),
        'weight' => fieldIn($case['inventory'], 'number', 'Weight', ['decimals' => 1]),
    ];
}

/**
 * The keys of an analysis as the API gives it, in order.
 */
const PHOTO_ANALYSIS_KEYS = ['id', 'status', 'error', 'proposals', 'created_at', 'completed_at'];

describe('without signing in', function (): void {
    it('answers 401 in JSON on both endpoints', function (string $method, string $path): void {
        $case = photoReady();
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['owner']->id]);

        $this->call($method, str_replace(['{inventory}', '{analysis}'], [$case['inventory']->ulid, $analysis->ulid], $path))
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');
    })->with([
        ['POST', '/api/v1/inventories/{inventory}/photo-analyses'],
        ['GET', '/api/v1/inventories/{inventory}/photo-analyses/{analysis}'],
    ]);
});

describe('sending photos', function (): void {
    it('answers 202 with the analysis pending, and queues the job once', function (): void {
        Queue::fake();
        $case = photoReady();
        $editor = $case['team']['editor'];

        $response = photoPost($this, $editor, $case['inventory'], [photoUpload('one.jpg'), photoUpload('two.jpg')])->assertStatus(202);
        $analysis = PhotoAnalysis::query()->sole();

        expect(array_keys($response->json()))->toBe(['data'])
            ->and(array_keys($response->json('data')))->toBe(PHOTO_ANALYSIS_KEYS)
            ->and($response->json('data'))->toMatchArray(['id' => $analysis->ulid, 'status' => 'pending', 'error' => null, 'proposals' => [], 'completed_at' => null])
            ->and($response->json('data.created_at'))->toBeString()
            ->and($analysis->status)->toBe(PhotoAnalysisStatus::Pending)
            ->and($analysis->user_id)->toBe($editor->id)
            ->and($analysis->inventory_id)->toBe($case['inventory']->id)
            ->and($analysis->locale)->toBe('en')
            ->and($analysis->photo_count)->toBe(2)
            ->and($analysis->disk)->toBe(ATTACHMENT_TEST_DISK)
            ->and($analysis->proposals)->toBeNull()
            ->and($analysis->expires_at->between(now()->addHours(24)->subMinute(), now()->addHours(24)->addMinute()))->toBeTrue();

        Queue::assertPushed(AnalyzePhotos::class, 1);
        Queue::assertPushed(AnalyzePhotos::class, fn (AnalyzePhotos $job): bool => $job->analysis->is($analysis) && $job->queue === null);
    });

    it('keeps the language of the person, to write the evidence in', function (): void {
        Queue::fake();
        $case = photoReady();

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()], ['Accept-Language' => 'fr'])->assertStatus(202);

        expect(PhotoAnalysis::query()->sole()->locale)->toBe('fr');
    });

    it('stores the photos prepared, under the folder of the inventory, and counts them nowhere', function (): void {
        Queue::fake();
        $case = photoReady();
        $workspace = $case['team']['workspace'];
        $big = attachmentUploadedAs('big.png', attachmentImageBytes('png', 3136, 1000), 'image/png');
        $gps = attachmentUploadedAs('gps.jpg', photoJpegWithGps(1200, 800), 'image/jpeg');

        photoPost($this, $case['team']['editor'], $case['inventory'], [$big, $gps, photoUpload('p.webp', 'webp')])->assertStatus(202);

        $analysis = PhotoAnalysis::query()->sole();
        $folder = "workspaces/{$workspace->ulid}/inventories/{$case['inventory']->ulid}/analyses/{$analysis->ulid}";
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);

        expect(storedFiles())->toBe(["{$folder}/1.jpg", "{$folder}/2.jpg", "{$folder}/3.jpg"]);

        $first = getimagesizefromstring((string) $disk->get("{$folder}/1.jpg"));
        $second = (string) $disk->get("{$folder}/2.jpg");

        // Re-encoded: a JPEG of 1568 pixels at most, without a trace of where the photo was taken.
        expect($first[2])->toBe(IMAGETYPE_JPEG)->and([$first[0], $first[1]])->toBe([1568, 500])
            ->and(getimagesizefromstring((string) $disk->get("{$folder}/3.jpg"))[2])->toBe(IMAGETYPE_JPEG)
            ->and($second)->not->toContain('Exif')
            ->and(@exif_read_data('data://image/jpeg;base64,'.base64_encode($second), null, true))->not->toHaveKey('GPS')
            ->and($workspace->fresh()->storage_used)->toBe(0)
            ->and(DB::table('attachments')->count())->toBe(0);
    });

    it('accepts the owner as well as an editor', function (): void {
        Queue::fake();
        $case = photoReady();

        photoPost($this, $case['team']['owner'], $case['inventory'], [photoUpload()])->assertStatus(202);

        expect(PhotoAnalysis::query()->count())->toBe(1);
    });

    it('runs the whole analysis when the queue is synchronous, and the person reads the proposals', function (): void {
        config(['queue.default' => 'sync']);
        app()->instance(PhotoAnalyzer::class, new FakePhotoAnalyzer);
        $case = photoReady();
        $editor = $case['team']['editor'];

        $created = photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(202);
        $read = inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], PhotoAnalysis::query()->sole()))->assertOk();

        expect($created->json('data.id'))->toBe($read->json('data.id'))
            ->and($read->json('data.status'))->toBe('completed')
            ->and(array_column($read->json('data.proposals'), 'field'))->toBe([$case['name']->ulid, $case['weight']->ulid])
            ->and($read->json('data.proposals.0.value'))->toBe('Sample Name')
            ->and($read->json('data.proposals.1.value'))->toBe('3.5')
            ->and(storedFiles())->toBe([]);
    });
});

describe('the photos', function (): void {
    it('are refused when there is none, when they are not a list, and beyond five', function (array $data, string $error): void {
        Queue::fake();
        $case = photoReady();
        inventoryCall($this, $case['team']['editor'], 'GET', '/api/v1/me');

        $response = $this->post(photoUri($case['inventory']), $data, frontend(['Accept' => 'application/json']))->assertStatus(422);

        expect(array_keys($response->json('errors')))->toContain($error);
        Queue::assertNothingPushed();
        expect(PhotoAnalysis::query()->count())->toBe(0)->and(storedFiles())->toBe([]);
    })->with([
        'none' => [[], 'photos'],
        'an empty list' => [['photos' => []], 'photos'],
        'a text' => [['photos' => 'photo.jpg'], 'photos'],
        'six photos' => [['photos' => [1, 2, 3, 4, 5, 6]], 'photos'],
        'a text in the list' => [['photos' => ['photo.jpg']], 'photos.0'],
    ]);

    it('are refused beyond the configured number', function (): void {
        Queue::fake();
        config(['inventories.max_photos_per_analysis' => 2]);
        $case = photoReady();

        $response = photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload(), photoUpload(), photoUpload()])->assertStatus(422);

        expect($response->json('errors.photos.0'))->toBe('You can send 2 photos at a time.');

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload(), photoUpload()])->assertStatus(202);
    });

    it('are refused when they are not a JPEG, a PNG or a WebP image', function (string $name, string $content): void {
        Queue::fake();
        $case = photoReady();
        $file = attachmentUploadedAs($name, $content, 'image/jpeg');

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload(), $file])->assertStatus(422)->assertJsonValidationErrors(['photos.1']);

        expect(PhotoAnalysis::query()->count())->toBe(0)->and(storedFiles())->toBe([]);
    })->with([
        'a PDF' => ['invoice.jpg', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n"],
        'a GIF' => ['anim.jpg', attachmentImageBytes('gif')],
        'a text' => ['notes.jpg', 'just words'],
    ]);

    it('are refused when too large', function (): void {
        Queue::fake();
        config(['inventories.max_attachment_size' => 1024]);
        $case = photoReady();
        $big = attachmentUploadedAs('big.jpg', attachmentImageBytes('jpeg', 600, 600), 'image/jpeg');

        $response = photoPost($this, $case['team']['editor'], $case['inventory'], [$big])->assertStatus(422);

        expect($response->json('errors')['photos.0'][0])->toBe('Each photo must not be larger than 1 KB.');
    });

    it('are refused when an image cannot be decoded, and the error says which one', function (): void {
        Queue::fake();
        $case = photoReady();
        $broken = attachmentUploadedAs('broken.jpg', substr(attachmentImageBytes('jpeg', 200, 200), 0, 160).str_repeat("\x00", 40), 'image/jpeg');

        $response = photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload(), $broken])->assertStatus(422);

        expect($response->json('code'))->toBe('unreadable_photo')
            ->and(array_keys($response->json('errors')))->toBe(['photos.1'])
            ->and($response->json('message'))->toBe('This photo cannot be read.')
            ->and(PhotoAnalysis::query()->count())->toBe(0)
            ->and(storedFiles())->toBe([]);
        Queue::assertNothingPushed();
    });
});

describe('a workspace that does not use the photo entry', function (): void {
    it('answers 409 photo_entry_disabled, and stores and counts nothing', function (string $reason): void {
        Queue::fake();
        $case = photoReady();

        match ($reason) {
            'workspace' => $case['team']['workspace']->forceFill(['photo_entry_enabled' => false])->save(),
            'switch' => app(InstanceSettings::class)->update([InstanceSettings::PHOTO_ENTRY_ENABLED => false]),
            'key' => config(['services.anthropic.key' => '']),
        };

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'photo_entry_disabled')
            ->assertJsonPath('message', 'The photo entry is not turned on for this workspace.');

        Queue::assertNothingPushed();
        expect(PhotoAnalysis::query()->count())->toBe(0)->and(storedFiles())->toBe([])
            ->and(RateLimiter::attempts('photo-analyses-per-day:'.$case['team']['editor']->id))->toBe(0);
    })->with(['workspace', 'switch', 'key']);

    it('is told so after the roles are judged: a viewer is refused, a stranger told there is nothing', function (): void {
        $case = photoReady();
        $case['team']['workspace']->forceFill(['photo_entry_enabled' => false])->save();

        photoPost($this, $case['team']['viewer'], $case['inventory'], [photoUpload()])->assertForbidden();
        photoPost($this, $case['team']['stranger'], $case['inventory'], [photoUpload()])->assertNotFound();
    });
});

describe('an inventory with nothing to fill', function (): void {
    it('answers 409 no_fields_to_fill for an inventory with no field, or only file and photo fields', function (): void {
        Queue::fake();
        $case = photoCase();
        photoTurnedOn($case['team']['workspace']);

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])
            ->assertStatus(409)
            ->assertJsonPath('code', 'no_fields_to_fill')
            ->assertJsonPath('message', 'This inventory has no field that photos can fill.');

        fieldIn($case['inventory'], 'photo', 'Pictures');
        fieldIn($case['inventory'], 'file', 'Manual');

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])->assertStatus(409)->assertJsonPath('code', 'no_fields_to_fill');

        Queue::assertNothingPushed();
        expect(PhotoAnalysis::query()->count())->toBe(0)->and(RateLimiter::attempts('photo-analyses-per-day:'.$case['team']['editor']->id))->toBe(0);
    });
});

describe('who may send photos', function (): void {
    it('refuses a viewer (403) and tells a stranger there is nothing (404)', function (): void {
        Queue::fake();
        $case = photoReady();

        photoPost($this, $case['team']['viewer'], $case['inventory'], [photoUpload()])->assertForbidden();
        photoPost($this, $case['team']['stranger'], $case['inventory'], [photoUpload()])->assertNotFound();

        Queue::assertNothingPushed();
        expect(PhotoAnalysis::query()->count())->toBe(0);
    });

    it('refuses an API token: it is for a person at their browser', function (): void {
        $case = photoReady();
        $token = tokenFor($case['team']['editor']);

        tokenCall($this, $token, 'POST', photoUri($case['inventory']), [])->assertForbidden()->assertJsonPath('code', 'session_required');
        tokenCall($this, $token, 'GET', photoUri($case['inventory']).'/01hzzzzzzzzzzzzzzzzzzzzzzz')->assertForbidden()->assertJsonPath('code', 'session_required');
    });
});

describe('the pace', function (): void {
    it('allows five requests a minute to a person, and answers 429 with Retry-After beyond', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day' => 100]);
        $case = photoReady();

        foreach (range(1, 5) as $_) {
            photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])->assertStatus(202);
        }

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');

        // Another person has their own count.
        photoPost($this, $case['team']['owner'], $case['inventory'], [photoUpload()])->assertStatus(202);
        expect(PhotoAnalysis::query()->count())->toBe(6);
    });

    it('allows the analyses of a day to a person, and answers 429 beyond, whatever their workspace', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day' => 2]);
        $case = photoReady();
        $editor = $case['team']['editor'];

        photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(202);
        photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(202);
        $refused = photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(429);

        expect($refused->headers->get('Retry-After'))->toBeNumeric()->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(86400)
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('2')
            ->and($refused->json('code'))->toBe('too_many_requests')
            ->and(PhotoAnalysis::query()->count())->toBe(2)
            ->and(storedFiles())->toHaveCount(2);

        // Someone else has theirs.
        photoPost($this, $case['team']['owner'], $case['inventory'], [photoUpload()])->assertStatus(202);
    });

    it('counts only the analyses that are accepted: a refused request costs nothing', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day' => 2]);
        $case = photoReady();
        $editor = $case['team']['editor'];

        // Refused in every way, five times in a minute is the pace, so stay under it: three refusals.
        photoPost($this, $editor, $case['inventory'], [attachmentUploadedAs('x.jpg', 'text', 'image/jpeg')])->assertStatus(422);
        photoPost($this, $editor, $case['inventory'], [attachmentUploadedAs('x.jpg', substr(attachmentImageBytes('jpeg', 200, 200), 0, 160).str_repeat("\x00", 40), 'image/jpeg')])->assertStatus(422);
        $case['team']['workspace']->forceFill(['photo_entry_enabled' => false])->save();
        photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(409);
        $case['team']['workspace']->forceFill(['photo_entry_enabled' => true])->save();

        expect(RateLimiter::attempts('photo-analyses-per-day:'.$editor->id))->toBe(0);

        photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(202);

        expect(RateLimiter::attempts('photo-analyses-per-day:'.$editor->id))->toBe(1);
    });

    it('tells a person who is out of analyses before it works on their photos', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day' => 1]);
        $case = photoReady();
        $editor = $case['team']['editor'];

        photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(202);
        // A photo that would be refused as unreadable is not even looked at: the day is used up.
        photoPost($this, $editor, $case['inventory'], [attachmentUploadedAs('x.jpg', substr(attachmentImageBytes('jpeg', 200, 200), 0, 160).str_repeat("\x00", 40), 'image/jpeg')])->assertStatus(429);
    });
});

describe('a photo the server cannot prepare', function (): void {
    afterEach(function (): void {
        unset($GLOBALS['photo_imagejpeg_fails']);
    });

    it('is a 500 that is reported, not a 422 that blames the photo, and costs nothing', function (): void {
        Queue::fake();
        $case = photoReady();
        $reported = [];
        app()->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported): bool {
            $reported[] = $exception->getMessage();

            return false;
        });
        $GLOBALS['photo_imagejpeg_fails'] = true;

        $response = photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])->assertStatus(500);

        expect($response->json('code'))->toBeNull()
            ->and($response->json('errors'))->toBeNull()
            ->and($reported)->toHaveCount(1)
            ->and($reported[0])->toContain('could not be prepared')
            ->and(PhotoAnalysis::query()->count())->toBe(0)
            ->and(RateLimiter::attempts('photo-analyses-per-day:'.$case['team']['editor']->id))->toBe(0);
    });
});

describe('an analysis that cannot be started', function (): void {
    /**
     * What the application reports while the test runs.
     *
     * @param  list<string>  $reported
     */
    function photoReports(array &$reported): void
    {
        app()->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use (&$reported): bool {
            $reported[] = $exception->getMessage();

            return false;
        });
    }

    it('answers 503 when a photo cannot be written, leaves no folder without a row, reports the cause and gives the places back', function (): void {
        config(['inventories.photo_analyses_per_day_instance' => 5]);
        $case = photoReady();
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        $calls = 0;
        attachmentDiskProxy()->shouldReceive('put')->twice()->andReturnUsing(function (string $path, mixed $contents) use ($disk, &$calls): bool {
            return ++$calls === 1 ? (bool) $disk->put($path, $contents) : false;
        });
        $reported = [];
        photoReports($reported);

        $response = photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload(), photoUpload()])->assertStatus(503);

        $analysis = PhotoAnalysis::query()->sole();

        expect($response->json())->toBe(['message' => 'The photos could not be sent for analysis right now. Try again in a moment.', 'code' => 'photo_analysis_unavailable'])
            ->and($analysis->status)->toBe(PhotoAnalysisStatus::Failed)
            ->and($analysis->error)->toBe('failed')
            ->and(storedFiles())->toBe([])
            ->and($reported)->toContain('A file could not be written to the disk ['.ATTACHMENT_TEST_DISK.'].')
            ->and(RateLimiter::attempts('photo-analyses-per-day:'.$case['team']['editor']->id))->toBe(0)
            ->and(RateLimiter::attempts('photo-analyses-per-day:instance'))->toBe(0);
    });

    it('answers 503 when the job cannot be queued, and the photos go with the analysis that ends failed, the places back', function (): void {
        config(['inventories.photo_analyses_per_day_instance' => 5]);
        $case = photoReady();
        Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('The queue is down'));
        $reported = [];
        photoReports($reported);

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])
            ->assertStatus(503)
            ->assertJsonPath('code', 'photo_analysis_unavailable');

        expect(PhotoAnalysis::query()->sole()->status)->toBe(PhotoAnalysisStatus::Failed)
            ->and(storedFiles())->toBe([])
            ->and($reported)->toContain('The queue is down')
            ->and(RateLimiter::attempts('photo-analyses-per-day:'.$case['team']['editor']->id))->toBe(0)
            ->and(RateLimiter::attempts('photo-analyses-per-day:instance'))->toBe(0);
    });

    it('creates the row before it writes anything: a folder is never on the disk without one', function (): void {
        $case = photoReady();
        $disk = Storage::disk(ATTACHMENT_TEST_DISK);
        $rows = [];
        attachmentDiskProxy()->shouldReceive('put')->once()->andReturnUsing(function (string $path, mixed $contents) use ($disk, &$rows): bool {
            $rows[] = PhotoAnalysis::query()->count();

            return (bool) $disk->put($path, $contents);
        });
        Queue::fake();

        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])->assertStatus(202);

        expect($rows)->toBe([1]);
    });
});

describe('the cap of the instance', function (): void {
    it('is 200 analyses a day unless the configuration says otherwise', function (): void {
        expect(config('inventories.photo_analyses_per_day_instance'))->toBe(200);
    });

    it('stops everybody once the instance has run its analyses of the day, with a message and a code of its own', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day' => 100, 'inventories.photo_analyses_per_day_instance' => 2]);
        $case = photoReady();
        $editor = $case['team']['editor'];
        $owner = $case['team']['owner'];

        photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(202);
        photoPost($this, $owner, $case['inventory'], [photoUpload()])->assertStatus(202);
        $refused = photoPost($this, $editor, $case['inventory'], [photoUpload()])->assertStatus(429);

        expect($refused->json())->toBe(['message' => 'The instance reached its daily number of photo analyses; try again tomorrow.', 'code' => 'photo_analyses_instance_limit'])
            ->and((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(86400)
            ->and($refused->headers->get('X-RateLimit-Limit'))->toBe('2')
            ->and(PhotoAnalysis::query()->count())->toBe(2)
            ->and(storedFiles())->toHaveCount(2);

        // The other person is refused as well: the cap is the instance's, not the person's.
        photoPost($this, $owner, $case['inventory'], [photoUpload()])->assertStatus(429)->assertJsonPath('code', 'photo_analyses_instance_limit');
    });

    it('is told before the photos are worked on, like the limit of a person', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day_instance' => 1]);
        $case = photoReady();

        photoPost($this, $case['team']['owner'], $case['inventory'], [photoUpload()])->assertStatus(202);
        photoPost($this, $case['team']['editor'], $case['inventory'], [attachmentUploadedAs('x.jpg', substr(attachmentImageBytes('jpeg', 200, 200), 0, 160).str_repeat("\x00", 40), 'image/jpeg')])
            ->assertStatus(429)
            ->assertJsonPath('code', 'photo_analyses_instance_limit');
    });

    it('gives the place back to the person who was refused because of it', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day_instance' => 1]);
        $case = photoReady();
        $quota = app(PhotoAnalysisQuota::class);
        $editor = $case['team']['editor'];

        photoPost($this, $case['team']['owner'], $case['inventory'], [photoUpload()])->assertStatus(202);

        // The check made before the photos were prepared passed (another request had not counted yet): the reservation is what refuses.
        expect(fn () => $quota->reserve($editor))->toThrow(InstanceQuotaReachedException::class)
            ->and(RateLimiter::attempts('photo-analyses-per-day:'.$editor->id))->toBe(0);
    });

    it('counts only the analyses that are accepted', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day_instance' => 5]);
        $case = photoReady();

        photoPost($this, $case['team']['editor'], $case['inventory'], [attachmentUploadedAs('x.jpg', 'text', 'image/jpeg')])->assertStatus(422);
        photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])->assertStatus(202);

        expect(RateLimiter::attempts('photo-analyses-per-day:instance'))->toBe(1);
    });

    it('is no cap when it is 0', function (): void {
        Queue::fake();
        config(['inventories.photo_analyses_per_day' => 100, 'inventories.photo_analyses_per_day_instance' => 0]);
        $case = photoReady();

        foreach ([1, 2, 3] as $ignored) {
            photoPost($this, $case['team']['editor'], $case['inventory'], [photoUpload()])->assertStatus(202);
        }

        expect(PhotoAnalysis::query()->count())->toBe(3)->and(RateLimiter::attempts('photo-analyses-per-day:instance'))->toBe(0);
    });
});

describe('reading an analysis', function (): void {
    it('reads the fields of the inventory only for an analysis that is completed, not at every poll', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $pending = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id]);
        $done = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Completed, 'proposals' => [], 'completed_at' => now()]);
        $fieldQueries = function (PhotoAnalysis $analysis) use ($editor, $case): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], $analysis))->assertOk();

            return count(array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains((string) $query['query'], 'inventory_fields')));
        };

        // The permission check reads the fields in neither case; the ULIDs for the proposals are asked for only when there are some.
        $whilePending = $fieldQueries($pending);
        $whenDone = $fieldQueries($done);

        expect($whenDone)->toBeGreaterThan($whilePending);
    });

    it('gives the analysis of the person who sent the photos, in every state', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $inventory = $case['inventory'];
        $proposals = [
            ['field' => $case['name']->ulid, 'value' => 'Drill', 'confidence' => 'high', 'evidence' => 'Printed on the case'],
            ['field' => $case['weight']->ulid, 'value' => '2.5', 'confidence' => 'low', 'evidence' => ''],
        ];
        $pending = PhotoAnalysis::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $editor->id]);
        $running = PhotoAnalysis::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Running, 'started_at' => now()]);
        $done = PhotoAnalysis::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Completed, 'proposals' => $proposals, 'completed_at' => now(), 'model' => 'claude-sonnet-5-5', 'input_tokens' => 1200, 'output_tokens' => 80]);
        $nothing = PhotoAnalysis::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Completed, 'proposals' => [], 'completed_at' => now()]);
        $failed = PhotoAnalysis::factory()->create(['inventory_id' => $inventory->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Failed, 'error' => 'unavailable', 'completed_at' => now()]);

        foreach ([$pending, $running] as $open) {
            $response = inventoryCall($this, $editor, 'GET', photoUri($inventory, $open))->assertOk();

            expect(array_keys($response->json('data')))->toBe(PHOTO_ANALYSIS_KEYS)
                ->and($response->json('data'))->toMatchArray(['id' => $open->ulid, 'status' => $open->status->value, 'error' => null, 'proposals' => [], 'completed_at' => null]);
        }

        $response = inventoryCall($this, $editor, 'GET', photoUri($inventory, $done))->assertOk();
        expect(array_keys($response->json()))->toBe(['data'])
            ->and(array_keys($response->json('data')))->toBe(PHOTO_ANALYSIS_KEYS)
            ->and($response->json('data.status'))->toBe('completed')
            ->and($response->json('data.error'))->toBeNull()
            ->and($response->json('data.proposals'))->toBe($proposals)
            ->and(array_keys($response->json('data.proposals.0')))->toBe(['field', 'value', 'confidence', 'evidence'])
            ->and($response->json('data.completed_at'))->toBeString();

        // Nothing of what the request cost is given out.
        expect(json_encode($response->json()))->not->toContain('claude-sonnet')->not->toContain('1200')->not->toContain('input_tokens');

        expect(inventoryCall($this, $editor, 'GET', photoUri($inventory, $nothing))->assertOk()->json('data'))->toMatchArray(['status' => 'completed', 'proposals' => []]);

        $failure = inventoryCall($this, $editor, 'GET', photoUri($inventory, $failed))->assertOk();
        expect($failure->json('data.status'))->toBe('failed')
            ->and($failure->json('data.error'))->toBe('unavailable')
            ->and($failure->json('data.proposals'))->toBe([]);
    });

    it('gives the code of a failure, whatever the language of the request', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $failed = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Failed, 'error' => 'refused']);

        foreach (['en', 'fr'] as $language) {
            expect(inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], $failed), headers: ['Accept-Language' => $language])->json('data.error'))->toBe('refused');
        }
    });

    it('gives each code an analysis can fail with as it was stored', function (PhotoAnalysisError $error): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $failed = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Failed, 'error' => $error->value, 'completed_at' => now()]);

        $data = inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], $failed))->assertOk()->json('data');

        expect($data['status'])->toBe('failed')->and($data['error'])->toBe($error->value)->and($data['proposals'])->toBe([]);
    })->with(array_map(fn (PhotoAnalysisError $error): array => [$error], PhotoAnalysisError::cases()));

    it('tells a code it does not know, and a failure with no code, as the general failure', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $strange = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Failed, 'error' => 'from_a_later_version']);
        $nothing = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Failed, 'error' => null]);

        foreach ([$strange, $nothing] as $analysis) {
            expect(inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], $analysis))->json('data.error'))->toBe('failed');
        }
    });

    it('leaves out a proposal for a field that was deleted since', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $analysis = PhotoAnalysis::factory()->create([
            'inventory_id' => $case['inventory']->id,
            'user_id' => $editor->id,
            'status' => PhotoAnalysisStatus::Completed,
            'proposals' => [
                ['field' => $case['name']->ulid, 'value' => 'Drill', 'confidence' => 'high', 'evidence' => ''],
                ['field' => $case['weight']->ulid, 'value' => '2.5', 'confidence' => 'high', 'evidence' => ''],
            ],
        ]);
        $case['weight']->delete();

        $proposals = inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], $analysis))->assertOk()->json('data.proposals');

        expect(array_column($proposals, 'field'))->toBe([$case['name']->ulid]);
    });

    it('is found whatever the case of its ULID', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id]);

        inventoryCall($this, $editor, 'GET', photoUri($case['inventory']).'/'.strtoupper($analysis->ulid))->assertOk()->assertJsonPath('data.id', $analysis->ulid);
    });

    it('is a 404 for anybody but its author, even in the same workspace, and for one that does not exist', function (): void {
        $case = photoReady();
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['editor']->id]);

        inventoryCall($this, $case['team']['owner'], 'GET', photoUri($case['inventory'], $analysis))->assertNotFound();
        inventoryCall($this, $case['team']['stranger'], 'GET', photoUri($case['inventory'], $analysis))->assertNotFound();
        inventoryCall($this, $case['team']['editor'], 'GET', photoUri($case['inventory']).'/01hzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
        inventoryCall($this, $case['team']['editor'], 'GET', photoUri($case['inventory'], $analysis))->assertOk();
    });

    it('is a 404 once its time is up, whether or not the pruner cleared it away', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $expired = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id, 'status' => PhotoAnalysisStatus::Completed, 'proposals' => [], 'expires_at' => now()->subSecond()]);

        inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], $expired))->assertNotFound();
    });

    it('is a 404 through another inventory, even of the same person and workspace', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $other = inventoryIn($case['team']['workspace'], 'Other');
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id]);

        inventoryCall($this, $editor, 'GET', photoUri($other, $analysis))->assertNotFound();
    });

    it('is refused to a viewer, whoever they are', function (): void {
        $case = photoReady();
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $case['team']['viewer']->id]);

        inventoryCall($this, $case['team']['viewer'], 'GET', photoUri($case['inventory'], $analysis))->assertForbidden();
    });

    it('is still readable when the feature has been turned off since: the person only reads what they sent', function (): void {
        $case = photoReady();
        $editor = $case['team']['editor'];
        $analysis = PhotoAnalysis::factory()->create(['inventory_id' => $case['inventory']->id, 'user_id' => $editor->id]);
        $case['team']['workspace']->forceFill(['photo_entry_enabled' => false])->save();

        inventoryCall($this, $editor, 'GET', photoUri($case['inventory'], $analysis))->assertOk();
    });
});
