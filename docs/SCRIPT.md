# Kafka. Используем в Symfony

## Готовим проект

1. Запускаем контейнеры командой `docker-compose up -d`
2. Входим в контейнер командой `docker exec -it php sh`. Дальнейшие команды будем выполнять из контейнера
3. Устанавливаем зависимости командой `composer install`
4. Выполняем миграции комадной `php bin/console doctrine:migrations:migrate`

## Проверяем работоспособность приложения

1. Выполняем запрос Add message из Postman-коллекции, получаем успешный ответ.
2. Проверяем, что в БД появилась соответствующая запись.

## Устанавливаем Kafka

1. Исправляем файл `docker/Dockerfile`
    ```dockerfile
    FROM php:8.1-fpm-alpine
    
    # Install dev dependencies
    RUN apk update \
        && apk upgrade --available \
        && apk add --virtual build-deps \
            autoconf \
            build-base \
            icu-dev \
            libevent-dev \
            openssl-dev \
            zlib-dev \
            libzip \
            libzip-dev \
            zlib \
            zlib-dev \
            bzip2 \
            git \
            libpng \
            libpng-dev \
            libjpeg \
            libjpeg-turbo-dev \
            libwebp-dev \
            freetype \
            freetype-dev \
            postgresql-dev \
            curl \
            wget \
            bash \
            librdkafka-dev
    
    # Install Composer
    RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer
    
    # Install PHP extensions
    RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/
    RUN docker-php-ext-install -j$(getconf _NPROCESSORS_ONLN) \
        intl \
        gd \
        bcmath \
        pdo_pgsql \
        pcntl \
        sockets \
        zip
    RUN pecl channel-update pecl.php.net \
        && pecl install -o -f \
            rdkafka \
            redis \
            event \
        && rm -rf /tmp/pear \
        && echo "extension=rdkafka.so" > /usr/local/etc/php/conf.d/rdkafka.ini \
        && echo "extension=redis.so" > /usr/local/etc/php/conf.d/redis.ini \
        && echo "extension=event.so" > /usr/local/etc/php/conf.d/event.ini
    ```
2. Добавляем сервисы в файл `docker-compose.yml`
    ```yaml
    zookeeper:
      image: confluentinc/cp-zookeeper:latest
      environment:
        ZOOKEEPER_CLIENT_PORT: 2181
        ZOOKEEPER_TICK_TIME: 2000
      ports:
        - 22181:2181
    
    kafka:
      image: confluentinc/cp-kafka:latest
      depends_on:
        - zookeeper
      ports:
        - 2181:2181
        - 9092:9092
        - 29092:29092
      environment:
        KAFKA_BROKER_ID: 1
        KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
        KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://kafka:9092,PLAINTEXT_HOST://localhost:29092
        KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT,PLAINTEXT_HOST:PLAINTEXT
        KAFKA_INTER_BROKER_LISTENER_NAME: PLAINTEXT
        KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
  
  
    kafdrop:
      image: obsidiandynamics/kafdrop
      restart: "no"
      ports:
        - 9900:9000
      environment:
        KAFKA_BROKERCONNECT: kafka:9092
      depends_on:
        - "kafka"
  
    ```
3. Выходим из контейнера и перезапускаем контейнеры с пересборкой командами
    ```shell
    docker-compose stop
    docker-compose up -d --build
    ```
4. Возвращаемся в контейнер командой `docker exec -it php sh`. Дальнейшие команды выполняются из контейнера.
5. Устанавливаем пакет `simpod/kafka-bundle`
6. Добавляем адрес Kafka в файл `.env`
    ```ini
    KAFKA_BROKERS=kafka:9092
    ```
7. Добавляем файл `config/packages/kafka.yaml`
    ```yaml
    kafka:
      bootstrap_servers: '%env(KAFKA_BROKERS)%'
      client:
        id: 'app'
    ```
8. Заходим по адресу http://localhost:9900 и создаём topic `save_message`
9. Добавляем класс `App\Service\KafkaService`
    ```php
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
    
        public function send(string $name, array $data, int $timeout = self::DEFAULT_TIMEOUT): void
        {
            $payload = json_encode($data, JSON_THROW_ON_ERROR);
    
            $this->getTopic($name)->produce(RD_KAFKA_PARTITION_UA, 0, $payload);
    
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
    ```
