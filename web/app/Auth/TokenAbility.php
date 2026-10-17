<?php

declare(strict_types=1);

namespace App\Auth;

use App\Http\Middleware\RequireTokenAbility;

/**
 * What an API token may be allowed to do, besides what its owner may do (a token never goes beyond the role of the person
 * it belongs to: the policies decide that, for a token as for a session).
 *
 * The three abilities are independent of each other: a token that can write cannot read unless it is given `read` too.
 *
 * - `read`: every `GET` of the public API (the account, workspaces, inventories, items, history, files, exports, imports);
 * - `write`: items, attachments, exports and imports (the day-to-day content of an inventory);
 * - `manage`: workspaces, inventories and their fields (the structure).
 *
 * The routes are classified in `routes/api.php` with the `token:<ability>` middleware ({@see RequireTokenAbility}).
 * A token made before the abilities existed, or with `*`, has all of them. What belongs to a person at their browser (signing in,
 * the account, the administration, the tokens themselves) is for no token, whatever its abilities.
 */
enum TokenAbility: string
{
    case Read = 'read';
    case Write = 'write';
    case Manage = 'manage';

    /**
     * The name of the ability for a person, in the language of the request.
     */
    public function label(): string
    {
        return match ($this) {
            self::Read => __('Read'),
            self::Write => __('Write'),
            self::Manage => __('Manage'),
        };
    }

    /**
     * Every ability, as the strings a token stores and the API takes, in the order of the enum.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }
}
