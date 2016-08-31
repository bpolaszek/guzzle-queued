<?php

namespace BenTools\GuzzleQueued;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Pheanstalk\Pheanstalk;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * @method ResponseInterface get($uri, array $options = [])
 * @method ResponseInterface head($uri, array $options = [])
 * @method ResponseInterface put($uri, array $options = [])
 * @method ResponseInterface post($uri, array $options = [])
 * @method ResponseInterface patch($uri, array $options = [])
 * @method ResponseInterface delete($uri, array $options = [])
 * @method PromiseInterface getAsync($uri, array $options = [])
 * @method PromiseInterface headAsync($uri, array $options = [])
 * @method PromiseInterface putAsync($uri, array $options = [])
 * @method PromiseInterface postAsync($uri, array $options = [])
 * @method PromiseInterface patchAsync($uri, array $options = [])
 * @method PromiseInterface deleteAsync($uri, array $options = [])
 */
class Client implements ClientInterface {

    const TUBE_REQUESTS  = 'guzzle.queued.requests';
    const TUBE_RESPONSES = 'guzzle.queued.responses';

    /**
     * @var Guzzle
     */
    private $decoratedClient;

    /**
     * @var Pheanstalk
     */
    private $queue;

    /**
     * @var int
     */
    private $ttr;

    /**
     * Client constructor.
     * @param Guzzle     $decoratedClient
     * @param Pheanstalk $queue
     * @param int        $ttr
     */
    public function __construct(Guzzle $decoratedClient, Pheanstalk $queue, $ttr = Pheanstalk::DEFAULT_TTR) {
        $this->decoratedClient = $decoratedClient;
        $this->queue           = $queue;
        $this->ttr             = $ttr;
        $this->queue->watchOnly(self::TUBE_RESPONSES);
    }

    /**
     * @inheritDoc
     */
    public function send(RequestInterface $request, array $options = []) {
        return $this->decoratedClient->send($request, $options);
    }

    /**
     * @param RequestInterface $request
     * @return Promise
     */
    public function sendAsync(RequestInterface $request, array $options = []) {
        $requestId     = uniqid();
        $requestBag    = [
            'requestId' => $requestId,
            'request'   => $request,
            'options'   => $options,
            'response'  => null,
        ];
        $serializedBag = static::wrapRequestBag($requestBag);
        $this->queue->putInTube(self::TUBE_REQUESTS, $serializedBag, Pheanstalk::DEFAULT_PRIORITY, Pheanstalk::DEFAULT_DELAY, (int) $this->ttr);

        $promise = new Promise(function () use ($requestId, &$promise) {

            /** @var PromiseInterface $promise */

            while ($job = $this->queue->reserve()) {

                $payload = $job->getData();

                if ($requestId === substr($payload, 14, 13)) {

                    $requestBag = static::unwrapRequestBag($payload);
                    $this->queue->delete($job);

                    switch (true) {

                        case !empty($requestBag['response']) && $requestBag['response']->getStatusCode() < 400:
                            $promise->resolve($requestBag['response']);
                            break 2;

                        case !empty($requestBag['response']):
                            $promise->reject(RequestException::create($requestBag['request'], $requestBag['response']));
                            break 2;

                        default:
                            $promise->reject(new ConnectException('Unable to process request', $requestBag['request']));
                            break 2;
                    }

                }
                else {
                    $this->queue->release($job, Pheanstalk::DEFAULT_PRIORITY, 1);
                }
            }

        });
        return $promise;
    }

    /**
     * @inheritDoc
     */
    public function request($method, $uri = null, array $options = []) {
        return $this->decoratedClient->request($method, $uri, $options);
    }

    /**
     * @inheritDoc
     */
    public function requestAsync($method, $uri, array $options = []) {
        return $this->decoratedClient->requestAsync($method, $uri, $options);
    }

    /**
     * @inheritDoc
     */
    public function getConfig($option = null) {
        return $this->decoratedClient->getConfig($option);
    }

    public function __call($method, $args) {
        return call_user_func_array([$this->decoratedClient, $method], $args);
    }

    /**
     * @return Guzzle
     */
    public function getDecoratedClient() {
        return $this->decoratedClient;
    }

    /**
     * @param $string
     * @return array
     */
    public static function unwrapRequestBag($string) {
        $requestBag            = \GuzzleHttp\json_decode($string, true);
        $requestBag['request'] = \GuzzleHttp\Psr7\parse_request($requestBag['request']);

        if (!empty($requestBag['response'])) {
            $requestBag['response'] = \GuzzleHttp\Psr7\parse_response($requestBag['response']);
        }
        return $requestBag;
    }

    /**
     * @param $requestBag
     * @return string
     */
    public static function wrapRequestBag($requestBag) {
        $requestBag['request'] = $requestBag['request']->withRequestTarget((string) $requestBag['request']->getUri());
        $requestBag['request'] = \GuzzleHttp\Psr7\str($requestBag['request']);
        if (!empty($requestBag['response'])) {
            $requestBag['response'] = \GuzzleHttp\Psr7\str($requestBag['response']);
        }
        return \GuzzleHttp\json_encode($requestBag);
    }
}