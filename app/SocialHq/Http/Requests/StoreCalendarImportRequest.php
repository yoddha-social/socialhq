<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Requests;

use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Calendars\CalendarSpreadsheet;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * The first step of an old calendar import: the file, checked by its
 * extension and by its content.
 */
class StoreCalendarImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->user()?->currentWorkspace;

        return $workspace !== null && BrandKnowledge::canEdit($this->user(), $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv,xlsx', 'max:'.(self::maxMegabytes() * 1024)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');

                if ($validator->errors()->isNotEmpty() || ! $file instanceof UploadedFile) {
                    return;
                }

                if (CalendarSpreadsheet::detectType((string) $file->getRealPath(), $file->getClientOriginalExtension()) === null) {
                    $validator->errors()->add('file', __('socialhq_calendars.errors.type'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $tooBig = __('socialhq_calendars.errors.too_big', ['size' => (string) self::maxMegabytes()]);

        return [
            'file.required' => __('socialhq_calendars.errors.no_file'),
            'file.file' => __('socialhq_calendars.errors.no_file'),
            'file.uploaded' => $tooBig,
            'file.extensions' => __('socialhq_calendars.errors.type'),
            'file.max' => $tooBig,
        ];
    }

    public static function maxMegabytes(): int
    {
        return max(1, (int) config('socialhq.knowledge.calendars.max_file_megabytes', 5));
    }
}
