<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\Exceptions\InstallationException;
use App\Installer\InstallationState;
use App\Installer\SetupToken;
use Illuminate\Console\Command;

/**
 * Prints the one-time token that unlocks the web install wizard.
 *
 * The token is also written to the application log on the first web access;
 * this command is the way to get it when the log is not at hand. Only the
 * token goes to the output so it can be captured in a script.
 */
final class InstallTokenCommand extends Command
{
    protected $signature = 'inventaire:install-token';

    protected $description = 'Print the one-time token that unlocks the web install wizard';

    /**
     * Prints the token, creating it if needed.
     */
    public function handle(InstallationState $state, SetupToken $token): int
    {
        if ($state->isInstalled()) {
            $this->components->error(__('The instance is already installed: there is no setup token.'));

            return self::FAILURE;
        }

        try {
            $this->line($token->current());
        } catch (InstallationException $exception) {
            $this->components->error($exception->userMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
