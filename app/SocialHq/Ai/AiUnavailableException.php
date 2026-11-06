<?php

declare(strict_types=1);

namespace App\SocialHq\Ai;

use Laravel\Ai\Exceptions\AiException;

/**
 * No approved provider can serve the task. An AiException, so the
 * controllers that already catch the SDK's errors show their usual message.
 */
class AiUnavailableException extends AiException {}
