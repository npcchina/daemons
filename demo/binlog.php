<?php
require_once __DIR__ .'/../vendor/autoload.php';

use Npc\Db\Subscriber;
use MySQLReplication\Event\DTO\EventDTO;
use Npc\Worker;

$config = [
    'db' => [
        'host' => '',
        'username' => '',
        'password' => '',
    ],
    'redis' => [
        'host' => 'localhost',
        'password' => 'password',
        'port' => 6379,
        'database' => 12,
    ],
];

$daemon = new Worker();
$daemon::$workerNum = 1;
$daemon->job(function() use($daemon,$config){
    try {
        $subscriber = new Subscriber();
        $subscriber->withHost($config['db']['host']);
        $subscriber->withUser($config['db']['username']);
        $subscriber->withPassword($config['db']['password']);
        $subscriber->withDatabasesOnly(['database']);
        $subscriber->withTablesOnly(['table_a','table_b']);
        $subscriber->withEventsOnly(['update','delete']);
        isset($daemon::$arguments['binlogFile']) && $subscriber->withBinLogFileName($daemon::$arguments['binlogFile']);
        isset($daemon::$arguments['binlogPos']) && $subscriber->withBinLogPosition($daemon::$arguments['binlogPos']);
        $subscriber->withRedis($config['redis']);
        $subscriber->withSlaveId(4000);
        $subscriber->onMessage = function(EventDTO $event) use($subscriber,$daemon)
        {
            //调用 daemon 的日志输出
            //$daemon::log($event);

            //如果希望被中断 需要调用 daemon 的信号处理
            $daemon::signalDispatch();

            try {
                $tableMap = $event->getTableMap();
                $binlog = $event->getEventInfo()->getBinLogCurrent();
                $type = $event->getType();
                $table = $tableMap->getTable();
                $changes = $event->getValues();

                $daemon::log(' Log:' . $binlog->getBinFileName() . ' Pos:' . $binlog->getBinLogPosition() . ' Time:' . $event->getEventInfo()->getDateTime() . ' ' . $type . '->' . $table . " [done]");

                foreach ($changes as $change) {
                    if ($type == 'write') {
                        //插入
                        $row = $change;
                    } else {
                        //更新
                        $row = $change['after'];
                    }

                    $subscriber->rPush('binlogEventsList:'.$table,json_encode([$type,$row,$binlog->getBinFileName(),$binlog->getBinLogPosition()]));
                }

                $subscriber->recordLastRun($event);
            }
            catch (\Exception $e)
            {
                $daemon::log($e->getMessage());
            }

        };
        $subscriber->run();
    }
    catch (\Exception $e)
    {
        $daemon::log($e->getMessage());
    }
});
$daemon->run();
