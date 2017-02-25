<?php

namespace spiritdead\resque\components\jobs\base;

use spiritdead\resque\components\workers\base\ResqueWorkerBase;
use spiritdead\resque\controllers\base\ResqueJobFactoryInterface;
use spiritdead\resque\controllers\ResqueJobFactory;
use spiritdead\resque\controllers\ResqueJobStatus;
use spiritdead\resque\exceptions\base\ResqueException;
use spiritdead\resque\exceptions\ResqueJobPerformException;
use spiritdead\resque\helpers\ResqueHelper;
use spiritdead\resque\Resque;

/**
 * Class ResqueJobBase
 * @package spiritdead\resque\jobs\base
 */
class ResqueJobBase implements ResqueJobInterface
{
    /**
     * @var null|Resque
     */
    private $resqueInstance = null;

    /**
     * @var null|ResqueJobStatus
     */
    public $status = null;

    /**
     * @var string The name of the queue that this job belongs to.
     */
    public $queue;

    /**
     * @var ResqueWorkerBase Instance of the Resque worker running this job.
     */
    public $worker;

    /**
     * @var array|null Array containing details of the job.
     */
    public $payload;

    /**
     * @var object|ResqueJobInterface Instance of the class performing work for this job.
     */
    private $classInstance;

    /**
     * @var ResqueJobFactoryInterface
     */
    private $jobFactory;

    /**
     * ResqueJobBase constructor.
     * @param $queue
     * @param $payload
     */
    public function __construct($resqueInstance, $queue, $payload = null)
    {
        $this->resqueInstance = $resqueInstance;
        $this->queue;
        $this->payload;
        if (isset($this->payload['id'])) {
            $this->status = new ResqueJobStatus($this->resqueInstance, $this->payload['id']);
            $this->jobFactory = new ResqueJobFactory();
        }
    }

    /**
     * Create a new job and save it to the specified queue.
     *
     * @param string $queue The name of the queue to place the job in.
     * @param string $class The name of the class that contains the code to execute the job.
     * @param array $args Any optional arguments that should be passed when the job is executed.
     * @param boolean $monitor Set to true to be able to monitor the status of a job.
     * @param string $id Unique identifier for tracking the job. Generated if not supplied.
     *
     * @return string
     * @throws \InvalidArgumentException
     */
    public function create($class, $args = null, $monitor = false, $id = false)
    {
        if ($id == null || empty($id)) {
            $id = ResqueHelper::generateJobId();
        }

        if ($args !== null && !is_array($args)) {
            throw new \InvalidArgumentException(
                'Supplied $args must be an array.'
            );
        }

        $this->payload = [
            'class' => $class,
            'args' => [$args],
            'id' => $id,
            'queue_time' => microtime(true)
        ];

        if ($monitor) {
            $this->status->create($id);
        }

        return $id;
    }

    /**
     * Find the next available job from the specified queue and return an
     * instance of Resque_Job for it.
     *
     * @param string $queue The name of the queue to check for a job in.
     * @return boolean|ResqueJobBase Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
     */
    public static function reserve(Resque $resqueInst, $queue)
    {
        $payload = $resqueInst->pop($queue);
        if (!is_array($payload)) {
            return false;
        }
        return new self($resqueInst, $queue, $payload);
    }

    /**
     * Find the next available job from the specified queues using blocking list pop
     * and return an instance of Resque_Job for it.
     *
     * @param Resque $resqueInstance
     * @param array $queues
     * @param int $timeout
     * @return boolean|ResqueJobBase Null when there aren't any waiting jobs, instance of Resque_Job when a job was found.
     */
    public static function reserveBlocking(Resque $resqueInstance, array $queues, $timeout = null)
    {
        $item = $resqueInstance->blpop($queues, $timeout);
        if (!is_array($item)) {
            return false;
        }
        return new self($resqueInstance, $item['queue'], $item['payload']);
    }

    /**
     * Update the status of the current job.
     *
     * @param int $status Status constant from Resque_Job_Status indicating the current status of a job.
     */
    public function updateStatus($status)
    {
        if (empty($this->payload['id'])) {
            return;
        }
        $this->status->update($status);
    }

    /**
     * Get the arguments supplied to this job.
     *
     * @return array Array of arguments.
     */
    public function getArguments()
    {
        if (!isset($this->payload['args'])) {
            return [];
        }
        return $this->payload['args'][0];
    }

    /**
     * Get the instantiated object for this job that will be performing work.
     * @return ResqueJobInterface Instance of the object that this job belongs to.
     * @throws ResqueException
     */
    public function getInstance()
    {
        if (!is_null($this->classInstance)) {
            return $this->classInstance;
        }
        $this->classInstance = $this->jobFactory->create($this->payload['class'], $this->getArguments(), $this->queue);
        $this->classInstance->job = $this;
        return $this->classInstance;
    }

    /**
     * Actually execute a job by calling the perform method on the class
     * associated with the job with the supplied arguments.
     *
     * @return boolean
     * @throws ResqueJobPerformException When the job's class could not be found or it does not contain a perform method.
     */
    public function perform()
    {
        try {
            $this->resqueInstance->events->trigger('beforePerform', $this);

            $instance = $this->getInstance();
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }

            $instance->perform();

            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }

            $this->resqueInstance->events->trigger('afterPerform', $this);
        } catch (ResqueJobPerformException $e) {
            return false;
        }

        return true;
    }

    /**
     * Mark the current job as having failed.
     *
     * @param $exception
     */
    public function fail($exception)
    {
        $this->resqueInstance->events->trigger('onFailure', [
            'exception' => $exception,
            'job' => $this
        ]);

        $this->status->update(ResqueJobStatus::STATUS_FAILED);

        //@todo: create a way to use a queue to store all of the failed jobs

        /*Resque_Failure::create(
            $this->payload,
            $exception,
            $this->worker,
            $this->queue
        );*/

        $this->resqueInstance->stats->incr('failed');
        $this->resqueInstance->stats->incr('failed:' . $this->worker);
    }

    /**
     * Re-queue the current job.
     * @return string
     */
    public function recreate()
    {
        return self::create($this->queue, $this->payload['class'], $this->getArguments(), $this->status->isTracking());
    }

    /**
     * Generate a string representation used to describe the current job.
     *
     * @return string The string representation of the job.
     */
    public function __toString()
    {
        $name = ['Job{' . $this->queue . '}'];
        if (!empty($this->payload['id'])) {
            $name[] = 'ID: ' . $this->payload['id'];
        }
        $name[] = $this->payload['class'];
        if (!empty($this->payload['args'])) {
            $name[] = json_encode($this->payload['args']);
        }
        return '(' . implode(' | ', $name) . ')';
    }
}