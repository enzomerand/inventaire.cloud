<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

/**
 * The categories the templates are filed under: a closed list, so that the gallery has a stable set of headings.
 *
 * A template names its category by key (`home`, `it`...); the heading a person reads is translated here. Adding a category
 * means adding its key and its label, and nothing else.
 */
final class TemplateCategories
{
    /** The keys, in the order the gallery lists the categories. */
    public const KEYS = ['home', 'business', 'it', 'collection', 'hobby', 'food', 'association'];

    /**
     * Whether the key is one of the categories.
     */
    public static function exists(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /**
     * The heading of a category, in the language of the request; the key itself for one that does not exist.
     */
    public static function label(string $key): string
    {
        return match ($key) {
            'home' => __('Home and family'),
            'business' => __('Business and retail'),
            'it' => __('IT and electronics'),
            'collection' => __('Collections'),
            'hobby' => __('Hobbies and workshop'),
            'food' => __('Food and drink'),
            'association' => __('Associations and clubs'),
            default => $key,
        };
    }
}
