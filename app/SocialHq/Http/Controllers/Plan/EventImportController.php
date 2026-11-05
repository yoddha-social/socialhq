<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\ImportOfficialEvents;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * "Import official dates": loads the shipped dataset into the account,
 * unconfirmed, keeping confirmations and the team's edits.
 */
class EventImportController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);

        try {
            $counts = ImportOfficialEvents::handle($workspace->account);
        } catch (InvalidArgumentException $exception) {
            report($exception);
            session()->flash('flash.banner', __('socialhq_events.import.failed'));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        }

        $message = __('socialhq_events.import.done', [
            'created' => $counts['created'],
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped'],
        ]);

        if ($counts['missing'] > 0) {
            $message .= ' '.trans_choice('socialhq_events.import.missing', $counts['missing'], ['count' => $counts['missing']]);
        }

        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
