<?php

namespace App\Service;

use RdKafka\Conf;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use RuntimeException;
use SimPod\KafkaBundle\Kafka\Configuration;

class KafkaService
{
    public const DEFAULT_TIMEOUT = 1000;
    public const FLUSH_RETRY_COUNT = 10;
    public const SEND_MESSAGE_TOPIC = 'send_message';

    /**
     * @var ProducerTopic[]
     */
    private array $topics = [];

    private Producer $producer;

    public function __construct(private readonly Configuration $configuration) {
        $this->producer = new Producer(new Conf());
        $this->producer->addBrokers($this->configuration->getBootstrapServers());
    }

    public function send(string $name, array $data, int $partition = RD_KAFKA_PARTITION_UA, int $timeout = self::DEFAULT_TIMEOUT): void
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR);

        $this->getTopic($name)->produce($partition, 0, $payload);

        for ($i = 0; $i < self::FLUSH_RETRY_COUNT; ++$i) {
            if ($this->producer->flush($timeout) === RD_KAFKA_RESP_ERR_NO_ERROR) {
                return;
            }
        }

        throw new RuntimeException($payload);
    }

    private function getTopic(string $name): ProducerTopic
    {
        if (false === isset($this->topics[$name])) {
            $this->topics[$name] = $this->producer->newTopic($name);
        }

        return $this->topics[$name];
    }
}
