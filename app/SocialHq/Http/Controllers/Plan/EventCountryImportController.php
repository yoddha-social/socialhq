<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\Plan;

use App\Http\Controllers\Controller;
use App\SocialHq\Events\ImportPublicHolidays;
use App\SocialHq\Events\Nager\NagerDate;
use App\SocialHq\Events\Nager\NagerDateUnavailable;
use App\SocialHq\Http\Requests\Plan\ImportHolidayCountriesRequest;
use App\SocialHq\Plan\PlanAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * "Add a country": imports countries' public holidays from Nager.Date into
 * the account, unconfirmed, keeping confirmations and the team's edits. The
 * dates are shared by every brand, so only who may change shared dates may
 * import them.
 */
class EventCountryImportController extends Controller
{
    public function store(ImportHolidayCountriesRequest $request): RedirectResponse
    {
        $workspace = PlanAccess::workspace($request, approve: true);
        EventController::ensureMayChange($request, $workspace, shared: true);
        $countries = $request->countries();

        try {
            $counts = ImportPublicHolidays::handle($workspace->account, $countries, $request->years());
        } catch (NagerDateUnavailable $exception) {
            Log::warning('Social HQ: Nager.Date import failed.', ['countries' => $exception->countries, 'message' => $exception->getMessage()]);
            session()->flash('flash.banner', __('socialhq_events.countries.unavailable', ['countries' => self::names($exception->countries ?: $countries)]));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        } catch (InvalidArgumentException $exception) {
            report($exception);
            session()->flash('flash.banner', __('socialhq_events.countries.failed'));
            session()->flash('flash.bannerStyle', 'danger');

            return back();
        }

        $message = __('socialhq_events.countries.done', [
            'countries' => self::names($countries),
            'created' => $counts['created'],
            'updated' => $counts['updated'],
            'skipped' => $counts['skipped'],
        ]);

        if ($counts['missing'] > 0) {
            $message .= ' '.trans_choice('socialhq_events.countries.missing', $counts['missing'], ['count' => $counts['missing']]);
        }

        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    /**
     * The countries' names as Nager.Date lists them, or their codes when the list can't be read.
     *
     * @param  list<string>  $codes
     */
    private static function names(array $codes): string
    {
        $names = rescue(fn (): array => collect(NagerDate::countries())->pluck('name', 'code')->all(), [], report: false);

        return collect($codes)->map(fn (string $code): string => data_get($names, $code, $code))->join(', ', ' and ');
    }
}
