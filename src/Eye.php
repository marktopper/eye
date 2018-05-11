<?php

namespace Eyewitness\Eye;

use Eyewitness\Eye\App\Witness\Scheduler;
use Eyewitness\Eye\App\Witness\Database;
use Eyewitness\Eye\App\Witness\Request;
use Eyewitness\Eye\App\Witness\Server;
use Eyewitness\Eye\App\Witness\Queue;
use Eyewitness\Eye\App\Witness\Email;
use Eyewitness\Eye\App\Witness\Disk;
use Eyewitness\Eye\App\Witness\Log;
use Eyewitness\Eye\App\Api\Api;

class Eye
{
    const QUEUE_CONNECTION_PLACEHOLDER = 'QUEUE_CONNECTION_PLACEHOLDER';
    const CRON_OFFSET_PLACEHOLDER = 'CRON_OFFSET_PLACEHOLDER';
    const QUEUE_TUBE_PLACEHOLDER = 'QUEUE_TUBE_PLACEHOLDER';
    const SECRET_KEY_PLACEHOLDER = 'SECRET_KEY_PLACEHOLDER';
    const APP_TOKEN_PLACEHOLDER = 'APP_TOKEN_PLACEHOLDER';
    const EYE_VERSION = '2.0.4';

    /**
     * The Scheduler witness.
     *
     * @var \Eyewitness\Eye\App\Witness\Scheduler
     */
    protected $scheduler;

    /**
     * The Database witness.
     *
     * @var \Eyewitness\Eye\App\Witness\Database
     */
    protected $database;

    /**
     * The Request witness.
     *
     * @var \Eyewitness\Eye\App\Witness\Request
     */
    protected $request;

    /**
     * The Server witness.
     *
     * @var \Eyewitness\Eye\App\Witness\Server
     */
    protected $server;

    /**
     * The Queue wintess.
     *
     * @var \Eyewitness\Eye\App\Witness\Queue
     */
    protected $queue;

    /**
     * The Email witness.
     *
     * @var \Eyewitness\Eye\App\Witness\Email
     */
    protected $email;

    /**
     * The Disk witness.
     *
     * @var \Eyewitness\Eye\App\Witness\Disk
     */
    protected $disk;

    /**
     * The Log witness.
     *
     * @var \Eyewitness\Eye\App\Witness\Log
     */
    protected $log;

    /**
     * The Api back to Eyewitness.io server.
     *
     * @var \Eyewitness\Eye\Api
     */
    protected $api;

    /**
     * Get the version number of the package.
     *
     * @return string
     */
    public function version()
    {
        return static::EYE_VERSION;
    }

    /**
     * Check if Eyewitness appears to be installed and configured.
     *
     * @return bool
     */
    public function checkConfig()
    {
        $app_token = config('eyewitness.app_token');
        $secret_key = config('eyewitness.secret_key');

        if ($app_token == '' || is_null($app_token) || $app_token === self::APP_TOKEN_PLACEHOLDER) {
            return false;
        }

        if ($secret_key == '' || is_null($secret_key) || $secret_key === self::SECRET_KEY_PLACEHOLDER) {
            return false;
        }

        return true;
    }

    /**
     * Get the poll schedule cron based upon the config setting.
     *
     * @return string
     */
    public function getPollSchedule()
    {
        $list = ['1,7,13,19,25,31,37,43,49,55 * * * *',
                 '2,8,14,20,26,32,38,44,50,56 * * * *',
                 '3,9,15,21,27,33,39,45,51,57 * * * *',
                 '4,10,16,22,28,34,40,46,52,58 * * * *',
                 '5,11,17,23,29,35,41,47,53,59 * * * *'];

        $offset = config('eyewitness.cron_offset');

        if ($offset == '' || is_null($offset) || $offset === self::CRON_OFFSET_PLACEHOLDER) {
            return $list[0];
        }

        return $list[$offset];
    }

    /**
     * Run all checks.
     *
     * @return array
     */
    public function runAllChecks($email = true)
    {
        $data = $this->getConfig();

        if (config('eyewitness.monitor_database')) {
            $data['db_stats'] = $this->database()->check();
        }

        if (config('eyewitness.monitor_scheduler')) {
            $data['scheduler'] = $this->scheduler()->check();
        }

        if (config('eyewitness.monitor_request')) {
            $data['request_stats'] = $this->request()->check();
        }

        if (config('eyewitness.monitor_queue')) {
            $data['queue_stats'] = $this->queue()->check();
            $this->queue()->deploySonar();
        }

        if (config('eyewitness.monitor_disk')) {
            $data['disk_stats'] = $this->disk()->check();
        }

        if (config('eyewitness.monitor_email')) {
            $data['email_stats'] = $this->email()->check();
            if ($email) {
                $this->email()->send();
            }
        }

        if (config('eyewitness.monitor_log')) {
            $data['log_stats'] = $this->log()->check();
        }

        return $data;
    }

    /**
     * Get current configuration.
     *
     * @return array
     */
    public function getConfig()
    {
        $data['server_stats'] = $this->server()->check();
        $data['eyewitness_version'] = $this->version();
        $data['application_environment'] = app()->environment();
        $data['application_debug'] =  config('app.debug');
        $data['eyewitness_config'] = config('eyewitness');

        return $data;
    }

    /**
     * Return the Scheduler instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Scheduler
     */
    public function scheduler()
    {
        if (is_null($this->scheduler)) {
            $this->scheduler = app(Scheduler::class);
        }

        return $this->scheduler;
    }

    /**
     * Return the Database instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Database
     */
    public function database()
    {
        if (is_null($this->database)) {
            $this->database = app(Database::class);
        }

        return $this->database;
    }

    /**
     * Return the Request instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Request
     */
    public function request()
    {
        if (is_null($this->request)) {
            $this->request = app(Request::class);
        }

        return $this->request;
    }

    /**
     * Return the Server instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Server
     */
    public function server()
    {
        if (is_null($this->server)) {
            $this->server = app(Server::class);
        }

        return $this->server;
    }
    /**
     * Return the Queue instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Queue
     */
    public function queue()
    {
        if (is_null($this->queue)) {
            $this->queue = app(Queue::class);
        }

        return $this->queue;
    }

    /**
     * Return the Email instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Email
     */
    public function email()
    {
        if (is_null($this->email)) {
            $this->email = app(Email::class);
        }

        return $this->email;
    }

    /**
     * Return the Disk instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Disk
     */
    public function disk()
    {
        if (is_null($this->disk)) {
            $this->disk = app(Disk::class);
        }

        return $this->disk;
    }

    /**
     * Return the Log instance.
     *
     * @return \Eyewitness\Eye\App\Witness\Log
     */
    public function log()
    {
        if (is_null($this->log)) {
            $this->log = app(Log::class);
        }

        return $this->log;
    }

    /**
     * Return the Api instance.
     *
     * @return \Eyewitness\Eye\App\Api
     */
    public function api()
    {
        if (is_null($this->api)) {
            $this->api = app(Api::class);
        }

        return $this->api;
    }
}
