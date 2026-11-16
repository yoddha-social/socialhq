<?php

declare(strict_types=1);

use App\SocialHq\Knowledge\Look\SvgSanitizer;
use App\SocialHq\Knowledge\SafeXml;
use App\SocialHq\Knowledge\Website\Sitemap;

function utf16(string $xml): string
{
    return "\xFF\xFE".mb_convert_encoding(str_replace('encoding="UTF-8"', 'encoding="UTF-16"', $xml), 'UTF-16LE', 'UTF-8');
}

test('a DTD hidden from the text check by UTF-16 still refuses the file', function () {
    $svg = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE svg [<!ENTITY x "boom">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text><rect width="1" height="1"/></svg>';
    $sitemap = '<?xml version="1.0" encoding="UTF-8"?><!DOCTYPE urlset [<!ENTITY x "boom">]><urlset><url><loc>https://acme.example/&x;</loc></url></urlset>';

    expect(stripos(utf16($svg), '<!DOCTYPE'))->toBeFalse()
        ->and(SafeXml::load(utf16($svg)))->toBeNull()
        ->and(SvgSanitizer::sanitize(utf16($svg)))->toBeNull()
        ->and(Sitemap::parse(utf16($sitemap)))->toBe(['pages' => [], 'sitemaps' => []]);

    // The same files without their DTD are read.
    $plainSvg = '<?xml version="1.0" encoding="UTF-8"?><svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';
    $plainSitemap = '<?xml version="1.0" encoding="UTF-8"?><urlset><url><loc>https://acme.example/a</loc></url></urlset>';

    expect(SvgSanitizer::sanitize(utf16($plainSvg)))->toContain('<rect')
        ->and(Sitemap::parse(utf16($plainSitemap)))->toBe(['pages' => ['https://acme.example/a'], 'sitemaps' => []]);
});

test('CSS escapes cannot spell a url() or an @import past the SVG checks', function () {
    $svg = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg">
          <style>@\69mport "https://evil.example/x.css";</style>
          <path d="M0 0h1v1z" style="fill:\75 rl(https://evil.example/p)"/>
          <rect width="1" height="1" fill="\75 rl(https://evil.example/q)"/>
        </svg>
        SVG;

    expect(SvgSanitizer::sanitize($svg))
        ->toContain('<path')->toContain('<rect')
        ->not->toContain('evil.example')->not->toContain('\\');
});
