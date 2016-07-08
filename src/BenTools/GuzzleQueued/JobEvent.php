<?php

namespace BenTools\GuzzleQueued;

use Pheanstalk\Job;
use Symfony\Component\EventDispatcher\Event;

class JobEvent extends Event {

    const BEFORE_PROCESS = 'queue.job.before.process';
    const AFTER_PROCESS  = 'queue.job.after.process';

    /**
     * @var Job
     */
    protected $job;

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
     * @param Job $job
     */
    public function __construct(Job $job) {
        $this->job = $job;
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

}