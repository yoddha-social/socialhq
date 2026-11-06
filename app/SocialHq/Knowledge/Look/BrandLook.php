<?php

declare(strict_types=1);

namespace App\SocialHq\Knowledge\Look;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A workspace's brand look: its logo (on the private disk, LogoStorage),
 * colours, fonts and notes on the overall visual style. The brief writer
 * and AI graphics read it (promptText()), the copy writer one line of it.
 */
class BrandLook extends Model
{
    public const MAX_COLOURS = 8;

    /** Bitmaps the image model may take as a reference; an SVG logo can't be one. */
    public const RASTER_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    protected $table = 'shq_brand_looks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'colours' => 'array',
            'logo_size' => 'integer',
            'logo_width' => 'integer',
            'logo_height' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function of(Workspace $workspace): ?self
    {
        return self::query()->where('workspace_id', $workspace->id)->first();
    }

    public function hasLogo(): bool
    {
        return filled($this->logo_path);
    }

    /**
     * A logo AI graphics can attach as a reference image (PNG, JPEG or WebP).
     */
    public function hasRasterLogo(): bool
    {
        return $this->hasLogo() && in_array($this->logo_mime, self::RASTER_MIMES, true);
    }

    /**
     * @return list<array{name: string, hex: string}>
     */
    public function colourList(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $colour): ?array => is_array($colour) && is_string(data_get($colour, 'hex'))
                ? ['name' => (string) data_get($colour, 'name', ''), 'hex' => (string) data_get($colour, 'hex')]
                : null,
            (array) $this->colours,
        )));
    }

    /**
     * The look as reference data for an AI prompt, or "" when nothing is set.
     */
    public function promptText(): string
    {
        $colours = array_map(
            fn (array $colour): string => trim("{$this->clean($colour['name'])} {$colour['hex']}"),
            $this->colourList(),
        );
        $fonts = array_filter([
            filled($this->heading_font) ? "headings {$this->clean((string) $this->heading_font)}" : null,
            filled($this->body_font) ? "body text {$this->clean((string) $this->body_font)}" : null,
        ]);

        return implode("\n", array_filter([
            $colours === [] ? null : 'Brand colours: '.implode(', ', $colours),
            $fonts === [] ? null : 'Fonts: '.implode('; ', $fonts),
            $this->hasLogo() ? 'Logo: the team has the logo file; leave room for it.' : null,
            filled($this->style_notes) ? "Overall look:\n{$this->clean((string) $this->style_notes)}" : null,
        ]));
    }

    /**
     * One line for the copy writer: the visual style the caption sits next to.
     */
    public function copyLine(): string
    {
        $notes = trim((string) preg_replace('/\s+/u', ' ', (string) $this->style_notes));

        return $notes === '' ? '' : $this->clean(Str::limit($notes, 300, '…'));
    }

    public static function promptFor(Workspace $workspace): string
    {
        return self::of($workspace)?->promptText() ?? '';
    }

    /**
     * Breaks up runs of three or more double quotes, so nothing can close
     * the """ block the prompt wraps the look in.
     */
    private function clean(string $value): string
    {
        return (string) preg_replace_callback(
            '/"{3,}/',
            fn (array $quotes): string => implode(' ', mb_str_split($quotes[0])),
            trim($value),
        );
    }
}
