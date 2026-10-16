<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

/**
 * One field of a template, in one language: what a field of an inventory is made from.
 *
 * `options` holds the options of the type other than the choices; `choices` holds the choices of a select or multiple
 * choice, each with the `key` the examples and the file use to name it (the stored value is an id the server makes when
 * the field is created, as for any field).
 */
final readonly class TemplateField
{
    /**
     * @param  string  $key  The word the examples use for the field.
     * @param  string  $type  A key of the registry of field types.
     * @param  string  $name  In the language of the template instance.
     * @param  bool  $required  Whether an item must have a value.
     * @param  array<string, mixed>  $options  The options of the type, without the choices.
     * @param  list<array{key: string, label: string, color: string|null}>  $choices  The choices, in order.
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $name,
        public bool $required,
        public array $options,
        public array $choices,
    ) {}

    /**
     * The options as the creation of a field takes them: the choices carry a label and a color, and no id (the server
     * makes the ids).
     *
     * @return array<string, mixed>
     */
    public function creationOptions(): array
    {
        if ($this->choices === []) {
            return $this->options;
        }

        return $this->options + ['choices' => array_map(
            fn (array $choice): array => ['label' => $choice['label']] + ($choice['color'] === null ? [] : ['color' => $choice['color']]),
            $this->choices,
        )];
    }

    /**
     * The options as the public API gives them: the choices keep the `key` the examples name them by.
     *
     * @return array<string, mixed>
     */
    public function publicOptions(): array
    {
        return $this->choices === [] ? $this->options : $this->options + ['choices' => $this->choices];
    }
}
