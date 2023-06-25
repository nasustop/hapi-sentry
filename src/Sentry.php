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

use Http\Discovery\Psr17FactoryDiscovery;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Logger\LoggerFactory;
use Sentry\ClientBuilder;
use Sentry\SentrySdk;
use Throwable;

class Sentry
{
    public function __construct()
    {
        /* @var $configFactory ConfigInterface */
        $configFactory = make(ConfigInterface::class);
        $config = $configFactory->get('sentry');
        if (! empty($config['dsn']) && SentrySdk::getCurrentHub()->getClient() == null) {
            $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
            $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
            /* @var $loggerFactory LoggerFactory */
            $loggerFactory = make(LoggerFactory::class);
            $logger = $loggerFactory->get('sentry', $config['logger'] ?? 'default');
            $client = ClientBuilder::create([
                'dsn' => $config['dsn'],
            ])
                ->setTransportFactory(new \Nasustop\HapiSentry\Transport\SwooleTransportFactory(
                    $streamFactory,
                    $requestFactory,
                    $logger,
                ))
                ->getClient();

            SentrySdk::init()->bindClient($client);
        }
    }

    public function captureException(Throwable $throwable)
    {
        \Sentry\captureException($throwable);
    }
}
