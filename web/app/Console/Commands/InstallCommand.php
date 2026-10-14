<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Installer\Console\ConsoleInstaller;
use Illuminate\Console\Command;

/**
 * Installs the instance from the terminal: the console twin of the web wizard.
 *
 * All the logic lives in {@see ConsoleInstaller}; this class only declares the
 * options (one per wizard field, see ConsoleInstaller::OPTIONS) and hands over.
 */
final class InstallCommand extends Command
{
    protected $signature = 'inventaire:install
        {--from-env : Validate the current configuration (.env and environment) with the live checks and finalize, without asking or writing anything}
        {--skip-mail-test : Do not test the mail settings (non-interactive mode only)}
        {--keep-environment : Leave APP_ENV and APP_DEBUG as they are instead of switching to production (non-interactive mode)}
        {--db-driver= : Database engine: pgsql, mysql or mariadb}
        {--db-host= : Database host}
        {--db-port= : Database port}
        {--db-name= : Database name}
        {--db-user= : Database user}
        {--db-password= : Database password (visible in the process list: prefer .env or the environment)}
        {--db-no-password : Store an empty database password}
        {--app-url= : Public URL of the instance}
        {--locale= : Default language (fr or en)}
        {--timezone= : Timezone, e.g. Europe/Paris}
        {--mail-host= : SMTP host}
        {--mail-port= : SMTP port}
        {--mail-scheme= : smtp (STARTTLS when offered) or smtps (implicit TLS)}
        {--mail-user= : SMTP user}
        {--mail-password= : SMTP password (visible in the process list: prefer .env or the environment)}
        {--mail-no-password : Store an empty SMTP password}
        {--mail-from-address= : Sender address}
        {--mail-from-name= : Sender name}
        {--mail-test-recipient= : Address receiving the verification code (interactive mode)}
        {--storage= : File storage: local or s3}
        {--s3-key= : S3 access key}
        {--s3-secret= : S3 secret key (visible in the process list: prefer .env or the environment)}
        {--s3-region= : S3 region}
        {--s3-bucket= : S3 bucket}
        {--s3-endpoint= : S3 endpoint URL for non-AWS providers}
        {--s3-path-style : Use path-style S3 URLs}
        {--documenso-url= : Documenso instance URL}
        {--documenso-api-key= : Documenso API token (visible in the process list: prefer .env or the environment)}
        {--admin-name= : Name of the first administrator}
        {--admin-email= : Email address of the first administrator, who signs in with a code sent to it}';

    protected $description = 'Install the instance from the terminal (interactive, non-interactive with options, or --from-env)';

    /**
     * Runs the console installer.
     */
    public function handle(): int
    {
        return $this->laravel->make(ConsoleInstaller::class, [
            'command' => $this,
            'interactive' => $this->input->isInteractive() && ! $this->option('from-env'),
        ])->run();
    }
}
