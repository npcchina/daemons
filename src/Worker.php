<?php
namespace Npc;

class Worker extends Daemons
{
    public static $index = 0;
    public static $worker_num = 1;
    protected static $workers = [];
    protected static $_run = null;

    public function __construct()
    {
    }


    public function run(callable $function)
    {
        static::$_run = $function;
    }

    public function runOnce(callable $function)
    {
        static::$_run = $function;
    }

    public static function forkWorkers()
    {
        for($index = 1;$index <= static::$worker_num;$index++)
        {
            static::forkOneWorker($index);
        }
    }

    static function getWorkerPid($index)
    {
        return isset(static::$workers[$index]) ? static::$workers[$index] : 0;
    }

    static function getWorkerIndex($pid)
    {
        foreach(static::$workers as $index => $_pid)
        {
            if($_pid === $pid){
                return $index;
            }
        }
    }

    public static function forkOneWorker($index)
    {
        $pid = static::getWorkerPid($index);
        if($pid)
        {
            return;
        }

        //尝试fork 子进程
        $pid  = pcntl_fork ();
        if ($pid  == - 1) {
            throw new Exception('can not fork workers');
        } else if ($pid ) {
            static::$workers[$index] = $pid;
        }
        else
        {
            //记录自己的pid
            static::$_pid = posix_getpid();
            static::$index = $index;
            call_user_func(static::$_run);
            exit(250);
        }
    }

    public static function monitorWorkers()
    {
        //监测
        //文件变动？
        //日志rotate

        while(1)
        {
            pcntl_signal_dispatch();
            $status = 0;
            $pid = pcntl_wait($status,WUNTRACED);
            if($pid > 0)
            {
                static::log($pid.' exit');
                $index = static::getWorkerIndex($pid);
                unset(static::$workers[$index]);

                //当不是在停止的时候 启动新进程
                if(static::$_status !== STOP)
                {
                    static::forkOneWorker($index);
                }
            }

            // If shutdown state and all child processes exited then master process exit.
            if (static::$_status === static::STATUS_SHUTDOWN) {
                static::stopAll();
            }
        }
    }

    public function runAll()
    {
        static::init();
        static::parseArguments();
        static::daemon();
        //static::resetStd();
        static::registerSignalHandler();

        static::forkWorkers();
        static::monitorWorkers();

        //只有管理进程可以执行shutdown handler 来删除pid 等 否则就要在 子进程 reinstall signal
        static::registerShutdownHandler();
    }

    protected static function stopAll()
    {
        foreach(static::$workers as $index => $pid)
        {
            static::stop($pid);
        }
    }

    protected static function signalHandler($signal)
    {
        static::log('signal caught '.$signal);
        switch ($signal) {
            case SIGINT:
                static::log('stop signal');
                static::stopAll();
                exit(0);
                break;
            case SIGUSR1:
            case SIGUSR2:
                static::log('reload signal');
                exit(0);
                break;
            default:
                break;
        }
    }

}