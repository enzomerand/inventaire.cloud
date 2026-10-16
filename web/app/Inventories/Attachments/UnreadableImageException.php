<?php

declare(strict_types=1);

namespace App\Inventories\Attachments;

use RuntimeException;

/**
 * Raised when a file that says it is an image cannot be decoded as one.
 */
final class UnreadableImageException extends RuntimeException {}
