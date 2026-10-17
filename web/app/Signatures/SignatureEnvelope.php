<?php

declare(strict_types=1);

namespace App\Signatures;

use App\Inventories\Versions\VersionFileName;
use App\Models\Inventory;
use App\Models\InventoryVersion;
use App\Models\Workspace;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * What is sent to Documenso to have a version signed: the title and the file name of the document, our identifier of it, the signers
 * with the box each one signs in, and what the emails say.
 *
 * The signature boxes are the ones measured on the laid-out page when the PDF was written (`field` of each signer, in percentages of
 * the page, counted from the top left): nothing is guessed here, and a signer without one makes the request impossible
 * ({@see DocumensoException::invalidRequest()}). The title, the subject and the message are written in the language of the version,
 * whatever the language of the worker, in fixed words: "{author} invites you to sign official version no. {N} of the inventory
 * “{inventory}” ({workspace}).". The emails are sent in parallel (no order), by Documenso.
 */
final readonly class SignatureEnvelope
{
    /** The longest subject Documenso accepts, in characters. */
    private const SUBJECT_MAX = 254;

    /** The longest message Documenso accepts, in characters. */
    private const MESSAGE_MAX = 5000;

    /** The longest part of the title that is the name of the inventory, in characters. */
    private const NAME_MAX = 150;

    /** The languages of the emails of Documenso; any other is sent in English. */
    private const LANGUAGES = ['fr', 'en'];

    /**
     * @param  list<array{name: string, email: string, fields: list<array{page: int, positionX: float|int, positionY: float|int, width: float|int, height: float|int}>}>  $recipients
     * @param  array<string, mixed>  $meta
     */
    private function __construct(
        public string $title,
        public string $externalId,
        public string $fileName,
        public array $recipients,
        public array $meta,
    ) {}

    /**
     * Builds what is sent for a version.
     *
     * @throws DocumensoException When a signer has no signature box, or the version has no signer.
     */
    public static function forVersion(InventoryVersion $version): self
    {
        $inventory = Inventory::query()->findOrFail($version->inventory_id);
        $previous = App::getLocale();
        App::setLocale($version->locale);

        try {
            return self::build($version, $inventory);
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * Builds it in the language the application is set to.
     */
    private static function build(InventoryVersion $version, Inventory $inventory): self
    {
        $locale = App::getLocale();
        $title = Str::limit($inventory->name, self::NAME_MAX, '…').' — '.__('version :number', ['number' => $version->number]);
        $message = __(':author invites you to sign official version no. :number of the inventory “:inventory” (:workspace).', [
            'author' => $version->author_name,
            'number' => $version->number,
            'inventory' => $inventory->name,
            'workspace' => (string) Workspace::query()->whereKey($inventory->workspace_id)->value('name'),
        ]);

        return new self(
            $title,
            $version->ulid,
            VersionFileName::for($inventory->name, $version->number, 'pdf'),
            self::recipients($version),
            [
                'subject' => Str::limit(__('Signature requested: :title', ['title' => $title]), self::SUBJECT_MAX, '…'),
                'message' => Str::limit($message, self::MESSAGE_MAX, '…'),
                'language' => in_array($locale, self::LANGUAGES, true) ? $locale : 'en',
                'timezone' => config()->string('app.display_timezone'),
                'signingOrder' => 'PARALLEL',
                'distributionMethod' => 'EMAIL',
            ],
        );
    }

    /**
     * The signers as Documenso's recipients, each with their signature box.
     *
     * @return list<array{name: string, email: string, fields: list<array{page: int, positionX: float|int, positionY: float|int, width: float|int, height: float|int}>}>
     *
     * @throws DocumensoException
     */
    private static function recipients(InventoryVersion $version): array
    {
        $recipients = [];

        foreach ($version->signers ?? [] as $signer) {
            $field = $signer['field'] ?? null;

            if (! is_array($field) || ! is_int($field['page'] ?? null) || ! self::isPercentage($field['x'] ?? null) || ! self::isPercentage($field['y'] ?? null)
                || ! self::isPercentage($field['width'] ?? null) || ! self::isPercentage($field['height'] ?? null)) {
                throw DocumensoException::invalidRequest('a signer has no signature area on the page');
            }

            $recipients[] = [
                'name' => (string) ($signer['name'] ?? ''),
                'email' => (string) ($signer['email'] ?? ''),
                'fields' => [[
                    'page' => $field['page'],
                    'positionX' => $field['x'],
                    'positionY' => $field['y'],
                    'width' => $field['width'],
                    'height' => $field['height'],
                ]],
            ];
        }

        if ($recipients === []) {
            throw DocumensoException::invalidRequest('the version has no signer');
        }

        return $recipients;
    }

    /**
     * Whether a value is a number from 0 to 100.
     */
    private static function isPercentage(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && $value >= 0 && $value <= 100;
    }
}
