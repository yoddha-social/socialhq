<?php

declare(strict_types=1);

namespace App\SocialHq\Http\Controllers\CompanyAi;

use App\SocialHq\Ai\Company\ProviderKeyRefused;
use App\SocialHq\Ai\Gateway\GatewayException;
use App\SocialHq\Secrets\SecretsKeyMissing;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Turns company AI failures into a message on the form field the owner was
 * filling, in our words: never the provider's or LiteLLM's text.
 */
trait HandlesCompanyAiErrors
{
    /**
     * @param  Closure(): RedirectResponse  $action
     *
     * @throws ValidationException
     */
    private function attempt(string $field, Closure $action): RedirectResponse
    {
        try {
            return $action();
        } catch (ProviderKeyRefused $exception) {
            throw ValidationException::withMessages([$field => __("socialhq_ai.errors.{$exception->errorKey}")]);
        } catch (SecretsKeyMissing $exception) {
            report($exception);

            throw ValidationException::withMessages([$field => __('socialhq_ai.errors.secrets_key_missing')]);
        } catch (GatewayException $exception) {
            report($exception);

            throw ValidationException::withMessages([$field => __("socialhq_ai.errors.{$exception->errorKey}")]);
        }
    }

    private function done(string $message): RedirectResponse
    {
        session()->flash('flash.banner', $message);
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }
}
