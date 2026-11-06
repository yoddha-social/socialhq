<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Website;

/**
 * A site's robots.txt, as RFC 9309 reads it: the group for our user agent
 * (or "*"), the longest matching Allow or Disallow rule wins (Allow on a
 * tie), "*" and "$" in paths. Also the Crawl-delay and the sitemaps listed.
 */
final readonly class RobotsTxt
{
    public const USER_AGENT = 'socialhqbot';

    /**
     * @param  list<array{allow: bool, pattern: string}>  $rules
     * @param  list<string>  $sitemaps
     */
    private function __construct(
        private array $rules,
        public ?float $crawlDelay,
        public array $sitemaps,
    ) {}

    public static function allowAll(): self
    {
        return new self([], null, []);
    }

    public static function disallowAll(): self
    {
        return new self([['allow' => false, 'pattern' => '/']], null, []);
    }

    public static function parse(string $content): self
    {
        $groups = [];
        $current = null;
        $lastWasAgent = false;
        $sitemaps = [];

        foreach (preg_split('/\r\n|\r|\n/', mb_substr($content, 0, 500000)) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if (! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map(trim(...), explode(':', $line, 2));
            $field = strtolower($field);

            if ($field === 'sitemap') {
                $sitemaps[] = $value;

                continue;
            }

            if ($field === 'user-agent') {
                if (! $lastWasAgent || $current === null) {
                    $groups[] = ['agents' => [], 'rules' => [], 'delay' => null];
                    $current = array_key_last($groups);
                }

                $groups[$current]['agents'][] = strtolower($value);
                $lastWasAgent = true;

                continue;
            }

            $lastWasAgent = false;

            if ($current === null) {
                continue;
            }

            if (in_array($field, ['allow', 'disallow'], true) && $value !== '') {
                $groups[$current]['rules'][] = ['allow' => $field === 'allow', 'pattern' => $value];
            } elseif ($field === 'crawl-delay' && is_numeric($value)) {
                $groups[$current]['delay'] = (float) $value;
            }
        }

        $ours = array_values(array_filter($groups, fn (array $group): bool => collect($group['agents'])->contains(fn (string $agent): bool => ! in_array($agent, ['*', ''], true) && str_contains(self::USER_AGENT, explode('/', $agent)[0]))));
        $chosen = $ours !== [] ? $ours : array_values(array_filter($groups, fn (array $group): bool => in_array('*', $group['agents'], true)));

        return new self(
            array_merge(...array_map(fn (array $group): array => $group['rules'], $chosen ?: [['rules' => []]])),
            collect($chosen)->pluck('delay')->filter(fn (?float $delay): bool => $delay !== null)->max(),
            array_values(array_unique($sitemaps)),
        );
    }

    /**
     * Whether we may fetch this path (with its query string).
     */
    public function allows(string $path): bool
    {
        $best = null;

        foreach ($this->rules as $rule) {
            $length = strlen($rule['pattern']);

            if (! self::matches($rule['pattern'], $path)) {
                continue;
            }

            if ($best === null || $length > $best['length'] || ($length === $best['length'] && $rule['allow'])) {
                $best = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $best === null || $best['allow'];
    }

    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $pattern = $anchored ? substr($pattern, 0, -1) : $pattern;
        $regex = '#^'.implode('.*', array_map(fn (string $part): string => preg_quote(rawurldecode($part), '#'), explode('*', $pattern))).($anchored ? '$' : '').'#';

        return preg_match($regex, rawurldecode($path)) === 1;
    }
}
