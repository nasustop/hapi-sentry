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
namespace Nasustop\HapiSentry\Transport;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Sentry\Options;
use Sentry\Serializer\PayloadSerializer;
use Sentry\Transport\NullTransport;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

final class SwooleTransportFactory implements TransportFactoryInterface
{
    /**
     * @var StreamFactoryInterface A PSR-7 stream factory
     */
    private StreamFactoryInterface $streamFactory;

    /**
     * @var RequestFactoryInterface A PSR-7 request factory
     */
    private RequestFactoryInterface $requestFactory;

    /**
     * @var null|LoggerInterface A PSR-3 logger
     */
    private ?LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param StreamFactoryInterface $streamFactory The PSR-7 stream factory
     * @param RequestFactoryInterface $requestFactory The PSR-7 request factory
     * @param null|LoggerInterface $logger An optional PSR-3 logger
     */
    public function __construct(StreamFactoryInterface $streamFactory, RequestFactoryInterface $requestFactory, ?LoggerInterface $logger = null)
    {
        $this->streamFactory = $streamFactory;
        $this->requestFactory = $requestFactory;
        $this->logger = $logger;
    }

    public function create(Options $options): TransportInterface
    {
        if ($options->getDsn() === null) {
            return new NullTransport();
        }

        return new SwooleHttpTransport(
            $options,
            $this->streamFactory,
            $this->requestFactory,
            new PayloadSerializer($options),
            $this->logger
        );
    }
}
