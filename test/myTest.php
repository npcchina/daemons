<?php
use Npc\Worker;
require_once __DIR__ .'/../vendor/autoload.php';

$worker = new Worker();
$worker::$worker_num = 2;
$worker->runOnce(function() use($worker){
    while(1)
    {
        $worker::signalDispatch();
        $worker::log($worker::$index.' i am child ');
        sleep(1);
    }
});

$worker->runAll();
