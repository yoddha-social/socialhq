<?php

declare(strict_types=1);

test('.env.example documents every key config/socialhq.php reads', function () {
    preg_match_all("/env\\('([A-Z0-9_]+)'/", file_get_contents(base_path('config/socialhq.php')), $matches);
    $example = file_get_contents(base_path('.env.example'));

    $missing = collect($matches[1])
        ->unique()
        ->reject(fn (string $key) => preg_match("/^#? ?{$key}=/m", $example) === 1)
        ->values()
        ->all();

    expect($missing)->toBe([]);
});
