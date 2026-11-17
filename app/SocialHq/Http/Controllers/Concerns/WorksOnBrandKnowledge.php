<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Concerns;

use App\Models\User;
use App\Models\Workspace;
use App\SocialHq\Knowledge\BrandKnowledge;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Brand knowledge controllers' shared steps: the current workspace,
 * which the user may change (or, with $edit false, read), and the success
 * banner after a change.
 */
trait WorksOnBrandKnowledge
{
    protected function workspace(Request $request, bool $edit = true): Workspace
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);
        abort_unless($edit ? BrandKnowledge::canEdit($user, $workspace) : BrandKnowledge::canView($user, $workspace), Response::HTTP_FORBIDDEN);

        return $workspace;
    }

    protected function saved(string $message): RedirectResponse
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
