<?php
use Npc\Worker;
require_once __DIR__ .'/../vendor/autoload.php';

$worker = new Worker();
$worker::$worker_num = 1;
$worker->job(function() use($worker){
        $worker::log($worker::$index);
        sleep(2);
});

$worker->run();
