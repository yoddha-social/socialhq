<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Http\Requests\App\Post\ApprovePostRequest;
use App\Http\Requests\App\Post\RejectPostRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;

class PostApprovalController extends Controller
{
    public function approve(ApprovePostRequest $request, Post $post): RedirectResponse
    {
        ApprovePost::execute($post, $request->user(), $request->validated());

        return back();
    }

    public function reject(RejectPostRequest $request, Post $post): RedirectResponse
    {
        RejectPost::execute($post, $request->user(), $request->validated('reason'));

        return back();
    }
}
