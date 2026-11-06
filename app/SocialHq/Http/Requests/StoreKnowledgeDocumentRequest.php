<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Documents\DocumentType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * A brand knowledge document: PDF, Word, PowerPoint, text or Markdown,
 * checked by content (DocumentType), up to the configured size.
 */
class StoreKnowledgeDocumentRequest extends FormRequest
{
    private ?DocumentType $detected = null;

    /**
     * Only admins upload, so nobody else's file is even opened.
     */
    public function authorize(): bool
    {
        $workspace = $this->user()?->currentWorkspace;

        return $workspace === null || BrandKnowledge::canEdit($this->user(), $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.self::maxKilobytes()],
            'title' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($validator->errors()->has('file') || ! $file instanceof UploadedFile) {
                    return;
                }

                $this->detected = DocumentType::detect((string) $file->getRealPath(), (string) $file->getClientOriginalName());

                if ($this->detected === null) {
                    $validator->errors()->add('file', __('socialhq_knowledge.documents.errors.type'));
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
            'file.required' => __('socialhq_knowledge.documents.errors.required'),
            'file.file' => __('socialhq_knowledge.documents.errors.required'),
            'file.uploaded' => __('socialhq_knowledge.documents.errors.too_large', ['size' => intdiv(self::maxKilobytes(), 1024)]),
            'file.max' => __('socialhq_knowledge.documents.errors.too_large', ['size' => intdiv(self::maxKilobytes(), 1024)]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'title' => __('socialhq_knowledge.documents.dialog.title'),
        ];
    }

    /**
     * The file's type, from its content (set once validation passed).
     */
    public function documentType(): DocumentType
    {
        return $this->detected ?? DocumentType::Text;
    }

    public static function maxKilobytes(): int
    {
        return max(1, (int) config('socialhq.knowledge.documents.max_kilobytes', 10240));
    }
}
