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
