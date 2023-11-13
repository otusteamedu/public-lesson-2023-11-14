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
