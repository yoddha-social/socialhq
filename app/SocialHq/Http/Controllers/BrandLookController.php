<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\SocialHq\Http\Requests\StoreBrandLogoRequest;
use App\SocialHq\Http\Requests\UpdateBrandLookRequest;
use App\SocialHq\Knowledge\BrandKnowledge;
use App\SocialHq\Knowledge\Look\BrandLook;
use App\SocialHq\Knowledge\Look\LogoStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as BytesResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Brand look" on Brand knowledge: the logo, colours, fonts and overall
 * visual style of the current workspace. Workspace admins change it;
 * everyone who reads brand knowledge sees the logo.
 */
class BrandLookController extends Controller
{
    public function update(UpdateBrandLookRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);

        BrandLook::query()->updateOrCreate(['workspace_id' => $workspace->id], [
            'colours' => $request->colours(),
            'heading_font' => trim((string) $request->validated('heading_font')) ?: null,
            'body_font' => trim((string) $request->validated('body_font')) ?: null,
            'style_notes' => trim((string) $request->validated('style_notes')) ?: null,
            'updated_by' => $request->user()->id,
        ]);

        return $this->saved(__('socialhq_knowledge.look.flash.saved'));
    }

    public function storeLogo(StoreBrandLogoRequest $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);
        $logo = $request->logo();
        $path = LogoStorage::store($workspace, $logo);

        DB::transaction(function () use ($workspace, $logo, $path, $request): void {
            $look = BrandLook::query()->lockForUpdate()->firstOrNew(['workspace_id' => $workspace->id]);
            LogoStorage::deleteLater($look->logo_path);

            $look->fill([
                'logo_path' => $path,
                'logo_mime' => $logo->mime,
                'logo_size' => strlen($logo->bytes),
                'logo_width' => $logo->width,
                'logo_height' => $logo->height,
                'updated_by' => $request->user()->id,
            ])->save();
        });

        return $this->saved(__('socialhq_knowledge.look.flash.logo_saved'));
    }

    public function showLogo(Request $request): BytesResponse
    {
        $look = BrandLook::of($this->workspace($request, edit: false));

        abort_unless($look?->hasLogo() && LogoStorage::disk()->exists((string) $look->logo_path), Response::HTTP_NOT_FOUND);

        return response((string) LogoStorage::disk()->get((string) $look->logo_path), Response::HTTP_OK, [
            'Content-Type' => (string) $look->logo_mime,
            'X-Content-Type-Options' => 'nosniff',
            // Opened on its own, an SVG still runs nothing and loads nothing.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function destroyLogo(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request, edit: true);

        DB::transaction(function () use ($workspace, $request): void {
            $look = BrandLook::query()->lockForUpdate()->where('workspace_id', $workspace->id)->first();

            if ($look?->hasLogo()) {
                LogoStorage::deleteLater($look->logo_path);
                $look->update(['logo_path' => null, 'logo_mime' => null, 'logo_size' => null, 'logo_width' => null, 'logo_height' => null, 'updated_by' => $request->user()->id]);
            }
        });

        return $this->saved(__('socialhq_knowledge.look.flash.logo_removed'));
    }

    private function workspace(Request $request, bool $edit): Workspace
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        abort_if($workspace === null, Response::HTTP_NOT_FOUND);
        abort_unless($edit ? BrandKnowledge::canEdit($user, $workspace) : BrandKnowledge::canView($user, $workspace), Response::HTTP_FORBIDDEN);

        return $workspace;
    }

    private function saved(string $message): RedirectResponse
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
