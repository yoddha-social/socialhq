<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Http\Requests\Plan\StoreBriefFileRequest;
use App\SocialHq\Plan\PlanAccess;
use App\SocialHq\Production\BriefFile;
use App\SocialHq\Production\BriefRounds;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files uploaded for a brief's creative rounds. They are private: served
 * only through this controller to people who work in the workspace.
 */
class BriefFileController extends Controller
{
    public function store(StoreBriefFileRequest $request, int $item): RedirectResponse
    {
        $brief = PlanAccess::item($request, $item)->brief ?? abort(Response::HTTP_NOT_FOUND);

        BriefRounds::upload($brief, $request->user(), $request->file('file'), (string) $request->file('file')->getMimeType(), $request->validated('note'));

        return back();
    }

    public function show(Request $request, int $item, int $file): StreamedResponse
    {
        $brief = PlanAccess::item($request, $item)->brief ?? abort(Response::HTTP_NOT_FOUND);
        $stored = BriefFile::query()->where('brief_id', $brief->id)->findOrFail($file);

        abort_unless(Storage::exists($stored->path), Response::HTTP_NOT_FOUND);

        return Storage::response($stored->path, $stored->original_name, [
            'Content-Type' => $stored->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }
}
