<?php

declare(strict_types=1);

namespace App\Settings;

use App\Inventories\SearchText;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;

/**
 * The settings an administrator chooses for the whole instance.
 *
 * They live in the `settings` table, one row per setting (a key and a JSON value), so that they change without
 * touching `.env` or restarting anything, and are the same for every process and every server that share the
 * database. A setting that has no row holds its default. A row that is there but cannot be used (JSON that does not
 * decode, a value of the wrong type: a row edited by hand) holds the *safe* value of that setting, which is not always
 * the default (for registration, closed: see {@see self::registrationOpen()}), and is reported in the log, once per
 * request and without the value. Either way reading never fails because of what is stored.
 *
 * The rows are read once per request, all together (there are only a handful), and kept for the rest of it: asking
 * three times costs one query. Nothing is kept longer than the request, so a change made by an administrator is
 * seen by the very next request, wherever it is served; the price is one small query on the pages that need a
 * setting. The class is registered as a scoped service, so a worker that serves many requests starts afresh each time.
 * Writing a setting forgets what was read, and the next read loads it again.
 *
 * Each setting has a typed getter and a name here: nothing else knows how it is stored. The statements
 * are plain SQL builder calls that PostgreSQL, MySQL and MariaDB all run.
 *
 * One setting is not a choice: the version of the search text the items were built with, which `inventaire:upgrade`
 * keeps (see {@see self::searchTextVersion()}). It is stored the same way and is not among the settings the API shows.
 */
final class InstanceSettings
{
    /** Whether anybody can create an account by signing in with a new address. */
    public const REGISTRATION_OPEN = 'registration_open';

    /**
     * Whether, while registration is closed, an address that a workspace invited can still create an account: "closed" then means "on
     * invitation". Without effect while registration is open.
     */
    public const INVITATION_SIGN_UP = 'invitation_sign_up';

    /** Whether the owners of an inventory can share it read-only through a secret link. Off, every link answers as if it did not exist. */
    public const SHARE_LINKS_ENABLED = 'share_links_enabled';

    /**
     * The version ({@see SearchText::VERSION}) the stored search texts of the items were built with (a bookkeeping value of
     * `inventaire:upgrade`, not a choice of the administrator: it is not among {@see self::all()}).
     */
    public const SEARCH_TEXT_VERSION = 'search_text_version';

    /** @var array<string, mixed>|null The stored values by key, or null until they are read. An unreadable one is null. */
    private ?array $stored = null;

    /** @var array<string, true> The settings whose stored value was reported as unusable during this request. */
    private array $reported = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Whether a new address can sign in and so create an account: open unless an administrator closed it, which is the
     * case when there is no row at all. Accounts that exist are not concerned: closed or not, they sign in.
     *
     * A row that is there but unusable (JSON that does not decode, or a value that is not a boolean, such as `"false"`
     * or `0`) counts as *closed*: somebody tried to close registration, or something broke the row, and opening the
     * instance to everybody on the strength of a value nobody understands is the wrong way to fail.
     */
    public function registrationOpen(): bool
    {
        return $this->flag(self::REGISTRATION_OPEN, whenMissing: true, whenUnusable: false);
    }

    /**
     * Whether, while registration is closed, an invitation to join a workspace lets its address sign up (the default, so that "closed"
     * means "on invitation"). When an administrator turns it off, accounts come from the administrators alone, whatever invitations
     * wait. It says nothing about open registration, where anybody may sign up.
     *
     * Open unless an administrator turned it off, which is the case when there is no row at all. A row that is there but unusable
     * (not a boolean) counts as *off*, for the reason {@see self::registrationOpen()} gives: letting people in on the strength of a
     * value nobody understands is the wrong way to fail.
     */
    public function invitationSignUp(): bool
    {
        return $this->flag(self::INVITATION_SIGN_UP, whenMissing: true, whenUnusable: false);
    }

