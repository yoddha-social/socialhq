<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Look;

/**
 * An uploaded logo, decided by its content: a PNG, JPEG or WebP bitmap of a
 * usable size, or an SVG, which is stored only after SvgSanitizer.
 */
final readonly class LogoFile
{
    private const MIN_PIXELS = 16;

    private const MAX_PIXELS = 8192;

    public function __construct(
        public string $bytes,
        public string $mime,
        public string $extension,
        public ?int $width,
        public ?int $height,
    ) {}

    /**
     * The logo to store, or null when the file is none of these.
     */
    public static function read(string $path): ?self
    {
        $bytes = (string) file_get_contents($path);
        $mime = (string) finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $bytes);

        if (in_array($mime, BrandLook::RASTER_MIMES, true)) {
            $size = @getimagesizefromstring($bytes);

            if ($size === false || data_get($size, 'mime') !== $mime) {
                return null;
            }

            [$width, $height] = [(int) $size[0], (int) $size[1]];

            if (min($width, $height) < self::MIN_PIXELS || max($width, $height) > self::MAX_PIXELS) {
                return null;
            }

            return new self($bytes, $mime, match ($mime) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                default => 'webp',
            }, $width, $height);
        }

        if (! in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true)) {
            return null;
        }

        $svg = SvgSanitizer::sanitize($bytes);

        return $svg === null ? null : new self($svg, 'image/svg+xml', 'svg', null, null);
    }
}
