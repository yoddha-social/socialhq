<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\AiGraphics\GraphicFormat;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAiGraphicRequest extends FormRequest
{
    /**
     * Reference images are checked by content, not by name: real JPEG, PNG
     * or WebP bitmaps (no SVG, which can carry scripts), of a usable size.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The brand's logo, when added, takes one of the reference places.
        $maxImages = max(0, (int) config('socialhq.ai_graphics.max_reference_images', 3) - ($this->boolean('use_brand_logo') ? 1 : 0));
        $maxKilobytes = (int) config('socialhq.ai_graphics.max_reference_kilobytes', 10240);

        return [
            'prompt' => ['required', 'string', 'min:3', 'max:2000'],
            'format' => ['required', Rule::enum(GraphicFormat::class)],
            'references' => ['nullable', 'array', "max:{$maxImages}"],
            'use_brand_look' => ['sometimes', 'boolean'],
            'use_brand_logo' => ['sometimes', 'boolean'],
            'references.*' => [
                'file',
                'mimetypes:image/jpeg,image/png,image/webp',
                "max:{$maxKilobytes}",
                'dimensions:min_width=64,min_height=64,max_width=8192,max_height=8192',
            ],
        ];
    }
}