    /**
     * Whether the owners of an inventory can share it through a secret read-only link: on unless an administrator turned it off, which is
     * the case when there is no row at all. Off, no link can be made and every link that exists answers as if it did not (the links are
     * kept, and work again when sharing is turned back on).
     *
     * A row that is there but unusable (not a boolean) counts as *off*, for the reason {@see self::registrationOpen()} gives: showing an
     * inventory to anybody who holds an address, on the strength of a value nobody understands, is the wrong way to fail.
     */
    public function shareLinksEnabled(): bool
    {
        return $this->flag(self::SHARE_LINKS_ENABLED, whenMissing: true, whenUnusable: false);
    }

    /**
     * The version of the search text the items were last built with, or null when it was never recorded (or the row is
     * unusable: the texts are then built again, which is the safe thing to do).
     */
    public function searchTextVersion(): ?int
    {
        $this->stored ??= $this->load();

        if (! array_key_exists(self::SEARCH_TEXT_VERSION, $this->stored)) {
            return null;
        }

        $value = $this->stored[self::SEARCH_TEXT_VERSION];

        if (is_int($value)) {
            return $value;
        }

        $this->reportUnusable(self::SEARCH_TEXT_VERSION);

        return null;
    }

    /**
     * Records the version of the search text the items were built with.
     */
    public function recordSearchTextVersion(int $version): void
    {
        $this->write([self::SEARCH_TEXT_VERSION => $version]);
    }

    /**
     * Changes the settings that are given, and only those, in one statement. A setting that is not in `$changes` keeps
     * what it holds.
     *
     * @param  array{registration_open?: bool, invitation_sign_up?: bool, share_links_enabled?: bool}  $changes  The new values by name.
     */
    public function update(array $changes): void
    {
        $this->write($changes);
    }

    /**
     * Writes the values of the given settings, one row each, in one statement, and forgets what was read.
     *
     * @param  array<string, bool|int>  $changes  The new values by name.
     */
    private function write(array $changes): void
    {
        $now = now();
        $rows = [];

        foreach ($changes as $key => $value) {
            $rows[] = ['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now];
        }

        if ($rows === []) {
            return;
        }

        $this->db->table('settings')->upsert($rows, ['key'], ['value', 'updated_at']);

        $this->stored = null;
    }

    /**
     * Every setting with its current value, as the API shows them.
     *
     * @return array{registration_open: bool, invitation_sign_up: bool, share_links_enabled: bool}
     */
    public function all(): array
    {
        return [
            self::REGISTRATION_OPEN => $this->registrationOpen(),
            self::INVITATION_SIGN_UP => $this->invitationSignUp(),
            self::SHARE_LINKS_ENABLED => $this->shareLinksEnabled(),
        ];
    }

    /**
     * The value of a setting that holds a boolean: what it is when it has a row, else its default, and the safe value (reported once in
     * the log) when the row holds something else.
     *
     * @param  bool  $whenMissing  The default: what the setting holds when it has no row.
     * @param  bool  $whenUnusable  The safe value: what it holds when its row is not a boolean.
     */
    private function flag(string $key, bool $whenMissing, bool $whenUnusable): bool
    {
        $this->stored ??= $this->load();

        if (! array_key_exists($key, $this->stored)) {
            return $whenMissing;
        }

        $value = $this->stored[$key];

        if (is_bool($value)) {
            return $value;
        }

        $this->reportUnusable($key);

        return $whenUnusable;
    }

    /**
     * Reads every row of the table: key and decoded value. A value that is not valid JSON, or that the database did
     * not hand over as text, is kept as null: the row is there (the setting is not at its default) but holds nothing a
     * setting can use.
     *
     * @return array<string, mixed>
     */
    private function load(): array
    {
        $values = [];

        foreach ($this->db->table('settings')->pluck('value', 'key') as $key => $json) {
            $decoded = null;

            if (is_string($json)) {
                $candidate = json_decode($json, true);
                $decoded = json_last_error() === JSON_ERROR_NONE ? $candidate : null;
            }

            $values[(string) $key] = $decoded;
        }

        return $values;
    }

    /**
     * Says in the log, the first time during this request, that the stored value of a setting is unusable. The value
     * itself is not written (it is not known to be harmless), only the name of the setting.
     */
    private function reportUnusable(string $key): void
    {
        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;

        Log::warning('A stored instance setting is unreadable or of the wrong type: its safe value is used.', ['setting' => $key]);
    }
}
