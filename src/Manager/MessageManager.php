<?php

namespace App\Manager;

use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;

class MessageManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function createMessage(string $text): Message
    {
        $message = new Message();
        $message->setText($text);
        $message->setCreatedAt();
        $message->setUpdatedAt();
        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $message;
    }
}
