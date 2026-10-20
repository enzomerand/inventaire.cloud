<?php

declare(strict_types=1);

use App\Models\User;
use App\Settings\InstanceSettings;
use App\Support\PublicUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/**
 * What the settings say of the e-signature service when the instance has none.
 *
 * @return array{configured: bool, webhook_secret_configured: bool, webhook_url: string}
 */
function adminNoDocumenso(): array
{
    return ['configured' => false, 'webhook_secret_configured' => false, 'webhook_url' => PublicUrls::documensoWebhook()];
}

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
            ->assertExactJson(['data' => ['registration_open' => true, 'invitation_sign_up' => true, 'share_links_enabled' => true, 'documenso' => adminNoDocumenso()]]);
    });

    it('close registration, and are answered as they now are', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['registration_open' => false], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => false, 'invitation_sign_up' => true, 'share_links_enabled' => true, 'documenso' => adminNoDocumenso()]]);

        $this->flushSession();

        $this->actingAs($admin)->getJson('/api/v1/admin/settings', frontend())->assertOk()->assertJsonPath('data.registration_open', false);
        expect(DB::table('settings')->where('key', 'registration_open')->count())->toBe(1)
            ->and(json_decode((string) DB::table('settings')->where('key', 'registration_open')->value('value')))->toBeFalse();
    });

    it('let an administrator turn the invitations off and on, apart from the registration', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['invitation_sign_up' => false], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => true, 'invitation_sign_up' => false, 'share_links_enabled' => true, 'documenso' => adminNoDocumenso()]]);

        $this->flushSession();

        $this->actingAs($admin)->getJson('/api/v1/admin/settings', frontend())->assertOk()->assertJsonPath('data.invitation_sign_up', false)->assertJsonPath('data.registration_open', true);
        expect(DB::table('settings')->pluck('key')->all())->toBe(['invitation_sign_up']);

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['invitation_sign_up' => true, 'registration_open' => false], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => false, 'invitation_sign_up' => true, 'share_links_enabled' => true, 'documenso' => adminNoDocumenso()]]);
    });

    it('refuse an invitation_sign_up that is not a boolean, and change nothing', function (): void {
        $this->actingAs(User::factory()->admin()->create())->putJson('/api/v1/admin/settings', ['invitation_sign_up' => 'maybe'], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('invitation_sign_up');

        expect(DB::table('settings')->count())->toBe(0);
    });

    it('let an administrator turn sharing by link off and on, apart from the other settings', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['share_links_enabled' => false], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => true, 'invitation_sign_up' => true, 'share_links_enabled' => false, 'documenso' => adminNoDocumenso()]]);

        $this->flushSession();

        $this->actingAs($admin)->getJson('/api/v1/admin/settings', frontend())->assertOk()->assertJsonPath('data.share_links_enabled', false)->assertJsonPath('data.registration_open', true);
        expect(DB::table('settings')->pluck('key')->all())->toBe(['share_links_enabled'])
            ->and(app(InstanceSettings::class)->shareLinksEnabled())->toBeFalse();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['share_links_enabled' => true, 'registration_open' => false], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => false, 'invitation_sign_up' => true, 'share_links_enabled' => true, 'documenso' => adminNoDocumenso()]]);
    });

    it('refuse a share_links_enabled that is not a boolean, or null, and change nothing', function (mixed $value): void {
        $this->actingAs(User::factory()->admin()->create())->putJson('/api/v1/admin/settings', ['share_links_enabled' => $value], frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('share_links_enabled');

        expect(DB::table('settings')->count())->toBe(0);
    })->with(['a word' => 'maybe', 'null' => null, 'a list' => [[true]]]);

    it('refuse a share_links_enabled in French as well, with a message in French', function (): void {
        $response = $this->actingAs(User::factory()->admin()->speaking('fr')->create())->putJson('/api/v1/admin/settings', ['share_links_enabled' => 'maybe'], frontend(['Accept-Language' => 'fr']))
            ->assertUnprocessable();

        expect($response->json('errors.share_links_enabled.0'))->toContain('vrai ou faux');
    });

    it('open registration again', function (): void {
        $admin = User::factory()->admin()->create();
        app(InstanceSettings::class)->update([InstanceSettings::REGISTRATION_OPEN => false]);

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

    it('are changed only for the ones that are sent: a request must send at least one', function (array $data): void {
        $admin = User::factory()->admin()->create();
        app(InstanceSettings::class)->update([InstanceSettings::REGISTRATION_OPEN => false]);

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', $data, frontend())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('settings');

        expect(json_decode((string) DB::table('settings')->where('key', 'registration_open')->value('value')))->toBeFalse();
    })->with([
        'nothing' => [[]],
        'only what is not known' => [['something_else' => 'x']],
    ]);

    it('are not changed by a request that is refused', function (): void {
        $this->actingAs(User::factory()->admin()->create())->putJson('/api/v1/admin/settings', ['registration_open' => 'closed'], frontend())->assertUnprocessable();

        expect(DB::table('settings')->count())->toBe(0);
    });

    it('ignore what they do not know about', function (): void {
        $this->actingAs(User::factory()->admin()->create())
            ->putJson('/api/v1/admin/settings', ['registration_open' => false, 'something_else' => 'x'], frontend())
            ->assertOk()
            ->assertExactJson(['data' => ['registration_open' => false, 'invitation_sign_up' => true, 'share_links_enabled' => true, 'documenso' => adminNoDocumenso()]]);

        expect(DB::table('settings')->pluck('key')->all())->toBe(['registration_open']);
    });
});

describe('the settings page', function (): void {
    it('shows the switch as it is, with its endpoint', function (bool $open): void {
        app(InstanceSettings::class)->update([InstanceSettings::REGISTRATION_OPEN => $open]);

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

    describe('the card of the e-signature service', function (): void {
        beforeEach(function (): void {
            config(['services.documenso.url' => null, 'services.documenso.api_key' => null, 'services.documenso.webhook_secret' => null]);
        });

        /**
         * The settings page as an administrator reads it.
         */
        function adminSettingsPage(object $test, string $language = 'en'): TestResponse
        {
            return $test->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
                ->get(route('app.admin.settings'), ['Accept-Language' => $language])
                ->assertOk();
        }

        /**
         * A `data-*` attribute of the markup of the page, decoded as the browser decodes it.
         */
        function adminSettingsAttribute(TestResponse $page, string $name): ?string
        {
            return preg_match('/\s'.preg_quote($name, '/').'="([^"]*)"/', $page->getContent(), $match) === 1 ? html_entity_decode($match[1], ENT_QUOTES) : null;
        }

        it('says there is no service, and gives the address the webhook would have', function (): void {
            $page = adminSettingsPage($this)
                ->assertSee('Electronic signature (Documenso)')
                ->assertSee('Webhook address');

            expect(adminSettingsAttribute($page, 'data-documenso-configured'))->toBe('0')
                ->and(adminSettingsAttribute($page, 'data-documenso-secret'))->toBe('0')
                ->and(adminSettingsAttribute($page, 'data-webhook-url'))->toBe(PublicUrls::documensoWebhook());
        });

        it('says the service and the webhook are set, whichever way they are', function (mixed $url, mixed $key, mixed $secret, string $configured, string $open): void {
            config(['services.documenso.url' => $url, 'services.documenso.api_key' => $key, 'services.documenso.webhook_secret' => $secret]);

            $page = adminSettingsPage($this);

            expect(adminSettingsAttribute($page, 'data-documenso-configured'))->toBe($configured)
                ->and(adminSettingsAttribute($page, 'data-documenso-secret'))->toBe($open);
        })->with([
            'both, with a secret' => ['https://sign.example.test', 'api_test_key', 'secret-test', '1', '1'],
            'both, without a secret' => ['https://sign.example.test', 'api_test_key', null, '1', '0'],
            'a secret only' => [null, null, 'secret-test', '0', '1'],
            'an address only' => ['https://sign.example.test', null, null, '0', '0'],
        ]);

        it('never writes the address, the token or the secret in the page', function (): void {
            config(['services.documenso.url' => 'https://sign.example.test', 'services.documenso.api_key' => 'api_test_key', 'services.documenso.webhook_secret' => 'secret-test']);

            adminSettingsPage($this)
                ->assertDontSee('sign.example.test')
                ->assertDontSee('api_test_key')
                ->assertDontSee('secret-test');
        });

        it('builds the address of the webhook from APP_URL, never from the request', function (): void {
            $page = $this->actingAs(User::factory()->admin()->withPersonalWorkspace()->create())
                ->get(route('app.admin.settings'), ['X-Forwarded-Host' => 'evil.example.test'])
                ->assertOk();

            expect(adminSettingsAttribute($page, 'data-webhook-url'))->toBe(PublicUrls::documensoWebhook())
                ->and($page->getContent())->not->toContain('evil.example.test');
        });

        it('names the settings of the environment, and says how often the statuses are refreshed without a webhook', function (): void {
            adminSettingsPage($this)
                ->assertSee('DOCUMENSO_URL')
                ->assertSee('DOCUMENSO_API_KEY')
                ->assertSee('DOCUMENSO_WEBHOOK_SECRET')
                ->assertSee('refreshed every 10 minutes');
        });

        it('is in the same component as the sign-ups, which keeps its endpoint', function (): void {
            adminSettingsPage($this)
                ->assertSee('x-data="adminSettings"', false)
                ->assertSee('data-url="/api/v1/admin/settings"', false)
                ->assertSee('data-registration-open="1"', false);
        });

        it('is translated', function (): void {
            adminSettingsPage($this, 'fr')
                ->assertSee('Signature électronique (Documenso)')
                ->assertSee('Adresse du webhook')
                ->assertSee('actualisés toutes les 10 minutes');
        });
    });
});

describe('the state of the e-signature service', function (): void {
    beforeEach(function (): void {
        config(['services.documenso.url' => null, 'services.documenso.api_key' => null, 'services.documenso.webhook_secret' => null]);
    });

    /**
     * What an administrator reads of the service.
     *
     * @return array<string, mixed>
     */
    function adminDocumenso(object $test): array
    {
        return $test->actingAs(User::factory()->admin()->create())->getJson('/api/v1/admin/settings', frontend())->assertOk()->json('data.documenso');
    }

    it('says there is none, and where the webhook would be', function (): void {
        expect(adminDocumenso($this))->toBe([
            'configured' => false,
            'webhook_secret_configured' => false,
            'webhook_url' => rtrim(config()->string('app.url'), '/').'/webhooks/documenso',
        ]);
    });

    it('says whether signatures can be asked for: the address and the token are both there', function (mixed $url, mixed $key, bool $configured): void {
        config(['services.documenso.url' => $url, 'services.documenso.api_key' => $key]);

        expect(adminDocumenso($this)['configured'])->toBe($configured);
    })->with([
        'both' => ['https://sign.example.test', 'api_test_key', true],
        'the address only' => ['https://sign.example.test', null, false],
        'the token only' => [null, 'api_test_key', false],
        'an address of spaces' => ['   ', 'api_test_key', false],
        'an empty token' => ['https://sign.example.test', '', false],
    ]);

    it('says whether the webhook is open: a secret is there, whatever the rest', function (mixed $secret, bool $open): void {
        config(['services.documenso.webhook_secret' => $secret]);

        $documenso = adminDocumenso($this);

        expect($documenso['webhook_secret_configured'])->toBe($open)
            ->and($documenso['configured'])->toBeFalse();
    })->with([
        'a secret' => ['secret-test', true],
        'none' => [null, false],
        'empty' => ['', false],
        'spaces' => ['  ', false],
    ]);

    it('gives the address to tell Documenso, built from APP_URL and never from the request', function (): void {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/v1/admin/settings', frontend(['X-Forwarded-Host' => 'evil.example.test']))
            ->assertOk();

        expect($response->json('data.documenso.webhook_url'))->toBe(PublicUrls::documensoWebhook())
            ->and($response->json('data.documenso.webhook_url'))->not->toContain('evil.example.test')
            ->and($response->json('data.documenso.webhook_url'))->toStartWith(rtrim(config()->string('app.url'), '/'));
    });

    it('never gives the token nor the secret', function (): void {
        config(['services.documenso.url' => 'https://sign.example.test', 'services.documenso.api_key' => 'api_test_key', 'services.documenso.webhook_secret' => 'secret-test']);

        $response = $this->actingAs(User::factory()->admin()->create())->getJson('/api/v1/admin/settings', frontend())->assertOk();

        expect($response->getContent())->not->toContain('api_test_key')->not->toContain('secret-test')->not->toContain('sign.example.test')
            ->and(array_keys($response->json('data.documenso')))->toBe(['configured', 'webhook_secret_configured', 'webhook_url']);
    });

    it('is read-only: it is answered after a change, and nothing of it can be set', function (): void {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['registration_open' => false, 'documenso' => ['configured' => true]], frontend())
            ->assertOk()
            ->assertJsonPath('data.documenso.configured', false);

        $this->flushSession();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['documenso' => ['configured' => true]], frontend())->assertUnprocessable();

        expect(DB::table('settings')->pluck('key')->all())->toBe(['registration_open']);
    });
});
