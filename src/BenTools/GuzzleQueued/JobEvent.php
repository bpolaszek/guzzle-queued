<?php

namespace BenTools\GuzzleQueued;

use Pheanstalk\Job;
use Symfony\Component\EventDispatcher\Event;

class JobEvent extends Event {

    const BEFORE_PROCESS = 'guzzlequeued.job.before.process';
    const AFTER_PROCESS  = 'guzzlequeued.job.after.process';

    /**
     * @var Job
     */
    protected $job;

    /**
     * @var array
     */
    protected $data;

    /**
     * @var bool
     */
    protected $shouldIgnore = false;

    /**
     * @var bool
     */
    protected $shouldDelete = false;

    /**
     * @var bool
     */
    protected $shouldDelay = false;

    /**
     * @var
     */
    protected $delay;

    /**
     * JobEvent constructor.
     * @param Job    $job
     * @param string $data
     */
    public function __construct(Job $job, $data = []) {
        $this->job  = $job;
        $this->data = $data;
    }

    /**
     * @return Job
     */
    public function getJob() {
        return $this->job;
    }

    /**
     * @return boolean
     */
    public function shouldIgnore() {
        return $this->shouldIgnore;
    }

    /**
     * @param boolean $shouldIgnore
     * @return $this - Provides Fluent Interface
     */
    public function setShouldIgnore($shouldIgnore) {
        $this->shouldIgnore = (bool) $shouldIgnore;
        return $this;
    }

    /**
     * @return boolean
     */
    public function shouldDelete() {
        return $this->shouldDelete;
    }

    /**
     * @param boolean $shouldDelete
     * @return $this - Provides Fluent Interface
     */
    public function setShouldDelete($shouldDelete) {
        $this->shouldDelete = (bool) $shouldDelete;
        return $this;
    }

    /**
     * @return boolean
     */
    public function shouldDelay() {
        return $this->shouldDelay;
    }

    /**
     * @param boolean $shouldDelay
     * @return $this - Provides Fluent Interface
     */
    private function setShouldDelay($shouldDelay) {
        $this->shouldDelay = (bool) $shouldDelay;
        return $this;
    }

    /**
     * @return int
     */
    public function getDelay() {
        return $this->delay;
    }

    /**
     * @param int $delay
     * @return $this - Provides Fluent Interface
     */
    public function setDelay($delay) {
        $this->delay = (int) $delay;
        $this->setShouldDelay((bool) $this->delay);
        return $this;
    }

    /**
     * @return bool
     */
    public function shouldNotProcess() {
        return $this->shouldDelay() || $this->shouldDelete() || $this->shouldIgnore();
    }

    /**
     * @return array
     */
    public function getData() {
        return $this->data;
    }

    /**
     * @param array $data
     * @return $this - Provides Fluent Interface
     */
    public function setData($data) {
        $this->data = $data;
        return $this;
    }

}