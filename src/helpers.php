<?php

declare(strict_types=1);
/**
 * This file is part of Hapi.
 *
 * @link     https://www.nasus.top
 * @document https://wiki.nasus.top
 * @contact  xupengfei@xupengfei.net
 * @license  https://github.com/nasustop/hapi-sentry/blob/master/LICENSE
 */

use Nasustop\HapiSentry\Sentry;

if (! function_exists('sentryException')) {
    function sentryException(Throwable $throwable)
    {
        /* @var $sentry Sentry */
        $sentry = make(Sentry::class);
        $sentry->captureException($throwable);
    }
}
