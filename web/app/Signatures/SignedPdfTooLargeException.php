<?php

declare(strict_types=1);

namespace App\Signatures;

use RuntimeException;

/**
 * Thrown from inside the transfer of the signed PDF to stop it when the document is bigger than `inventories.max_signed_pdf_bytes`
 * (the callbacks of the HTTP client are the only place that can stop a transfer): the client wraps whatever its callbacks throw, so
 * {@see DocumensoClient::downloadSigned()} looks for this one in the chain of causes and answers with a refusal of its own.
 */
final class SignedPdfTooLargeException extends RuntimeException {}
