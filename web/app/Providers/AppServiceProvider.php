<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\EmailAddress;
use App\Console\ArtisanRunner;
use App\Console\ProcessArtisanRunner;
use App\Services\ClientNetwork;
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
            $email = sha1($this->submittedEmail($request));
            $network = ClientNetwork::key($request->ip());

            return [
                Limit::perMinutes((int) $perEmailAndIp['minutes'], (int) $perEmailAndIp['attempts'])->by('email-ip:'.$email.'|'.$network),
                Limit::perMinutes((int) $perEmail['minutes'], (int) $perEmail['attempts'])->by('email:'.$email),
                Limit::perMinutes((int) $perIp['minutes'], (int) $perIp['attempts'])->by('ip:'.$network),
            ];
        });

        // Typing codes back: on top of the attempts each code allows, so that guesses cannot hammer the database.
        RateLimiter::for('login-verify', fn (Request $request): Limit => Limit::perMinute(config()->integer('auth.login_codes.verify_per_minute'))
            ->by('ip:'.ClientNetwork::key($request->ip()).'|email:'.sha1($this->submittedEmail($request))));
    }

    /**
     * The address a request carries, in canonical form, for use in a limiter key.
     */
    private function submittedEmail(Request $request): string
    {
        $email = $request->input('email');

        return EmailAddress::normalize(is_string($email) ? $email : '');
    }
}
