<?php

declare(strict_types=1);

/**
 * Reads a supervisord config into [program => [key => value]].
 *
 * @return array<string, array<string, string>>
 */
function supervisordPrograms(string $file): array
{
    $programs = [];
    $current = null;

    foreach (file(base_path($file), FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^\[program:([\w-]+)\]$/', trim($line), $match)) {
            $current = $match[1];
            $programs[$current] = [];

            continue;
        }

        if (str_starts_with(trim($line), '[')) {
            $current = null;

            continue;
        }

        if ($current !== null && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $programs[$current][trim($key)] = trim($value);
        }
    }

    return $programs;
}

// php-fpm serves requests as www-data. A queued job that runs as root and is the
// first to create a storage directory leaves it root-owned and 0700 (the local
// disk's directories are private), and the web can no longer use it.
test('the queue, scheduler and websocket programs run as the web user', function (string $file) {
    $programs = supervisordPrograms($file);

    foreach (['horizon', 'scheduler', 'reverb'] as $program) {
        expect($programs)->toHaveKey($program)
            ->and($programs[$program]['user'] ?? 'root')->toBe('www-data', "{$program} in {$file}");
    }
})->with(['docker/supervisord.prod.conf', 'docker/supervisord.dev.conf']);

test('only php-fpm and nginx keep root, to drop it themselves', function (string $file) {
    $asRoot = collect(supervisordPrograms($file))
        ->filter(fn (array $program) => ($program['user'] ?? 'root') === 'root')
        ->keys()
        ->reject(fn (string $name) => $name === 'vite')
        ->values()
        ->all();

    expect($asRoot)->toBe(['php-fpm', 'nginx']);
})->with(['docker/supervisord.prod.conf', 'docker/supervisord.dev.conf']);

test('the production entrypoint hands storage to the web user on every start', function () {
    expect(file_get_contents(base_path('docker/entrypoint.sh')))
        ->toContain('chown -R www-data:www-data storage bootstrap/cache');
});
