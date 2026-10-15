<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The page of accounts an administrator asks for: the word to search for in names and addresses, and the page.
 */
final class ListUsersRequest extends FormRequest
{
    /** Longest word, in characters. */
    public const SEARCH_MAX_LENGTH = 255;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_MAX_LENGTH],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * The word to search for, or an empty string when the whole list is wanted.
     */
    public function search(): string
    {
        return $this->string('q')->trim()->toString();
    }
}
