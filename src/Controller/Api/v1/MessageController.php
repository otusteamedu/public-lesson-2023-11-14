<?php

namespace App\Controller\Api\v1;

use App\Manager\MessageManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/api/v1/message')]
#[AsController]
class MessageController
{
    public function __construct(private readonly MessageManager $messageManager)
    {
    }

    #[Route(path: '', methods: ['POST'])]
    public function saveMessageAction(Request $request): Response
    {
        $text = $request->request->get('text');
        $message = $this->messageManager->createMessage($text);

        return new JsonResponse(['success' => true, 'messageId' => $message->getId()], Response::HTTP_OK);
    }
}
