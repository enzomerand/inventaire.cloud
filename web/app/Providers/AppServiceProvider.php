<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\EmailAddress;
use App\Auth\StoreSessionFingerprint;
use App\Console\ArtisanRunner;
use App\Console\ProcessArtisanRunner;
use App\Inventories\Fields\FieldTypes;
use App\Models\User;
use App\Services\ClientNetwork;
use App\Settings\InstanceSettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Registers and boots application-wide services.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ArtisanRunner::class, ProcessArtisanRunner::class);

        // Read once per request and no longer (see the class), so a worker that serves many requests starts afresh.
        $this->app->scoped(InstanceSettings::class);

        // The field types are stateless objects: one registry for the whole process.
        $this->app->singleton(FieldTypes::class, fn (): FieldTypes => FieldTypes::standard());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every sign-in, "remember me" cookie included, leaves its session with the account's fingerprint (see the class).
        Event::listen(Login::class, StoreSessionFingerprint::class);

        // The administration pages and endpoints are for the administrators of the instance, and nobody else: the
        // routes ask for `can:admin`. Judged on the account as stored, at every request, so promoting or demoting
        // someone takes effect at once.
        Gate::define('admin', fn (User $user): bool => $user->is_admin);

        $this->registerSignInLimiters();
        $this->registerAccountLimiters();
        $this->registerWorkspaceLimiters();
        $this->registerAdminLimiters();
    }

    /**
     * The rate limiter of the administration endpoint that sends a mail to an address the administrator chooses
     * (limit in config/admin.php, `user_creation_per_hour`), keyed on the administrator.
     */
    private function registerAdminLimiters(): void
    {
        RateLimiter::for('admin-user-creation', fn (Request $request): Limit => Limit::perHour(config()->integer('admin.user_creation_per_hour'))
            ->by('admin-user-creation:'.$this->signedInKey($request)));
    }

    /**
     * The rate limiter of the workspace endpoints that create rows for anybody who is signed in (limit in
     * config/workspaces.php, `create_per_hour`), keyed on the user.
     */
    private function registerWorkspaceLimiters(): void
    {
        RateLimiter::for('workspace-creation', fn (Request $request): Limit => Limit::perHour(config()->integer('workspaces.create_per_hour'))
            ->by('workspace-creation:'.$this->signedInKey($request)));
    }

    /**
     * The rate limiters of the account endpoints that send a mail, confirm a deletion or hand out all of the
     * account's data (limits in config/auth.php, `account_deletion` and `account_export`).
     *
     * They key on the signed-in user: these endpoints are never reached by anybody else.
     */
    private function registerAccountLimiters(): void
    {
        RateLimiter::for('account-deletion-code', function (Request $request): array {
            $short = config()->array('auth.account_deletion.send_per_user');
            $long = config()->array('auth.account_deletion.send_per_user_hourly');
            $who = $this->signedInKey($request);

            return [
                Limit::perMinutes((int) $short['minutes'], (int) $short['attempts'])->by('deletion-code:'.$who),
                Limit::perMinutes((int) $long['minutes'], (int) $long['attempts'])->by('deletion-code-hourly:'.$who),
            ];
        });

        RateLimiter::for('account-deletion', fn (Request $request): Limit => Limit::perMinute(config()->integer('auth.account_deletion.verify_per_minute'))
            ->by('deletion:'.$this->signedInKey($request)));

        RateLimiter::for('account-export', function (Request $request): Limit {
            $export = config()->array('auth.account_export');

            return Limit::perMinutes((int) $export['minutes'], (int) $export['attempts'])->by('export:'.$this->signedInKey($request));
        });
    }

    /**
     * Who a request is from, for a limiter that runs after authentication: the user, or the network as a fallback.
     */
    private function signedInKey(Request $request): string
    {
        $user = $request->user();

        return $user === null ? 'ip:'.ClientNetwork::key($request->ip()) : 'user:'.$user->getAuthIdentifier();
    }

    /**
     * The rate limiters of the sign-in endpoints (limits in config/auth.php, `login_codes`).
     *
     * They key on what the visitor typed and on their network, never on whether
     * an account exists, so a limit cannot be used to tell accounts apart. An
     * IPv6 client counts as its whole /64 (see {@see ClientNetwork}).
     */
    private function registerSignInLimiters(): void
    {
        // Sending, from three angles: one person asking again and again (address and network together), one inbox
        // flooded by many networks (the address alone, all networks together) and one network using the instance as a
        // mail relay (the network alone).
        RateLimiter::for('login-code', function (Request $request): array {
            $perEmailAndIp = config()->array('auth.login_codes.send_per_email_and_ip');
            $perEmail = config()->array('auth.login_codes.send_per_email');
            $perIp = config()->array('auth.login_codes.send_per_ip');
            $email = $this->submittedEmailKey($request);
            $network = ClientNetwork::key($request->ip());

            return [
                Limit::perMinutes((int) $perEmailAndIp['minutes'], (int) $perEmailAndIp['attempts'])->by('email-ip:'.$email.'|'.$network),
                Limit::perMinutes((int) $perEmail['minutes'], (int) $perEmail['attempts'])->by('email:'.$email),
                Limit::perMinutes((int) $perIp['minutes'], (int) $perIp['attempts'])->by('ip:'.$network),
            ];
        });

        // Typing codes back: on top of the attempts each code allows, so that guesses cannot hammer the database.
        RateLimiter::for('login-verify', fn (Request $request): Limit => Limit::perMinute(config()->integer('auth.login_codes.verify_per_minute'))
            ->by('ip:'.ClientNetwork::key($request->ip()).'|email:'.$this->submittedEmailKey($request)));
    }

    /**
     * The address a request carries, in canonical form, as it goes into a limiter key (hashed).
     *
     * Text too long to be an address (validation refuses it) shares one key
     * instead of being converted and hashed: the limiters run before the
     * validation, on whatever size of body the server accepts.
     */
    private function submittedEmailKey(Request $request): string
    {
        $email = $request->input('email');
        $email = EmailAddress::normalize(is_string($email) ? $email : '');

        return EmailAddress::isTooLong($email) ? 'too-long' : sha1($email);
    }
}
