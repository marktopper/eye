<?php

namespace Eyewitness\Eye\App\Scheduling;

use Illuminate\Console\Scheduling\Event as OriginalEvent;
use Eyewitness\Eye\App\Scheduling\BaseEventTrait;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\Process\ProcessUtils;
use Symfony\Component\Process\Process;
use Illuminate\Console\Application;
use Eyewitness\Eye\Eye;

class Event extends OriginalEvent
{
    use BaseEventTrait, ApplicationBackportTrait;

    /**
     * Indicates if the command should run in background.
     *
     * @var bool
     */
    public $runInBackground = false;

    /**
     * Create a new custom child event instance.
     * https://stackoverflow.com/a/4722587/1317935
     *
     * @param  \Illuminate\Console\Scheduling\Event  $object
     * @return void
     */
    public function __construct(OriginalEvent $object)
    {
        foreach($object as $property => $value) {
            $this->$property = $value;
        }

        $this->eye = app(Eye::class);
    }

    /**
     * Run the given event.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    public function run(Container $container)
    {
        if ((! $this->ignoreMutex) && $this->withoutOverlapping && (! $this->setMutex())) {
            return;
        }

        ((! $this->forceRunInForeground) && $this->runInBackground)
            ? $this->newCommandInBackground($container)
            : $this->runCommandInForeground($container);
    }

    /**
     * Run the foreground process.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    protected function runForegroundProcess(Container $container)
    {
       $this->exitcode = (new Process($this->buildForegroundCommand(), base_path(), null, null, null))->run();
    }

    /**
     * Run the command in the background.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return void
     */
    protected function newCommandInBackground(Container $container)
    {
        (new Process($this->buildBackgroundCommand(), base_path(), null, null, null))->run();
    }

    /**
     * Build the command for running the event in the foreground.
     *
     * @return string
     */
    public function buildForegroundCommand()
    {
        return $this->ensureCorrectUser($this->command.$this->getAppendOutput().$this->output.' 2>&1');
    }

    /**
     * Determine if we are amending output.
     *
     * @return string
     */
    protected function getAppendOutput()
    {
        if (laravel_version_is('<', '5.1.0')) {
            return '>';
        }

        return $this->shouldAppendOutput ? ' >> ' : ' > ';
    }

    /**
     * Build the command for running the event in the background.
     *
     * This is modified from the original, to send the full mutex to the Eyewitness background
     * command, which will send the whole event back to the scheduler for processing as a
     * foreground command (but on a seperate process).
     *
     * Best of both worlds :)
     *
     * @return string
     */
    public function buildBackgroundCommand()
    {
        $background = $this->formatCommandString('eyewitness:background').' "'.$this->mutexName().'" --force';
        $output = ProcessUtils::escapeArgument($this->getDefaultOutput());

        return $this->ensureCorrectUser('('.$background.' > '.$output.' 2>&1) > '.$output.' 2>&1 &');
    }

    /**
     * Finalize the event's command syntax with the correct user.
     *
     * @param  string  $command
     * @return string
     */
    protected function ensureCorrectUser($command)
    {
        return $this->user && ! windows_os() ? 'sudo -u '.$this->user.' -- sh -c \''.$command.'\'' : $command;
    }

    /**
     * Ensure that output is being captured if not already set by the event.
     *
     * @return void
     */
    protected function ensureOutputIsBeingCapturedForEyewitness()
    {
        if (! config('eyewitness.capture_cron_output')) {
            return;
        }

        if ($this->outputNotBeingCaptured()) {
            $this->output = storage_path('logs/eyewitness_cron_'.sha1($this->mutexName()).'.cron.log');
        }
    }

    /**
     * Get the output from the last scheduled job. Only remove the file if we created
     * it specifically for Eyewitness - otherwise leave it alone for the framework
     * to handle as normal.
     *
     * @return mixed
     */
    protected function retrieveOutput()
    {
        if ((! config('eyewitness.capture_cron_output')) || (! file_exists($this->output))) {
            return null;
        }

        $text = file_get_contents($this->output);

        if (str_contains($this->output, 'eyewitness_cron_') && file_exists($this->output)) {
            unlink($this->output);
        }

        return substr($text, 0, 15000);
    }
}
