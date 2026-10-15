<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;
use App\Rules\SingleLineText;
use Closure;

/**
 * Free labels, as many as the person wants up to 30: keywords, brands. Each one is a line of 1 to 50 characters.
 *
 * Tags are trimmed, empty ones are dropped and a tag already there in other capitals is dropped too (the first one
 * written stays), so "Red", "red" and " RED " are one tag.
 */
final class TagsType extends AbstractFieldType
{
    /** Most tags a value may hold. */
    public const MAX_TAGS = 30;

    /** Longest tag, in characters. */
    public const TAG_MAX_LENGTH = 50;

    /**
     * The word that identifies the type in the API and in the database: `tags`.
     */
    public function key(): string
    {
        return 'tags';
    }

    /**
     * What the type is called for a person ("Tags"), in the language of the request.
     */
    public function label(): string
    {
        return __('Tags');
    }

    /**
     * Trims every tag, drops the empty ones and the repeats. Anything that is not a list of texts is left for the rules.
     */
    public function prepare(mixed $value, InventoryField $field): mixed
    {
        if (! is_array($value)) {
            return parent::prepare($value, $field);
        }

        $tags = [];
        $seen = [];

        foreach ($value as $tag) {
            $tag = is_string($tag) ? SingleLineText::trim($tag) : $tag;
            $key = is_string($tag) ? SingleLineText::fold($tag) : null;

            if ($tag === '' || ($key !== null && isset($seen[$key]))) {
                continue;
            }

            if ($key !== null) {
                $seen[$key] = true;
            }

            $tags[] = $tag;
        }

        return $tags;
    }

    /**
     * A list of at most 30 tags, each a line of at most 50 characters.
     *
     * @return list<mixed>
     */
    public function valueRules(InventoryField $field): array
    {
        return ['array', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            if (count($value) > self::MAX_TAGS) {
                $fail(__('The :attribute must not have more than :max tags.', ['max' => self::MAX_TAGS]));

                return;
            }

            foreach ($value as $tag) {
                if (! is_string($tag) || mb_strlen($tag) > self::TAG_MAX_LENGTH) {
                    $fail(__('Each tag of :attribute must be a text of at most :max characters.', ['max' => self::TAG_MAX_LENGTH]));

                    return;
                }

                $problem = SingleLineText::problem($tag);

                if ($problem !== null) {
                    $fail($problem);

                    return;
                }
            }
        }];
    }
}