10. Исправляем класс `App\Controller\Api\v1\MessageController`
    ```php
    <?php
    
    namespace App\Controller\Api\v1;
    
    use App\Service\KafkaService;
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Attribute\AsController;
    use Symfony\Component\Routing\Annotation\Route;
    
    #[Route(path: '/api/v1/message')]
    #[AsController]
    class MessageController
    {
        public function __construct(private readonly KafkaService $kafkaService)
        {
        }
    
        #[Route(path: '', methods: ['POST'])]
        public function saveMessageAction(Request $request): Response
        {
            $text = $request->request->get('text');
            $this->kafkaService->send(KafkaService::SEND_MESSAGE_TOPIC, ['text' => $text]);
    
            return new JsonResponse(['success' => true], Response::HTTP_OK);
        }
    }
    
    ```
11. Выполняем запрос Add message из Postman-коллекции, получаем успешный ответ.
12. Проверяем в интерфейсе Kafdrop, что создался топик и в нём появилось сообщение.

## Добавляем консьюмер

1. Добавляем класс `App\Consumer\MessageConsumer`
    ```php
    <?php
    
    namespace App\Consumer;
    
    use App\Manager\MessageManager;
    use RdKafka\Message;
    use SimPod\Kafka\Clients\Consumer\ConsumerConfig;
    use SimPod\Kafka\Clients\Consumer\KafkaConsumer;
    use SimPod\KafkaBundle\Kafka\Configuration;
    use SimPod\KafkaBundle\Kafka\Clients\Consumer\NamedConsumer;
    
    final class MessageConsumer implements NamedConsumer
    {
        private const TIMEOUT_MS = 120000;
    
        public function __construct(
            private readonly Configuration $configuration,
            private readonly string $topic,
            private readonly string $groupId,
            private readonly string $name,
            private readonly MessageManager $messageManager,
        )
        {
        }
    
        public function run(): void
        {
            $kafkaConsumer = new KafkaConsumer($this->getConfig());
    
            $kafkaConsumer->subscribe([$this->topic]);
    
            while (true) {
                $kafkaConsumer->start(
                    self::TIMEOUT_MS,
                    function (Message $message) use ($kafkaConsumer) : void {
                        $data = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
                        $this->messageManager->createMessage($data['text']);
                        echo 'Processed message: '.$data['text']."\n";

                        $kafkaConsumer->commit($message);
                    }
                );
            }
        }
    
        public function getName(): string {
            return $this->name;
        }
    
        private function getConfig(): ConsumerConfig
        {
            $config = new ConsumerConfig();
    
            $config->set(ConsumerConfig::BOOTSTRAP_SERVERS_CONFIG, $this->configuration->getBootstrapServers());
            $config->set(ConsumerConfig::ENABLE_AUTO_COMMIT_CONFIG, false);
            $config->set(ConsumerConfig::CLIENT_ID_CONFIG, $this->configuration->getClientIdWithHostname());
            $config->set(ConsumerConfig::AUTO_OFFSET_RESET_CONFIG, 'earliest');
            $config->set(ConsumerConfig::GROUP_ID_CONFIG, $this->groupId);
    
            return $config;
        }
    }
    ```
2. В файле `config/services.yaml` добавляем новый сервис
    ```yaml
    App\Consumer\MessageConsumer:
        arguments:
            $topic: !php/const App\Service\KafkaService::SEND_MESSAGE_TOPIC
            $groupId: group1
            $name: send_message
    ```
3. Выполняем команду `php bin/console kafka:consumer:run send_message &`
4. Через некоторое время увидим сообщение в консоли об обработанном сообщении, проверяем, что запись появилась в БД.
5. Выполняем запрос Add message из Postman-коллекции, получаем успешный ответ, видим, что сообщение обработано и запись
появилась в БД.

## Пробуем масштабировать консьюмеры

1. В файле `config/services.yaml`
   1. Добавляем исключение автозагрузки сервисов из директории `src/Consumer`
   2. удаляем добавленный сервис и добавляем два новых сервиса
        ```yaml
        App\Consumer\MessageConsumer0:
            class: App\Consumer\MessageConsumer
            arguments:
                $topic: !php/const App\Service\KafkaService::SEND_MESSAGE_TOPIC
                $groupId: group1
                $name: send_message0

        App\Consumer\MessageConsumer1:
            class: App\Consumer\MessageConsumer
            arguments:
                $topic: !php/const App\Service\KafkaService::SEND_MESSAGE_TOPIC
                $groupId: group1
                $name: send_message1
        ```
