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
if (! function_exists('sentryException')) {
    function sentryException(Throwable $throwable)
    {
        $sentry = \Hyperf\Context\ApplicationContext::getContainer()->get(\Nasustop\HapiSentry\Sentry::class);
        $sentry->captureException($throwable);
    }
}
