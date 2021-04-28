<?php
$conf = new RdKafka\Conf();
//$conf->set('log_level', (string) LOG_DEBUG);
//$conf->set('debug', 'all');
$conf->set('bootstrap.servers','');
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


//$topicConf = new RdKafka\TopicConf();
//$conf->setDefaultTopicConf($topicConf);

$consumer = new RdKafka\KafkaConsumer($conf);

// Subscribe to topic 'test'
$consumer->subscribe(['']);

while (true) {
    $message = $consumer->consume(1000);
    var_dump($consumer->getAssignment());

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