<?php
namespace Npc;

class Worker extends Daemons
{
    const STATUS_STARTING = 1;
    const STATUS_RUNNING = 2;
    const STATUS_SHUTDOWN = 4;
    const STATUS_RELOADING = 8;

    public static $index = 0;
    public static $worker_num = 1;
    protected static $workers = [];
    protected static $_run = null;
    protected static $_masterPid = null;

    protected static $_status = self::STATUS_STARTING;

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

    public static function forkWorkers()
    {
        for($index = 1;$index <= static::$worker_num;$index++)
        {
            static::forkOneWorker($index);
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
            srand();
            mt_srand();
            //worker 要处理的事情
            //记得清理一些从父集成来的变量
            //记录自己的pid
            static::$_pid = posix_getpid();
            static::$index = $index;
            static::$workers = [];
            static::registerSignalHandler();
            //默认循环 TODO 可以外层自带循环 系统提供循环处理能力
            while(1)
            {
                static::signalDispatch();
                call_user_func(static::$_run);
            }
            exit(255);
        }
    }

    /**
     *
     * 监测
     *
     * TODO 文件变动 志rotate
     */
    public static function monitorWorkers()
    {
        while(1)
        {
            static::signalDispatch();

            $status = 0;
            $pid = pcntl_wait($status,WUNTRACED);
            if($pid > 0)
            {
                static::log($pid.' exit');
                $index = static::getWorkerIndex($pid);
                unset(static::$workers[$index]);

                //当不是在停止的时候 启动新进程
                if(static::$_status !== static::STATUS_SHUTDOWN)
                {
                    static::forkOneWorker($index);
                }
            }

            //TODO 还有问题 特别是调用 stop 的地方
            // If shutdown state and all child processes exited then master process exit.
            if (static::$_status === static::STATUS_SHUTDOWN) {
                //static::stopAll();
            }
        }
    }

    public static function isAlive($pid)
    {
        return $pid && posix_kill($pid,0);
    }

    public function runAll()
    {
        static::init();
        static::parseArguments();
        static::daemon();

        static::forkWorkers();

        static::registerSignalHandlerMaster();
        static::registerShutdownHandler();
        static::monitorWorkers();
    }

    protected static function stopAll()
    {
        static::$_status = self::STATUS_SHUTDOWN;
        foreach(static::$workers as $index => $pid)
        {
            if(static::isAlive($pid))
            {
                posix_kill($pid, SIGINT);
            }
            else
            {
                unset(static::$workers[$index]);
            }
        }

        foreach(static::$workers as $index => $pid)
        {
            if(static::isAlive($pid))
            {
                $timeout = 5; //超时时间
                $start = time();
                while(1)
                {
                    if(!static::isAlive($pid))
                    {
                        static::log(''.$pid.' stopped successful');
                        unset(static::$workers[$index]);
                        break;
                    }
                    else
                    {
                        //static::log($pid.' alive?'.var_export(posix_kill($pid, SIGINT),true)."\t".posix_strerror(posix_get_last_error()));
//                        $output = [];
//                        exec('ps aux | grep my',$output);
//                        static::log(implode("\n",$output));
                    }

                    // 检查是否超过$timeout时间
                    if(time() - $start >= $timeout)
                    {
                        static::log('stop '.$pid.' failed');
                        break;
                    }
                    usleep(10000);
                }
            }
            else
            {
                unset(static::$workers[$index]);
            }
        }

        if(empty(static::$workers))
        {
            static::log('all workers stopped successful');
        }
        else
        {
            static::log('stop workers ['.implode(',',static::$workers).']failed');
        }
    }

    protected static function registerSignalHandler()
    {
        $signalHandler = '\Npc\Worker::signalHandler';

        pcntl_signal(SIGINT, SIG_IGN, false);
        pcntl_signal(SIGTERM, SIG_IGN, false);
        pcntl_signal(SIGUSR1, SIG_IGN, false);
        pcntl_signal(SIGUSR2, SIG_IGN, false);
        pcntl_signal(SIGIO, SIG_IGN, false);

        //接管系统信号
        pcntl_signal(SIGTERM, $signalHandler, false);
        //不允许的行为
        //pcntl_signal(SIGKILL, [$this,'signalHandler'], false);
        //自定义的停止信号
        pcntl_signal(SIGINT, $signalHandler, false);
        //自定义的重启信号
        pcntl_signal(SIGUSR1, $signalHandler, false);
        //尚未用到
        pcntl_signal(SIGUSR2, $signalHandler, false);
        //尚未用到
        $reg = pcntl_signal(SIGPIPE, SIG_IGN, false);

        static::log('register signal handler '.($reg ? 'success':'failed'));
    }

    protected function registerSignalHandlerMaster()
    {
        $signalHandler = '\Npc\Worker::signalHandlerMaster';

        pcntl_signal(SIGCHLD, SIG_IGN, false);

        //接管系统信号
        pcntl_signal(SIGTERM, $signalHandler, false);
        //不允许的行为
        //pcntl_signal(SIGKILL, [$this,'signalHandler'], false);
        //自定义的停止信号
        pcntl_signal(SIGINT, $signalHandler, false);
        //自定义的重启信号
        pcntl_signal(SIGUSR1, $signalHandler, false);
        //尚未用到
        pcntl_signal(SIGUSR2, $signalHandler, false);
        //尚未用到
        $reg = pcntl_signal(SIGPIPE, SIG_IGN, false);


        static::log('register signal handler '.($reg ? 'success':'failed'));
    }

    protected static function signalHandlerMaster($signal)
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