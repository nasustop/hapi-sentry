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

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Http\Client\Common\Plugin;
use Http\Client\Common\Plugin\AuthenticationPlugin;
use Http\Client\Common\Plugin\DecoderPlugin;
use Http\Client\Common\Plugin\ErrorPlugin;
use Http\Client\Common\Plugin\HeaderSetPlugin;
use Http\Client\Common\Plugin\RetryPlugin;
use Http\Client\Common\PluginChain;
use Http\Promise\Promise;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Sentry\Event;
use Sentry\EventType;
use Sentry\HttpClient\Authentication\SentryAuthentication;
use Sentry\HttpClient\Plugin\GzipEncoderPlugin;
use Sentry\Options;
use Sentry\Response;
use Sentry\ResponseStatus;
use Sentry\Serializer\PayloadSerializerInterface;
use Sentry\Transport\RateLimiter;
use Sentry\Transport\TransportInterface;
use Swoole\Coroutine\Http\Client;

final class SwooleHttpTransport implements TransportInterface
{
    /**
     * @var Options The Sentry client options
     */
    private Options $options;

    /**
     * @var StreamFactoryInterface The PSR-7 stream factory
     */
    private StreamFactoryInterface $streamFactory;

    /**
     * @var RequestFactoryInterface The PSR-7 request factory
     */
    private RequestFactoryInterface $requestFactory;

    /**
     * @var PayloadSerializerInterface The event serializer
     */
    private PayloadSerializerInterface $payloadSerializer;

    /**
     * @var LoggerInterface A PSR-3 logger
     */
    private LoggerInterface $logger;

    /**
     * @var RateLimiter The rate limiter
     */
    private RateLimiter $rateLimiter;

    /**
     * @var string The SDK identifier, to be used in {@see Event} and {@see SentryAuth}
     */
    private string $sdkIdentifier = \Sentry\Client::SDK_IDENTIFIER;

    /**
     * @var string The SDK version of the Client
     */
    private string $sdkVersion = \Sentry\Client::SDK_VERSION;

    /**
     * Constructor.
     *
     * @param Options $options The Sentry client configuration
     * @param StreamFactoryInterface $streamFactory The PSR-7 stream factory
     * @param RequestFactoryInterface $requestFactory The PSR-7 request factory
     * @param PayloadSerializerInterface $payloadSerializer The event serializer
     * @param null|LoggerInterface $logger An instance of a PSR-3 logger
     */
    public function __construct(
        Options $options,
        StreamFactoryInterface $streamFactory,
        RequestFactoryInterface $requestFactory,
        PayloadSerializerInterface $payloadSerializer,
        ?LoggerInterface $logger = null
    ) {
        $this->options = $options;
        $this->streamFactory = $streamFactory;
        $this->requestFactory = $requestFactory;
        $this->payloadSerializer = $payloadSerializer;
        $this->logger = $logger ?? new NullLogger();
        $this->rateLimiter = new RateLimiter($this->logger);
    }

    public function send(Event $event): PromiseInterface
    {
        $dsn = $this->options->getDsn();

        if ($dsn === null) {
            throw new RuntimeException(sprintf('The DSN option must be set to use the "%s" transport.', self::class));
        }

        $eventType = $event->getType();

        if ($this->rateLimiter->isRateLimited($eventType)) {
            $this->logger->warning(
                sprintf('Rate limit exceeded for sending requests of type "%s".', (string) $eventType),
                ['event' => $event]
            );

            return new RejectedPromise(new Response(ResponseStatus::rateLimit(), $event));
        }

        if (
            $this->options->isTracingEnabled()
            || EventType::transaction() === $eventType
            || EventType::checkIn() === $eventType
        ) {
            $request = $this->requestFactory->createRequest('POST', $dsn->getEnvelopeApiEndpointUrl())
                ->withHeader('Content-Type', 'application/x-sentry-envelope')
                ->withBody($this->streamFactory->createStream($this->payloadSerializer->serialize($event)));
        } else {
            $request = $this->requestFactory->createRequest('POST', $dsn->getStoreApiEndpointUrl())
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($this->payloadSerializer->serialize($event)));
        }

        $this->create($request);
        return new RejectedPromise(new Response(ResponseStatus::success(), $event));
    }

    public function close(?int $timeout = null): PromiseInterface
    {
        return new FulfilledPromise(true);
    }

    private function create(RequestInterface $request)
    {
        $httpClientPlugins = [
            new HeaderSetPlugin(['User-Agent' => $this->sdkIdentifier . '/' . $this->sdkVersion]),
            new AuthenticationPlugin(new SentryAuthentication($this->options, $this->sdkIdentifier, $this->sdkVersion)),
            new RetryPlugin(['retries' => $this->options->getSendAttempts(false)]),
            new ErrorPlugin(['only_server_exception' => true]),
        ];

        if ($this->options->isCompressionEnabled()) {
            $httpClientPlugins[] = new GzipEncoderPlugin($this->streamFactory);
            $httpClientPlugins[] = new DecoderPlugin();
        }
        $pluginChain = $this->createPluginChain($httpClientPlugins, function (RequestInterface $request) {
            $this->sendRequest($request);
        });

        $pluginChain($request);
    }

    private function sendRequest(RequestInterface $request)
    {
        go(function () use ($request) {
            $client = new Client($request->getUri()->getHost(), $request->getUri()->getPort(), $request->getUri()->getScheme() === 'https');
            $client->setMethod($request->getMethod());
            $headers = array_map(function ($value) {
                if (is_array($value)) {
                    return implode(', ', $value);
                }
                return (string) $value;
            }, $request->getHeaders());
            $client->setHeaders($headers);
            $client->setData($request->getBody()->getContents());
            $client->execute($request->getUri()->getPath());
            $response = @json_decode($client->getBody(), true);
            if (empty($response['id'])) {
                $this->logger->warning(
                    'Sentry sending requests error: ',
                    ['response' => $response]
                );
            }
            $client->close();
        });
    }

    /**
     * Create the plugin chain.
     *
     * @param Plugin[] $plugins A plugin chain
     * @param callable $clientCallable Callable making the HTTP call
     *
     * @return callable(RequestInterface): Promise
     */
    private function createPluginChain(array $plugins, callable $clientCallable): callable
    {
        return new PluginChain($plugins, $clientCallable);
    }
}
