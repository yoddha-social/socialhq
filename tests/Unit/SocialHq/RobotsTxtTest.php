<?php

declare(strict_types=1);

use App\SocialHq\Knowledge\Website\RobotsTxt;

test('rules match with * and $ as RFC 9309 reads them, the longest winning', function () {
    $robots = RobotsTxt::parse(implode("\n", [
        'User-agent: *',
        'Disallow: /private',
        'Allow: /private/press',
        'Disallow: /*.pdf$',
        'Disallow: /tmp/*/drafts',
        'Disallow: /search*q=',
    ]));

    expect($robots->allows('/'))->toBeTrue()
        ->and($robots->allows('/private/staff'))->toBeFalse()
        ->and($robots->allows('/private/press/2083'))->toBeTrue()
        ->and($robots->allows('/files/brochure.pdf'))->toBeFalse()
        ->and($robots->allows('/files/brochure.pdf?v=2'))->toBeTrue()
        ->and($robots->allows('/tmp/a/b/drafts/1'))->toBeFalse()
        ->and($robots->allows('/tmp/drafts'))->toBeTrue()
        ->and($robots->allows('/search?lang=en&q=bags'))->toBeFalse()
        ->and($robots->allows('/search'))->toBeTrue();
});

test('a hostile robots.txt costs linear time and keeps at most 500 rules', function () {
    $hostile = 'Disallow: /'.str_repeat('a*', 2000).'b$';
    $rules = array_map(fn (int $number): string => "Disallow: /section-{$number}/", range(1, 600));
    $robots = RobotsTxt::parse("User-agent: *\n{$hostile}\n".implode("\n", $rules));
    $started = hrtime(true);

    expect($robots->allows('/'.str_repeat('a', 5000)))->toBeTrue()
        ->and((hrtime(true) - $started) / 1e9)->toBeLessThan(1.0)
        ->and($robots->allows('/section-499/'))->toBeFalse()
        ->and($robots->allows('/section-500/'))->toBeTrue();
});
