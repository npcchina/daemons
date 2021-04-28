<?php

use RdKafka\KafkaConsumer;

$conf = new RdKafka\Conf();
//$conf->set('log_level', (string) LOG_DEBUG);
//$conf->set('debug', 'all');
$conf->set('bootstrap.servers','localhost:9092');
$conf->set('group.id','abcd');
$conf->set('auto.offset.reset', 'earliest');
$conf->set('enable.auto.commit', 0);

$conf->setRebalanceCb(function (RdKafka\KafkaConsumer $kafka, $err, array $partitions = null) {
    switch ($err) {
        case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
            echo "Assign: ";
            var_dump($partitions);
            $kafka->assign($partitions);
            break;

        case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
            echo "Revoke: ";
            var_dump($partitions);
            $kafka->assign(NULL);
            break;

        default:
            throw new \Exception($err);
    }
});


$consumer = new RdKafka\KafkaConsumer($conf);
// Subscribe to topic 'test'
$consumer->subscribe(['abcde']);

while (true) {
    $message = $consumer->consume(1*1000);

    switch ($message->err) {
        case RD_KAFKA_RESP_ERR_NO_ERROR:
            {
                echo $message->partition."\t".$message->payload."\n";
            }
            break;
        case RD_KAFKA_RESP_ERR__PARTITION_EOF:
            //echo "No more messages; will wait for more\n";
            break;
        case RD_KAFKA_RESP_ERR__TIMED_OUT:
            //echo "Timed out\n";
            break;
        default:
            throw new \Exception($message->errstr(), $message->err);
            break;
    }
}