2. В классе `App\Consumer\MessageConsumer` исправляем метод `run`
    ```php
    public function run(): void
    {
        $kafkaConsumer = new KafkaConsumer($this->getConfig());

        $kafkaConsumer->subscribe([$this->topic]);

        while (true) {
            $kafkaConsumer->start(
                self::TIMEOUT_MS,
                function (Message $message) use ($kafkaConsumer) : void {
                    $data = json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);
                    $this->messageManager->createMessage($this->name.' '.$data['text']);
                    echo $this->name.' processed message: '.$data['text']."\n";

                    $kafkaConsumer->commit($message);
                }
            );
        }
    }
    ```
3. В классе `App\Controller\Api\v1\MessageController` исправляем метод `saveMessageAction`
    ```php
    #[Route(path: '', methods: ['POST'])]
    public function saveMessageAction(Request $request): Response
    {
        $text = $request->request->get('text');
        $count = $request->request->get('count');
        for ($i = 0; $i < $count; $i++) {
            $this->kafkaService->send(KafkaService::SEND_MESSAGE_TOPIC, ['text' => $text.' #'.$i]);
        }

        return new JsonResponse(['success' => true], Response::HTTP_OK);
    }
    ```
4. Запускаем консьюмеры командами
    ```shell
    php bin/console kafka:consumer:run send_message0 &
    php bin/console kafka:consumer:run send_message1 &
    ```
5. Выполняем запрос Add message из Postman-коллекции с параметром `count = 20`
6. Видим, что все сообщения обработаны одним и тем же консьюмером.
7. Останавливаем консьюмер командой `kill -9 PID`
8. Ещё раз выполняем запрос Add message из Postman-коллекции с параметром `count = 20`
9. Видим, что второй консьюмер обрабатывает сообщения.
10. Останавливаем второй консьюмер

## Исправляем один из консьюмеров

1. В файле `config/services.yaml` исправляем описание сервиса `App\Consumer\MessageConsumer0`
    ```yaml
    App\Consumer\MessageConsumer0:
        class: App\Consumer\MessageConsumer
        arguments:
            $topic: !php/const App\Service\KafkaService::SEND_MESSAGE_TOPIC
            $groupId: group0
            $name: send_message0
    ```
2. Запускаем консьюмеры командами
    ```shell
    php bin/console kafka:consumer:run send_message0 &
    php bin/console kafka:consumer:run send_message1 &
    ```
3. Ещё раз выполняем запрос Add message из Postman-коллекции с параметром `count = 20`
4. Видим, что 0-й консьюмер обработал и все старые сообщения, а 1-й обработал только новые

## Добавляем партиции к топику

1. Выходим из контейнера php и заходим в контейнер kafka командой `docker exec -it kafka sh`
2. Выполняем команду `bin/kafka-topics --bootstrap-server localhost:9092 --alter --topic send_message --partitions 2`
3. В интерфейсе Kafdrop видим, что появилась вторая партиция
4. Выходим из контейнера kafka и заходим обратно в контейнер php командой `docker exec -it php sh`
5. Запускаем консьюмеры командами
    ```shell
    php bin/console kafka:consumer:run send_message0 &
    php bin/console kafka:consumer:run send_message1 &
    ```
6. Выполняем запрос Add message из Postman-коллекции с параметром `count = 20`
7. Видим, что сообщения как-то распределились по партициям, и каждый консьюмер обработал все сообщения, и они работали
параллельно.
8. Останавливаем оба консьюмера
9. В файле `config/services.yaml` исправляем описание сервиса `App\Consumer\MessageConsumer0`
    ```yaml
    App\Consumer\MessageConsumer0:
        class: App\Consumer\MessageConsumer
        arguments:
            $topic: !php/const App\Service\KafkaService::SEND_MESSAGE_TOPIC
            $groupId: group1
            $name: send_message0
    ```
10. Запускаем консьюмеры командами
     ```shell
     php bin/console kafka:consumer:run send_message0 &
     php bin/console kafka:consumer:run send_message1 &
     ```
