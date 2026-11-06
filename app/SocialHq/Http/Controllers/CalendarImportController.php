<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\ConfirmCalendarImportRequest;
use App\SocialHq\Http\Requests\StoreCalendarImportRequest;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Calendars\CalendarFileProblem;
use App\SocialHq\Knowledge\Calendars\CalendarImport;
use App\SocialHq\Knowledge\Calendars\CalendarSheet;
use App\SocialHq\Knowledge\Calendars\CalendarSpreadsheet;
use App\SocialHq\Knowledge\Calendars\CalendarStorage;
use App\SocialHq\Knowledge\Calendars\ColumnMapping;
use App\SocialHq\Knowledge\Calendars\DateOrder;
use App\SocialHq\Knowledge\Calendars\ImportCalendar;
use App\SocialHq\Knowledge\Calendars\OldCalendars;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Old calendars on the Brand knowledge page, as JSON for the import dialog:
 * upload (the file waits on the private disk), confirm the column mapping
 * (its rows become past posts and the file goes), view rows, delete.
 * Workspace admins import and delete; anyone who can post views rows.
 */
class CalendarImportController extends Controller
{
    /** Sample rows shown in the mapping step. */
    public const int SAMPLE_ROWS = 5;

    public function store(StoreCalendarImportRequest $request): JsonResponse
    {
        $workspace = $this->workspace($request, edit: true);

        // Count, then create: two admins importing at once may pass it by one. Accepted.
        if (CalendarImport::query()->where('workspace_id', $workspace->id)->count() >= self::maxImports()) {
            throw ValidationException::withMessages(['file' => __('socialhq_calendars.errors.too_many_imports', ['max' => (string) self::maxImports()])]);
        }

        $file = $request->file('file');
        $type = (string) CalendarSpreadsheet::detectType((string) $file->getRealPath(), $file->getClientOriginalExtension());

        try {
            $sheets = CalendarSpreadsheet::read((string) $file->getRealPath(), $type);
        } catch (CalendarFileProblem $problem) {
            throw ValidationException::withMessages(['file' => $problem->userMessage()]);
        }

        $path = CalendarStorage::newPath($workspace, $type);
        CalendarStorage::disk()->putFileAs(dirname($path), $file, basename($path));

        $import = CalendarImport::query()->create([
            'workspace_id' => $workspace->id,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_type' => $type,
            'path' => $path,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'id' => $import->id,
            'file_name' => $import->file_name,
            'sheets' => array_map(self::preview(...), $sheets),
        ], Response::HTTP_CREATED);
    }

    public function update(ConfirmCalendarImportRequest $request, int $import): JsonResponse
    {
        $found = $this->import($request, $import, edit: true);

        $imported = ImportCalendar::handle(
            $found,
            (int) $request->validated('sheet'),
            $request->mapping(),
            $request->enum('date_order', DateOrder::class),
        );

        return response()->json(['calendar' => OldCalendars::summary($imported->load('creator:id,name'))]);
    }

    public function rows(Request $request, int $import): JsonResponse
    {
        $found = $this->import($request, $import, edit: false);

        return response()->json([
            'rows' => OldCalendars::rows($found),
            'total' => $found->rows_imported,
        ]);
    }

    public function destroy(Request $request, int $import): HttpResponse
    {
        $this->import($request, $import, edit: true)->delete();

        return response()->noContent();
    }

    /**
     * One sheet as the mapping step shows it.
     *
     * @return array<string, mixed>
     */
    private static function preview(CalendarSheet $sheet): array
    {
        $mapping = ColumnMapping::guess($sheet->header);
        $dateOrder = ColumnMapping::guessDateOrder($mapping[ColumnMapping::DATE] === null ? [] : $sheet->column($mapping[ColumnMapping::DATE]));

        return [
            'index' => $sheet->index,
            'name' => $sheet->name,
            'header_row' => $sheet->headerRowNumber,
            'rows' => count($sheet->rows),
            'columns' => $sheet->columns(),
            'samples' => $sheet->samples(self::SAMPLE_ROWS),
            'mapping' => $mapping,
            'date_order' => $dateOrder['order']->value,
            'date_order_certain' => $dateOrder['certain'],
        ];
    }

    private static function maxImports(): int
    {
        return max(1, (int) config('socialhq.knowledge.calendars.max_imports', 50));
    }

    private function workspace(Request $request, bool $edit): Workspace
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);
        abort_unless($edit ? BrandKnowledge::canEdit($user, $workspace) : BrandKnowledge::canView($user, $workspace), Response::HTTP_FORBIDDEN);

        return $workspace;
    }

    /**
     * An import of the current workspace: another workspace's is not found.
     */
    private function import(Request $request, int $id, bool $edit): CalendarImport
    {
        $workspace = $this->workspace($request, $edit);

        return CalendarImport::query()->where('workspace_id', $workspace->id)->findOrFail($id);
    }
}
