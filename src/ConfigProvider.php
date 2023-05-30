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
namespace Nasustop\HapiSentry;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
            ],
            'commands' => [
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'sentry',
                    'description' => 'The config for sentry.',
                    'source' => __DIR__ . '/../publish/sentry.php',
                    'destination' => BASE_PATH . '/config/autoload/sentry.php',
                ],
            ],
        ];
    }
}
