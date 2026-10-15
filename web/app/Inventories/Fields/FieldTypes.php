<?php

declare(strict_types=1);

namespace App\Inventories\Fields;

use App\Models\InventoryField;

/**
 * The registry of field types: every type the application knows, by its key, in the order they are offered.
 *
 * It is the only place that lists the types. A field row only holds the key; whatever has to know what a value looks
 * like (validating an item, showing it, later importing or exporting it) asks the registry for the type.
 */
final class FieldTypes
{
    /** @var array<string, FieldType> */
    private array $types = [];

    /**
     * Builds the registry from the types, in the order they are offered.
     *
     * @param  iterable<FieldType>  $types  In the order they are offered. A key listed twice keeps its first place and its last class.
     */
    public function __construct(iterable $types)
    {
        foreach ($types as $type) {
            $this->types[$type->key()] = $type;
        }
    }

    /**
     * The registry of the application: every type there is.
     */
    public static function standard(): self
    {
        return new self([
            new TextType,
            new TextareaType,
            new NumberType,
            new MoneyType,
            new PercentageType,
            new BooleanType,
            new DateType,
            new DateTimeType,
            new TimeType,
            new SelectType,
            new MultiselectType,
            new TagsType,
            new UrlType,
            new EmailType,
            new PhoneType,
            new RatingType,
            new ColorType,
            new BarcodeType,
        ]);
    }

    /**
     * The type with this key.
     *
     * @throws UnknownFieldTypeException When there is none.
     */
    public function get(string $key): FieldType
    {
        return $this->types[$key] ?? throw new UnknownFieldTypeException($key);
    }

    /**
     * The type with this key, or null when there is none.
     */
    public function find(string $key): ?FieldType
    {
        return $this->types[$key] ?? null;
    }

    /**
     * The type of a field, or null when its row names one this version does not know (after a rollback, say).
     */
    public function of(InventoryField $field): ?FieldType
    {
        return $this->find($field->type);
    }

    /**
     * Whether there is a type with this key.
     */
    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /**
     * The keys of every type, in the order they are offered.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->types);
    }

    /**
     * Every type, in the order they are offered.
     *
     * @return list<FieldType>
     */
    public function all(): array
    {
        return array_values($this->types);
    }
}
