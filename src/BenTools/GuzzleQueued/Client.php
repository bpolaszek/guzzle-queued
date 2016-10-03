<?php

namespace BenTools\GuzzleQueued;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use Pheanstalk\Job;
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
     * @var string
     */
    private $requestsTube;

    /**
     * @var string
     */
    private $responsesTube;

    /**
     * Client constructor.
     * @param ClientInterface $decoratedClient
     * @param Pheanstalk      $queue
     * @param int             $ttr
     * @param string          $requestsTube
     * @param string          $responsesTube
     */
    public function __construct(ClientInterface $decoratedClient, Pheanstalk $queue, $ttr = Pheanstalk::DEFAULT_TTR, $requestsTube = self::TUBE_REQUESTS, $responsesTube = self::TUBE_RESPONSES) {
        $this->decoratedClient = $decoratedClient;
        $this->queue           = $queue;
        $this->ttr             = $ttr;
        $this->requestsTube    = $requestsTube;
        $this->responsesTube   = $responsesTube;
        $this->queue->watchOnly($responsesTube);
    }

    /**
     * @inheritDoc
     */
    public function send(RequestInterface $request, array $options = []) {
        return $this->sendAsync($request, $options)->wait();
    }

    /**
     * @param RequestInterface $request
     * @return Promise
     */
    public function sendAsync(RequestInterface $request, array $options = []) {

        $promise = new Promise(function () use ($request, $options, &$promise) {

            $requestId     = uniqid();
            $requestBag    = [
                'requestId' => $requestId,
                'request'   => $request,
                'options'   => $options,
                'response'  => null,
            ];
            $serializedBag = static::wrapRequestBag($requestBag);
            $this->queue->putInTube($this->requestsTube, $serializedBag, 0, Pheanstalk::DEFAULT_DELAY, (int) $this->ttr);
            $job = $this->queue->reserveFromTube(sprintf('%s.%s', $this->responsesTube, $requestId), $this->ttr);

            if ($job instanceof Job) {
                return $this->processPromise($promise, $job);
            }

            else {
                $promise->reject(new \RuntimeException("The request could not be processed within the given time."));
            }

        });

        return $promise;
    }

    /**
     * Process promise with the Beanstalk Job.
     * @param PromiseInterface $promise
     * @param Job              $job
     */
    private function processPromise(PromiseInterface $promise, Job $job) {
        $payload    = $job->getData();
        $requestBag = static::unwrapRequestBag($payload);
        $this->queue->delete($job);

        switch (true) {
            case !empty($requestBag['response']) && $requestBag['response']->getStatusCode() < 400:
                $promise->resolve($requestBag['response']);
                return $requestBag['response'];

            case !empty($requestBag['response']):
                $promise->reject(RequestException::create($requestBag['request'], $requestBag['response']));
                break;

            default:
                $promise->reject(new ConnectException('Unable to process request', $requestBag['request']));
                break;
        }
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
     * @return string
     */
    public function getRequestsTube() {
        return $this->requestsTube;
    }

    /**
     * @param string $requestsTube
     * @return $this - Provides Fluent Interface
     */
    public function setRequestsTube($requestsTube) {
        $this->requestsTube = $requestsTube;
        return $this;
    }

    /**
     * @return string
     */
    public function getResponsesTube() {
        return $this->responsesTube;
    }

    /**
     * @param string $responsesTube
     * @return $this - Provides Fluent Interface
     */
    public function setResponsesTube($responsesTube) {
        $this->responsesTube = $responsesTube;
        return $this;
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