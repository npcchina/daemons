<?php
/**
 * Created by IntelliJ IDEA.
 * User: 902303
 * Date: 2020/2/14
 * Time: 16:33
 */
namespace Npc;

class Daemons{

    public static $arguments = [];
    public $_db = null;
    public $_redis = null;

    protected static $uniqueName = '';
    protected static $logDir = '/var/log';
    public static $logFile = null;
    protected static $pidDir = '/var/run';
    public static $pidFile = null;
    protected static $stdoutFile = '/dev/null';

    protected static $_pid = null;

    protected static $runAsUser = 'npc'; //设置进程允许在某个用户模式下

    public function __construct()
    {
        static::init();
        static::parseArguments();
        static::daemon();
        //static::resetStd();
        static::registerSignalHandler();
        static::registerShutdownHandler();
    }

    protected static function init()
    {
        $backtrace        = \debug_backtrace();

        static::$uniqueName = basename($backtrace[\count($backtrace) - 1]['file'],'.php');

        if(!\is_file(static::getLogFile()))
        {
            \touch(static::getLogFile());
            \chmod(static::getLogFile(),0622);
        }

    }

    /**
     * 创建子进程之前检查
     */
    protected static function daemon()
    {
        //检查扩展
        if(!function_exists('posix_kill') || !function_exists('pcntl_fork'))
        {
            static::log('please recompile PHP with --enable-pcntl  and without --disable-posix');
        }

        //检查运行模式
        if(php_sapi_name() != 'cli')
        {
            static::log('this script could only run in PHP-CLI mode');
        }

        //尝试fork 子进程
        $_pid  = pcntl_fork ();
        if (static::$_pid  == - 1) {
            static::log ( 'could not fork child process');
        } else if ($_pid ) {
            //父进程 fork 成功 退出
            static::log('process started with pid '.$_pid);
            exit ( 0 );
        }

        //进程尝试从当前终端脱离成为独立进程 -- 这个在 fork 之前和之后都可以调用
        if (posix_setsid () == - 1) {
            throw new Exception( 'count not detach from terminal');
        }

        //获取启动进程的pid
        static::$_pid = posix_getpid();
        static::log('process started with pid '.static::$_pid);

        //写入pid 为何放置在这边 因为尝试用root 身份创建了 pid 以及 log 下文切换为非用户身份后会导致写入失败
        static::savePid();

        //尝试设置 进程 归属用户
        static::setProcessUser();
    }

    /**
     * 尝试设置运行当前进程的用户 from workerman
     *
     * 切换为指定用户态会存在问题 之前的 写入 到 /var/run /var/log 逻辑不通了。。。 还是尝试取消此逻辑？ TODO
     *
     * @return void
     */
    protected static function setProcessUser()
    {
        if(empty(static::$runAsUser) || posix_getuid() !== 0) // 0 run as root ?
        {
            return;
        }
        //获取指定用户名用户系统信息
        $userInfo = posix_getpwnam(static::$runAsUser);
        if($userInfo['uid'] != posix_getuid() || $userInfo['gid'] != posix_getgid())
        {
            if(!posix_setgid($userInfo['gid']) || !posix_setuid($userInfo['uid']))
            {
                static::log( 'can not run as '.static::$runAsUser);
            }
        }
    }

    public static function isAlive($pid)
    {
        return $pid && posix_kill($pid, 0);
    }

    public static function stop($pid)
    {
        //如果进程非运行中
        if(!static::isAlive($pid))
        {
            static::log('process is not running');
        }
        //尝试向进程发送停止信号
        posix_kill($pid, SIGINT);
        $timeout = 5; //超时时间
        $start = time();
        while(1)
        {
            // 检查进程是否存活
            if(static::isAlive($pid))
            {
                // 检查是否超过$timeout时间
                if(time() - $start >= $timeout)
                {
                    static::log('process stop failed');

                    //exit？
                    break;
                }
                usleep(10000);
                continue;
            }
            static::log('process stopped successful');
        }
    }

    public static function reload($pid)
    {
        //TODO 此处的sleep 本意是等待原始进程退出 正确清理 可以存在也可以不存在
        //TODO 探讨？此处不存在是存在问题：比如 原始业务退出时间过长
        //如果进程非运行中
        if(static::isAlive($pid))
        {
            //尝试向进程发送停止信号
            posix_kill($pid, SIGUSR1);
        }
    }

    /**
     * 初始化命令行参数
     */
    protected static function parseArguments()
    {
        global $argv;

        foreach($argv as $k => $v)
        {
            if(trim($v,'-') !== $v)
            {
                $v = trim($v,'-');
                //TODO 此处 $param 变量定义了 空参的默认行为 --stop 等同于 --stop=true
                $param = true;
                if(stripos($v,'=') !== false)
                {
                    list($v,$param) = explode('=',$v);
                }
                static::$arguments[$v] = isset($argv[$k+1]) && stripos($argv[$k+1],'-') === false ? $argv[$k+1] : $param;
            }
        }

        if(isset(static::$arguments['logFile']))
        {
            static::$logFile = static::$arguments['logFile'];
        }

        if(isset(static::$arguments['pidFile']))
        {
            static::$pidFile = static::$arguments['pidFile'];
        }

        //特殊命令处理 TODO
        $pid = @file_get_contents(static::getPidFile());
        //停止命令
        if(isset(static::$arguments['stop']))
        {
            static::stop($pid);
        }
        //平滑重启逻辑
        else if(isset(static::$arguments['reload']))
        {
            static::reload($pid);
        }
        else if(static::isAlive($pid))
        {
            //进程已经存在 默认退出
            static::log('process is running with pid '.$pid);
        }
    }

