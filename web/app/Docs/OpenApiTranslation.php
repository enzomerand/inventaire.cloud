<?php

declare(strict_types=1);

namespace App\Docs;

/**
 * What a translation of the OpenAPI document (`resources/openapi/v1.<locale>.yaml`) is allowed to change, and how it is applied.
 *
 * A translation is an overlay: the tree of the document with only its texts left, the `summary`, `description` and `title`
 * strings, translated. Everything else (the paths, the schemas, the examples, the enums) is read from the English file, so a
 * translation cannot change what the API does, only what the page says about it. Two things describe the same rule, and both live
 * here so that they cannot drift apart: {@see self::texts()} extracts the texts of a document (what an overlay must hold, no more
 * and no less), {@see self::apply()} puts an overlay back into it.
 */
final class OpenApiTranslation
{
    /**
     * The version of the rule: what {@see self::texts()} and {@see self::apply()} take for a text, and how they put it in place.
     *
     * The document made of a translation is kept in the cache under the hash of the two files it is made from, and this number: a
     * change of the rule changes the document without changing either file, so bump it with the change, or the old document keeps
     * being served.
     */
    public const VERSION = 1;

    /** The keys whose string value is a text to translate. */
    public const TEXT_KEYS = ['summary', 'description', 'title'];

    /** The keys whose content is data of the API (an example, a value, a default...), never a text of the document: not looked into. */
    public const DATA_KEYS = ['example', 'value', 'default', 'enum', 'const'];

    /**
     * The keys whose content is a map of names the document chose (the properties of an object), not of keywords: a property
     * can be called `default` or `example`, and what it says about itself is a text like any other.
     */
    public const NAME_MAPS = ['properties'];

    /**
     * The texts of a document, with the tree around them: what a translation of the document must repeat.
     *
     * A map keeps the keys that lead to a text, a list keeps its positions (an empty list stands for an item with no text, so that
     * the positions of the translation are those of the document); a branch with no text is left out.
     *
     * @param  array<array-key, mixed>  $node
     * @param  bool  $names  Whether the keys of the node are names the document chose, which are not keywords whatever they are.
     * @return array<array-key, mixed>|null Null when the node holds no text.
     */
    public static function texts(array $node, bool $names = false): ?array
    {
        $list = array_is_list($node);
        $keywords = ! $names && ! $list;
        $texts = [];
        $found = false;

        foreach ($node as $key => $value) {
            if ($keywords && in_array($key, self::DATA_KEYS, true)) {
                continue;
            }

            if ($keywords && in_array($key, self::TEXT_KEYS, true) && is_string($value)) {
                $texts[$key] = $value;

                continue;
            }

            $branch = is_array($value) ? self::texts($value, $keywords && in_array($key, self::NAME_MAPS, true)) : null;

            if ($list) {
                $texts[$key] = $branch ?? [];
                $found = $found || $branch !== null;
            } elseif ($branch !== null) {
                $texts[$key] = $branch;
            }
        }

        if ($list) {
            return $found ? $texts : null;
        }

        return $texts === [] ? null : $texts;
    }

    /**
     * The document with the texts of a translation in place of its own.
     *
     * Only a non-empty string under a text key, where the document has a string at the same place, is taken from the
     * translation; whatever else it holds (a key the document does not have, a value of another kind, a branch of data) is ignored.
     *
     * @param  array<array-key, mixed>  $document
     * @param  array<array-key, mixed>  $translation
     * @param  bool  $names  Whether the keys of the node are names the document chose, which are not keywords whatever they are.
     * @return array<array-key, mixed>
     */
    public static function apply(array $document, array $translation, bool $names = false): array
    {
        $keywords = ! $names && ! array_is_list($document);

        foreach ($translation as $key => $translated) {
            if (! array_key_exists($key, $document) || ($keywords && in_array($key, self::DATA_KEYS, true))) {
                continue;
            }

            $original = $document[$key];

            if ($keywords && in_array($key, self::TEXT_KEYS, true) && is_string($original)) {
                if (is_string($translated) && trim($translated) !== '') {
                    $document[$key] = $translated;
                }
            } elseif (is_array($original) && is_array($translated)) {
                $document[$key] = self::apply($original, $translated, $keywords && in_array($key, self::NAME_MAPS, true));
            }
        }

        return $document;
    }
}
