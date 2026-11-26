<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Documents;

use Illuminate\Support\Uri;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;

/**
 * Renders a Markdown file someone uploaded for its preview, as HTML the
 * page may show: HTML written in the file is shown as text (escaped, never
 * run), a picture never loads (its description stays as text, so opening
 * the preview fetches nothing from another site), and only http(s) links
 * stay links, opening in a new tab with no referrer and no access to the
 * app's window. Any other link (relative, javascript:, data:, mailto:)
 * becomes its text. Nesting is capped so a hostile file can't exhaust the
 * parser.
 */
final class SafeMarkdown
{
    private const int MAX_NESTING = 50;

    private const array LINK_SCHEMES = ['http', 'https'];

    public static function toHtml(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => self::MAX_NESTING,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addEventListener(DocumentParsedEvent::class, self::neutralise(...));

        return (string) (new MarkdownConverter($environment))->convert($markdown);
    }

    private static function neutralise(DocumentParsedEvent $event): void
    {
        // Collected first: the tree changes while they are handled.
        $nodes = iterator_to_array($event->getDocument()->iterator(), false);

        foreach ($nodes as $node) {
            if ($node instanceof Image) {
                self::unwrap($node);
            } elseif ($node instanceof Link) {
                if (! in_array(self::scheme($node->getUrl()), self::LINK_SCHEMES, true)) {
                    self::unwrap($node);

                    continue;
                }

                $node->data->set('attributes/target', '_blank');
                $node->data->set('attributes/rel', 'noopener noreferrer nofollow');
            }
        }
    }

    /**
     * The node's content takes its place (a link's text, a picture's description).
     */
    private static function unwrap(Node $node): void
    {
        foreach ($node->children() as $child) {
            $node->insertBefore($child);
        }

        $node->detach();
    }

    private static function scheme(string $url): ?string
    {
        $scheme = rescue(fn (): ?string => Uri::of(trim($url))->scheme(), report: false);

        return is_string($scheme) ? strtolower($scheme) : null;
    }
}
