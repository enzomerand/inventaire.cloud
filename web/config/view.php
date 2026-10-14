<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Views
|--------------------------------------------------------------------------
|
| The framework's own view settings, with one difference: a relative
| VIEW_COMPILED_PATH is resolved from the application's base path, like the
| APP_*_CACHE variables (Application::normalizeCachePath), instead of from the
| process's working directory, which depends on where a command was started.
| Without the variable, or with an absolute path, nothing changes.
|
*/

return [

    /*
    | Where the Blade templates are looked up.
    */
    'paths' => [
        resource_path('views'),
    ],

    /*
    | Where the compiled templates are stored: storage/framework/views unless
    | VIEW_COMPILED_PATH says otherwise.
    */
    'compiled' => (static function (): string|false {
        $path = env('VIEW_COMPILED_PATH');

        if (! is_string($path) || $path === '') {
            return realpath(storage_path('framework/views'));
        }

        return str_starts_with($path, '/') || str_starts_with($path, '\\') ? $path : base_path($path);
    })(),

];
