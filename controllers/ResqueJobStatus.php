<?php

namespace spiritdead\resque\controllers;

use spiritdead\resque\Resque;

/**
 * Class ResqueJobStatus
 * @package spiritdead\resque\controllers
 */
class ResqueJobStatus
{
    /**
     * @var null|Resque
     */
    private $resqueInstance = null;

    const STATUS_WAITING = 1;
    const STATUS_RUNNING = 2;
    const STATUS_FAILED = 3;
    const STATUS_COMPLETE = 4;

    /**
     * @var string The ID of the job this status class refers back to.
     */
    private $id;

    /**
     * @var mixed Cache variable if the status of this job is being monitored or not.
     *    True/false when checked at least once or null if not checked yet.
     */
    private $isTracking = null;

    /**
     * @var array Array of statuses that are considered final/complete.
     */
    private static $completeStatuses = [
        self::STATUS_FAILED,
        self::STATUS_COMPLETE
    ];

    /**
     * ResqueJobStatus constructor.
     * @param $resqueInst
     * @param $id
     */
    public function __construct(Resque $resqueInst, $id)
    {
        $this->id = $id;
        $this->resqueInstance = $resqueInst;
    }

    /**
     * Create a new status monitor item for the supplied job ID. Will create
     * all necessary keys in Redis to monitor the status of a job.
     *
     * @param string $id The ID of the job to monitor the status of.
     */
    public function create($id)
    {
        $statusPacket = [
            'status' => self::STATUS_WAITING,
            'updated' => time(),
            'started' => time()
        ];
        $this->resqueInstance->redis->set('job:' . $id . ':status', json_encode($statusPacket));
    }

    /**
     * Check if we're actually checking the status of the loaded job status
     * instance.
     *
     * @return boolean True if the status is being monitored, false if not.
     */
    public function isTracking()
    {
        if ($this->isTracking === false) {
            return false;
        }

        if (!$this->resqueInstance->redis->exists((string)$this)) {
            $this->isTracking = false;
            return false;
        }

        $this->isTracking = true;
        return true;
    }

    /**
     * Update the status indicator for the current job with a new status.
     *
     * @param int The status of the job (see constants in Resque_Job_Status)
     */
    public function update($status)
    {
        if (!$this->isTracking()) {
            return;
        }

        $statusPacket = [
            'status' => $status,
            'updated' => time()
        ];
        $this->resqueInstance->redis->set((string)$this, json_encode($statusPacket));

        // Expire the status for completed jobs after 24 hours
        if (in_array($status, self::$completeStatuses)) {
            $this->resqueInstance->redis->expire((string)$this, 86400);
        }
    }

    /**
     * Fetch the status for the job being monitored.
     *
     * @return mixed False if the status is not being monitored, otherwise the status as
     *    as an integer, based on the Resque_Job_Status constants.
     */
    public function get()
    {
        if (!$this->isTracking()) {
            return false;
        }

        $statusPacket = json_decode($this->resqueInstance->redis->get((string)$this), true);
        if (!$statusPacket) {
            return false;
        }

        return $statusPacket['status'];
    }

    /**
     * Stop tracking the status of a job.
     */
    public function stop()
    {
        $this->resqueInstance->redis->del((string)$this);
    }

    /**
     * Generate a string representation of this object.
     *
     * @return string String representation of the current job status class.
     */
    public function __toString()
    {
        return 'job:' . $this->id . ':status';
    }
}
