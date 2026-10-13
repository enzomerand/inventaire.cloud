<?php

declare(strict_types=1);

namespace App\Installer\Exceptions;

/**
 * An exclusive lock could not be taken because somebody else holds it.
 */
final class LockBusyException extends InstallationException {}
