<?php

declare(strict_types=1);

namespace App\Providers;

use App\Console\ArtisanRunner;
use App\Console\ProcessArtisanRunner;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerSignInLimiters();
    }

    /**
     * The rate limiters of the sign-in endpoints (limits in config/auth.php, `login_codes`).
     *
     * They key on what the visitor typed and on their address, never on whether
     * an account exists, so a limit cannot be used to tell accounts apart.
     */
    private function registerSignInLimiters(): void
    {
        // Sending: per email address (nobody's inbox gets flooded) and per IP address (the instance is no mail relay).
        RateLimiter::for('login-code', function (Request $request): array {
            $perEmail = config()->array('auth.login_codes.send_per_email');
            $perIp = config()->array('auth.login_codes.send_per_ip');

            return [
                Limit::perMinutes((int) $perEmail['minutes'], (int) $perEmail['attempts'])->by('email:'.sha1($this->submittedEmail($request))),
                Limit::perMinutes((int) $perIp['minutes'], (int) $perIp['attempts'])->by('ip:'.$request->ip()),
            ];
        });

        // Typing codes back: on top of the attempts each code allows, so that guesses cannot hammer the database.
        RateLimiter::for('login-verify', fn (Request $request): Limit => Limit::perMinute(config()->integer('auth.login_codes.verify_per_minute'))
            ->by('ip:'.$request->ip().'|email:'.sha1($this->submittedEmail($request))));
    }

    /**
     * The address a request carries, in canonical form, for use in a limiter key.
     */
    private function submittedEmail(Request $request): string
    {
        $email = $request->input('email');

        return User::normalizeEmail(is_string($email) ? $email : '');
    }
}
