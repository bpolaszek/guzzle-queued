<?php

namespace BenTools\GuzzleQueued;

use GuzzleHttp\ClientInterface;
use Pheanstalk\Job;
use Pheanstalk\Pheanstalk;
use Pheanstalk\PheanstalkInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Worker {

    /**
     * @var ClientInterface
     */
    private $client;

    /**
     * @var CacheItemPoolInterface
     */
    private $cachePool;

    /**
     * @var Pheanstalk
     */
    private $queue;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * Worker constructor.
     * @param ClientInterface          $client
     * @param CacheItemPoolInterface   $cachePool
     * @param Pheanstalk               $queue
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(ClientInterface $client, CacheItemPoolInterface $cachePool, Pheanstalk $queue, EventDispatcherInterface $eventDispatcher = null) {
        $this->client          = $client;
        $this->cachePool       = $cachePool;
        $this->queue           = $queue;
        $this->eventDispatcher = $eventDispatcher ?? new EventDispatcher();
    }

    public function loop() {
        while ($job = $this->queue->reserve()) {
            $this->process($job);
        }
    }

    /**
     * @param Job $job
     */
    public function process($job) {

        $event = $this->eventDispatcher->dispatch(JobEvent::BEFORE_PROCESS, new JobEvent($job));

        if ($event->shouldNotProcess()) {
            switch (true) {
                case $event->shouldIgnore():
                    $this->queue->bury($event->getJob());
                    break;
                case $event->shouldDelete():
                    $this->queue->delete($event->getJob());
                    break;
                case $event->shouldDelay():
                    $this->queue->release($event->getJob(), PheanstalkInterface::DEFAULT_PRIORITY, $event->getDelay());
                    break;
            }
            return;
        }

        $requestBag = Client::unwrapRequestBag($job->getData());

        try {
            /** @var RequestInterface $request */
            $request = $requestBag['request'];
        }
        catch (\InvalidArgumentException $e) {
            $requestBag['response'] = null;
        }

        try {
            $requestBag['response'] = $this->client->send($request, $requestBag['options']);
        }
        catch (\GuzzleHttp\Exception\RequestException $e) {
            $requestBag['response'] = $e->getResponse() ? $e->getResponse() : null;
        }
        catch (\Exception $e) {
            $requestBag['response'] = null;
        }
        $cacheItem = $this->cachePool->getItem($requestBag['requestId']);
        $cacheItem->set(Client::wrapRequestBag($requestBag));
        $this->cachePool->save($cacheItem);
        $this->queue->delete($job);

        $this->eventDispatcher->dispatch(JobEvent::AFTER_PROCESS, new JobEvent($job));
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher() {
        return $this->eventDispatcher;
    }

}