    /**
     * 获取pid 文件名
     * @return string
     */
    protected static function getPidFile()
    {
        return static::$pidFile ? static::$pidFile : static::$pidDir.DIRECTORY_SEPARATOR.static::$uniqueName.'.pid';
    }

    /**
     * 保存 pid 到日志
     * @throws Exception
     */
    protected static function savePid()
    {
        @mkdir(static::$pidDir.DIRECTORY_SEPARATOR);
        if(false === @file_put_contents(static::getPidFile(), static::$_pid))
        {
            throw new Exception('can not save pid to ' . static::getPidFile());
        }
    }

    /**
     * 获取日志文件名
     * @return string
     */
    protected static function getLogFile()
    {
        return static::$logFile ? static::$logFile : static::$logDir.DIRECTORY_SEPARATOR.static::$uniqueName.'.log';
    }


    /**
     * 改写错误输出
     * @throws Exception
     */
    protected static function resetStd()
    {
        global $STDOUT, $STDERR;
        $handle = fopen(static::$stdoutFile,"a");
        if($handle)
        {
            unset($handle);
            @fclose(STDOUT);
            @fclose(STDERR);
            $STDOUT = fopen(static::$stdoutFile,"a");
            $STDERR = fopen(static::$stdoutFile,"a");
        }
        else
        {
            throw new Exception('can not open stdoutFile ' . static::$stdoutFile);
        }
    }

    /**
     * 信号处理 目前只添加了 sigint & sigusr1 信号响应逻辑 其他传入的被注册信号将被忽略
     * @param $signal
     */
    protected static function signalHandler($signal)
    {
        static::log('signal caught '.$signal);
        switch ($signal) {
            case SIGINT:
                static::log('stop signal');
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

    /**
     * @important 每次业务循环结束或者开始都需要条用此代码来处理一些信号比如重启等
     */
    public static function signalDispatch()
    {
        pcntl_signal_dispatch();
    }

    /**
     * 注册信号处理
     *
     * 此处目的是接管一些信号 比如接管一些软中断信号 让进程可以完整的跑完逻辑
     *
     * 如果接管了某信号但是不对其进行响应 结果就是进程会忽略对应的信号
     *
     */
    protected function registerSignalHandler()
    {
        //接管系统信号
        pcntl_signal(SIGTERM, [$this,'signalHandler'], false);
        //不允许的行为
        //pcntl_signal(SIGKILL, [$this,'signalHandler'], false);
        //自定义的停止信号
        pcntl_signal(SIGINT, [$this,'signalHandler'], false);
        //自定义的重启信号
        pcntl_signal(SIGUSR1, [$this,'signalHandler'], false);
        //尚未用到
        pcntl_signal(SIGUSR2, [$this,'signalHandler'], false);
        //尚未用到
        $reg = pcntl_signal(SIGPIPE, SIG_IGN, false);

        static::log('register signal handler '.($reg ? 'success':'failed'));
    }

    /**
     * 注册退出监控
     */
    public function registerShutdownHandler()
    {
        register_shutdown_function(array($this,'shutdownHandler'));
    }

    /**
     * 程序退出记录
     */
    public static function shutdownHandler()
    {
        $errors = error_get_last();
        if($errors && ($errors['type'] === E_ERROR ||
                $errors['type'] === E_PARSE ||
                $errors['type'] === E_CORE_ERROR ||
                $errors['type'] === E_COMPILE_ERROR ||
                $errors['type'] === E_RECOVERABLE_ERROR ))
        {
            static::log(static::getErrorType($errors['type']) . " {$errors['message']} in {$errors['file']} on line {$errors['line']}");
        }

        //执行pid 清理？
        @unlink(static::getPidFile());

        static::log('shutdown');

        //此处是否需要？
        //exit(0)
    }

    /**
     * 获取错误类型对应的意义 from workerman
     * @param integer $type
     * @return string
     */
    protected static function getErrorType($type)
    {
        switch($type)
        {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_COMPILE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
    }

    /**
     * 日志记录逻辑
     * @param $message
     * @param $exit
     */
    public static function log($message = '')
    {
        //非子进程内部 日志直接输出
        if(!static::$_pid)
        {
            echo $message.PHP_EOL;
            exit();
        }

        file_put_contents(static::getLogFile(),static::_debug() .'['.static::$_pid.'] '. $message.PHP_EOL,FILE_APPEND);
    }

    /**
     * 时间以及内存使用记录
     *
     * @return string
     */
    public static function _debug()
    {
        static $time,$memory;

        list ($usec, $sec) = explode(" ", microtime());

        if(!$time)
        {
            $time = $usec + $sec;
            $memory = memory_get_usage(true)/1024 / 1024;
        }

        return date('Y-m-d H:i:s').'('.($usec + $sec - $time).'--'.round(memory_get_usage(true) /1024 / 1024 - $memory).'MB)';
    }

    public function run(callable $function)
    {

    }

    public function runOnce(callable $function)
    {
        call_user_func($function);
    }
}