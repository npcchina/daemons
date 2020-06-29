<?php
use Npc\Worker;
require_once __DIR__ .'/../vendor/autoload.php';

$worker = new Worker();
$worker::$workerNum = 1;
$worker->job(function() use($worker){
        $worker::log($worker::$index);
        sleep(1);
});

$worker->run();
