<?php

declare(strict_types=1);

namespace App\SocialHq\Production;

use App\SocialHq\Plan\CreativeKind;
use App\SocialHq\Plan\PlanItem;

/**
 * The format a brief starts with, read from the item's content type: size,
 * ratio, slides and video length, as text the designer can edit.
 */
final class FormatNotes
{
    public static function for(PlanItem $item): string
    {
        $type = $item->content_type;

        if ($type === null) {
            return '';
        }

        $lines = [];
        $dimensions = $type->preferredImageDimensions();
        $ratio = $type->aspectRatio();

        if ($item->creative === CreativeKind::Video) {
            if ($ratio !== null) {
                $lines[] = __('socialhq_production.brief.format.ratio', ['ratio' => $ratio]);
            }

            $seconds = $type->maxVideoDurationSec();

            if ($seconds !== null) {
                $lines[] = $seconds >= 120
                    ? __('socialhq_production.brief.format.video_minutes', ['minutes' => intdiv($seconds, 60)])
                    : __('socialhq_production.brief.format.video_seconds', ['seconds' => $seconds]);
            }
        } else {
            $lines[] = __('socialhq_production.brief.format.size', ['width' => $dimensions['width'], 'height' => $dimensions['height']]);

            if ($ratio !== null) {
                $lines[] = __('socialhq_production.brief.format.ratio', ['ratio' => $ratio]);
            }
        }

        if ($item->creative === CreativeKind::Carousel && $type->maxMediaCount() > 1) {
            $lines[] = __('socialhq_production.brief.format.slides', ['count' => $type->maxMediaCount()]);
        }

        if ($item->creative === CreativeKind::Document) {
            $lines[] = __('socialhq_production.brief.format.document');
        }

        $lines[] = __('socialhq_production.brief.format.for', ['format' => $type->label(), 'channel' => $type->platform()->label()]);

        return implode("\n", $lines);
    }
}
