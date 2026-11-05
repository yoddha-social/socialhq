<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests\Plan;

use App\Enums\Media\Type as MediaType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * A file for the current creative round: the types and size limits TryPost
 * accepts for post media (images, videos, PDFs), checked by content.
 */
class StoreBriefFileRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $types = [MediaType::Image, MediaType::Video, MediaType::Document];
        $mimeTypes = collect($types)->flatMap(fn (MediaType $type): array => $type->allowedMimeTypes())->unique()->implode(',');
        $largest = collect($types)->max(fn (MediaType $type): int => $type->maxSizeInKb());

        return [
            'file' => ['required', 'file', "mimetypes:{$mimeTypes}", "max:{$largest}"],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $type = $this->mediaType();
                $file = $this->file('file');

                if ($type !== null && $file instanceof UploadedFile && $file->getSize() > $type->maxSizeInBytes()) {
                    $validator->errors()->add('file', __('posts.composer.upload_errors.too_large', ['size' => $type->maxSizeInMb()]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimetypes' => __('socialhq_production.creative.errors.type'),
        ];
    }

    /**
     * The file's type, from its content.
     */
    public function mediaType(): ?MediaType
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? MediaType::fromMime((string) $file->getMimeType()) : null;
    }
}
