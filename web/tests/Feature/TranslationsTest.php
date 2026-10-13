<?php

declare(strict_types=1);

/**
 * Lists the English source strings the code asks the translator for.
 *
 * Only literal strings are found: a message built dynamically has to be added
 * to lang/fr.json by hand. Installer exceptions carry their English text as a
 * constructor argument (they translate it lazily), hence the second pattern.
 *
 * @return array<string, string> English text => first file using it.
 */
function translatedStringsInCode(): array
{
    $found = [];
    $files = [];

    foreach (['app' => '.php', 'resources/views' => '.blade.php'] as $directory => $extension) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), $extension)) {
                $files[] = $file->getPathname();
            }
        }
    }

    foreach ($files as $path) {
        preg_match_all("/(?:__\\(|new InstallationException\\()\\s*(?:'((?:[^'\\\\\\\\]|\\\\\\\\.)*)'|\"((?:[^\"\\\\\\\\]|\\\\\\\\.)*)\")/s", (string) file_get_contents($path), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $text = ($match[1] ?? '') !== '' ? str_replace(["\\'", '\\\\'], ["'", '\\'], $match[1]) : stripcslashes($match[2] ?? '');

            $found[$text] ??= str_replace(base_path().'/', '', $path);
        }
    }

    return $found;
}

it('has a French translation for every string the code uses', function (): void {
    $french = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = array_diff_key(translatedStringsInCode(), $french);

    expect($missing)->toBe([], 'Add these to lang/fr.json: '.implode(' | ', array_keys($missing)));
});

it('finds the strings it is supposed to check', function (): void {
    $strings = translatedStringsInCode();

    expect($strings)->toHaveKey('Skip to content')->toHaveKey('Cannot write :path.');
});

it('keeps placeholders identical in both languages', function (): void {
    $french = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach (translatedStringsInCode() as $english => $file) {
        preg_match_all('/:[a-z_]+/', $english, $source);
        preg_match_all('/:[a-z_]+/', (string) ($french[$english] ?? ''), $target);

        expect($target[0])->toEqualCanonicalizing($source[0], "Placeholders differ for: {$english}");
    }
});
