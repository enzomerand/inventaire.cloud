<?php

declare(strict_types=1);

namespace App\Inventories\Versions;

/**
 * Where one signer is: yet to sign, signed, or refused.
 */
enum SignerStatus: string
{
    case Pending = 'pending';
    case Signed = 'signed';
    case Rejected = 'rejected';
}
