<?php

namespace BenTools\GuzzleQueued;

use GuzzleHttp\ClientInterface;
use Pheanstalk\Job;
use Pheanstalk\Pheanstalk;
use Pheanstalk\PheanstalkInterface;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Worker {

    /**
     * @var ClientInterface
     */
    private $client;

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
     * @param ClientInterface               $client
     * @param Pheanstalk                    $queue
     * @param EventDispatcherInterface|null $eventDispatcher
     */
    public function __construct(ClientInterface $client, Pheanstalk $queue, EventDispatcherInterface $eventDispatcher = null) {
        $this->client          = $client;
        $this->queue           = $queue;
        $this->eventDispatcher = $eventDispatcher ?? new EventDispatcher();
        $this->queue->watchOnly(Client::TUBE_REQUESTS);
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

        $payload    = $job->getData();
        $requestBag = Client::unwrapRequestBag($payload);
        $event      = $this->dispatch(JobEvent::BEFORE_PROCESS, new JobEvent($job, $requestBag));

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

        try {

            /** @var RequestInterface $request */
            $request = $requestBag['request'];

            try {
                $requestBag['response'] = $this->client->send($request, $requestBag['options']);
            }
            catch (\GuzzleHttp\Exception\RequestException $e) {
                $requestBag['response']  = $e->getResponse() ? $e->getResponse() : null;
                $requestBag['exception'] = $e;
            }
            catch (\Exception $e) {
                $requestBag['response']  = null;
                $requestBag['exception'] = $e;
            }
        }
        catch (\InvalidArgumentException $e) {
            $requestBag['response']  = null;
            $requestBag['exception'] = $e;
        }

        $tube = sprintf('%s.%s', Client::TUBE_RESPONSES, $requestBag['requestId']);
        try {
            $this->queue->putInTube($tube, Client::wrapRequestBag($requestBag));
            $this->queue->delete($job);
            $this->queue->useTube(Client::TUBE_RESPONSES);
        }
        catch (\Exception $e) {
            $event = new JobEvent($job, $requestBag);
            $event->setException($e);
            $event = $this->eventDispatcher->dispatch(JobEvent::AFTER_PROCESS, $event);
            if ($event->hasException()) {
                throw $event->getException();
            }
        }

        $this->eventDispatcher->dispatch(JobEvent::AFTER_PROCESS, new JobEvent($job, $requestBag));
    }

    /**
     * @param          $eventName
     * @param JobEvent $event
     * @return JobEvent
     */
    public function dispatch($eventName, JobEvent $event) {
        return $this->eventDispatcher->dispatch($eventName, $event);
    }

    /**
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher() {
        return $this->eventDispatcher;
    }

}