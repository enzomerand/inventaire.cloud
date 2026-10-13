<?php

declare(strict_types=1);

namespace App\Installer;

/**
 * One input of a wizard step, described once for the web form, the console
 * prompts and the validation.
 */
final readonly class Field
{
    /**
     * @param  string  $name  Key of the input in requests and forms.
     * @param  string|null  $envKey  .env variable holding the value; null for values that are used once and never stored.
     * @param  string  $label  Translated label.
     * @param  string  $type  text, number, password, email, url, select or checkbox.
     * @param  bool  $secret  Whether the value is never sent back to the browser (masked).
     * @param  bool  $required  Whether an empty value is refused.
     * @param  string|null  $default  Value suggested while nothing is configured.
     * @param  string|null  $help  Translated hint shown under the input.
     * @param  string|null  $placeholder  Translated placeholder.
     * @param  list<array{value: string, label: string}>  $options  Choices of a select.
     * @param  array{field: string, value: string}|null  $showWhen  Display condition: another field must hold this value.
     */
    public function __construct(
        public string $name,
        public ?string $envKey,
        public string $label,
        public string $type = 'text',
        public bool $secret = false,
        public bool $required = true,
        public ?string $default = null,
        public ?string $help = null,
        public ?string $placeholder = null,
        public array $options = [],
        public ?array $showWhen = null,
    ) {}
}