11. Выполняем запрос Add message из Postman-коллекции с параметром `count = 20`
12. Видим, что теперь консьюмеры конкурирующие, и каждый читал только одно партицию.

## Распределяем сообщения на стороне продюсера

1. В классе `App\Service\KafkaService` исправляем метод `send`
    ```php
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
    ```
2. В классе `App\Controller\Api\v1\MessageController` исправляем метод `saveMessageAction`
    ```php
    #[Route(path: '', methods: ['POST'])]
    public function saveMessageAction(Request $request): Response
    {
        $text = $request->request->get('text');
        $count = $request->request->get('count');
        for ($i = 0; $i < $count; $i++) {
            $this->kafkaService->send(KafkaService::SEND_MESSAGE_TOPIC, ['text' => $text.' #'.$i], $i % 2);
        }

        return new JsonResponse(['success' => true], Response::HTTP_OK);
    }
    ```
3. Выполняем запрос Add message из Postman-коллекции с параметром `count = 20`
4. Видим, что теперь 0-й консьюмер получил чётные сообщения, а 1-й нечётные.
5. Останавливаем консьюмеры.

## Устанавливаем Symfony Messenger и Kafka Transport

1. Устанавливаем пакеты `symfony/messenger` и `koco/messenger-kafka`
2. В файл `.env` добавляем транспорт через Kafka
    ```ini
    MESSENGER_KAFKA_DSN=kafka://kafka:9092
    ```
3. Добавляем класс `App\DTO\Message`
    ```php
    <?php
    
    namespace App\DTO;
    
    class Message
    {
        public function __construct(private readonly string $text)
        {
        }
    
        public function getText(): string
        {
            return $this->text;
        }
    }
    ```
4. Исправляем файл `config/messenger.yaml`
    ```yaml
    framework:
        messenger:
            transports:
                producer:
                    dsn: '%env(MESSENGER_KAFKA_DSN)%'
                    options:
                        flushTimeout: 10000
                        flushRetries: 5
                        topic:
                            name: 'send_message'
                consumer:
                    dsn: '%env(MESSENGER_KAFKA_DSN)%'
                    options:
                        commitAsync: true
                        receiveTimeout: 10000
                        topic:
                            name: 'send_message'
                        kafka_conf:
                            enable.auto.offset.store: 'false'
                            group.id: 'group1'
                            max.poll.interval.ms: '45000'
                        topic_conf:
                            auto.offset.reset: 'earliest'
            routing:
                App\DTO\Message: kafka_producer
    ```
5. Исправляем класс `App\Controller\Api\v1\MessageController`
    ```php
    <?php
    
    namespace App\Controller\Api\v1;
    
    use App\DTO\Message;
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\HttpKernel\Attribute\AsController;
    use Symfony\Component\Messenger\MessageBusInterface;
    use Symfony\Component\Routing\Annotation\Route;
    
    #[Route(path: '/api/v1/message')]
    #[AsController]
    class MessageController
    {
        public function __construct(private readonly MessageBusInterface $messageBus)
        {
        }
    
        #[Route(path: '', methods: ['POST'])]
        public function saveMessageAction(Request $request): Response
        {
            $text = $request->request->get('text');
            $count = $request->request->get('count');
            for ($i = 0; $i < $count; $i++) {
                $this->messageBus->dispatch(new Message($text.' #'.$i));
            }
    
            return new JsonResponse(['success' => true], Response::HTTP_OK);
        }
    }
    ```
6. Выполняем запрос Add message из Postman-коллекции с параметром `count = 20`, видим, что сообщения распределились по
обеим партициям.

## Добавляем обработчик

1. Добавляем класс `App\Handler\MessageHandler`
    ```php
    <?php
    
    namespace App\Handler;
    
    use App\DTO\Message;
    use App\Manager\MessageManager;
    use Symfony\Component\Messenger\Attribute\AsMessageHandler;
    
    #[AsMessageHandler]
    class MessageHandler
    {
        public function __construct(private readonly MessageManager $messageManager)
        {
        }
    
        public function __invoke(Message $message): void
        {
            $this->messageManager->createMessage($message->getText());
        }
    }
    ```
2. Запускаем консьюмер командой `php /app/bin/console messenger:consume kafka_consumer --limit=1000 --env=dev -vv`
3. Видим, что сообщения успешно обработаны.
