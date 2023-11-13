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
        $count = $request->request->get('count');
        for ($i = 0; $i < $count; $i++) {
            $this->kafkaService->send(KafkaService::SEND_MESSAGE_TOPIC, ['text' => $text.' #'.$i]);
        }

        return new JsonResponse(['success' => true], Response::HTTP_OK);
    }
}
