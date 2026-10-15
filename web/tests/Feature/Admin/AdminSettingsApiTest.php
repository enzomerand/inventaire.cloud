<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

dataset('settings calls', [
    'read' => ['GET', []],
    'change' => ['PUT', ['registration_open' => false]],
]);

describe('who may call it', function (): void {
    it('refuses a visitor', function (string $method, array $data): void {
        $this->json($method, '/api/v1/admin/settings', $data, frontend())->assertUnauthorized();

        expect(app(InstanceSettings::class)->registrationOpen())->toBeTrue();
    })->with('settings calls');

    it('refuses an account that does not administer the instance, and changes nothing', function (string $method, array $data): void {
        $this->actingAs(User::factory()->withPersonalWorkspace()->create())->json($method, '/api/v1/admin/settings', $data, frontend())->assertForbidden();

        expect(DB::table('settings')->count())->toBe(0)->and(app(InstanceSettings::class)->registrationOpen())->toBeTrue();
    })->with('settings calls');

    it('is for the web front only: an administrator calling from elsewhere is refused with a reason', function (string $method, array $data): void {
        $this->actingAs(User::factory()->admin()->create())->json($method, '/api/v1/admin/settings', $data)
            ->assertForbidden()
            ->assertJsonPath('code', 'not_stateful');

        expect(DB::table('settings')->count())->toBe(0);
    })->with('settings calls');
});

it('refuses an API token even when the call looks like the front', function (string $method, array $data): void {
    $admin = User::factory()->admin()->create();

    $this->withToken($admin->createToken('script', ['*'])->plainTextToken)->json($method, '/api/v1/admin/settings', $data, frontend())
        ->assertForbidden()
        ->assertJsonPath('code', 'session_required');

    expect(DB::table('settings')->count())->toBe(0);
})->with('settings calls');

describe('the settings', function (): void {
    it('are open to sign-ups until an administrator says otherwise', function (): void {
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/v1/admin/settings', frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => true]]);
    });

    it('close registration, and are answered as they now are', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['registration_open' => false], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => false]]);

        $this->flushSession();

        $this->actingAs($admin)->getJson('/api/v1/admin/settings', frontend())->assertOk()->assertJsonPath('data.registration_open', false);
        expect(DB::table('settings')->where('key', 'registration_open')->count())->toBe(1)
            ->and(json_decode((string) DB::table('settings')->where('key', 'registration_open')->value('value')))->toBeFalse();
    });

    it('open registration again', function (): void {
        $admin = User::factory()->admin()->create();
        app(InstanceSettings::class)->setRegistrationOpen(false);

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['registration_open' => true], frontend())
            ->assertOk()
            ->assertJsonPath('data.registration_open', true);

        expect(app(InstanceSettings::class)->registrationOpen())->toBeTrue();
    });

    it('are the same for the next request on any server: they are read from the database each time', function (): void {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['registration_open' => false], frontend())->assertOk();

        // A new request is a new process: the service starts from what the database holds.
        app()->forgetScopedInstances();

        expect(app(InstanceSettings::class)->registrationOpen())->toBeFalse();
    });

    it('can be set to what they already are', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['registration_open' => true], frontend())->assertOk();
        $this->flushSession();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['registration_open' => true], frontend())->assertOk()->assertJsonPath('data.registration_open', true);

        expect(DB::table('settings')->count())->toBe(1);
    });

    it('accept the usual spellings of a boolean and refuse the rest', function (mixed $value, int $status): void {
        $this->actingAs(User::factory()->admin()->create())->putJson('/api/v1/admin/settings', ['registration_open' => $value], frontend())->assertStatus($status);
    })->with([
        'false' => [false, 200],
        'zero' => [0, 200],
        'the string one' => ['1', 200],
        'missing' => [null, 422],
        'a word' => ['closed', 422],
        'a list' => [[false], 422],
    ]);

    it('are not changed by a request that is refused', function (): void {
        $this->actingAs(User::factory()->admin()->create())->putJson('/api/v1/admin/settings', ['registration_open' => 'closed'], frontend())->assertUnprocessable();

        expect(DB::table('settings')->count())->toBe(0);
    });

    it('ignore what they do not know about', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/admin/settings', ['registration_open' => false, 'something_else' => 'x'], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => false]]);

        expect(DB::table('settings')->pluck('key')->all())->toBe(['registration_open']);
    });
});

describe('the settings page', function (): void {
    it('shows the switch as it is, with its endpoint', function (bool $open): void {
        app(InstanceSettings::class)->setRegistrationOpen($open);

        $this->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
            ->get(route('app.admin.settings'), ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertSee('x-data="adminSettings"', false)
            ->assertSee('data-url="/api/v1/admin/settings"', false)
            ->assertSee('data-registration-open="'.($open ? '1' : '0').'"', false)
            ->assertSee('Anybody can create an account')
            ->assertSee('admin.settings.notice.closed', false);
    })->with(['open' => [true], 'closed' => [false]]);

    it('is translated', function (): void {
        $this->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
            ->get(route('app.admin.settings'), ['Accept-Language' => 'fr'])
            ->assertOk()
            ->assertSee('Inscriptions')
            ->assertSee('Tout le monde peut créer un compte');
    });
});
