<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Persistence\Postgres\PostgresConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ReadyController
{
    public function __construct(
        private readonly PostgresConnection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/readyz', name: 'ready', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        try {
            $this->connection->pdo()->query('SELECT 1');
        } catch (\Throwable $exception) {
            $this->logger->error('Readiness database probe failed.', [
                'exceptionClass' => $exception::class,
            ]);

            return new JsonResponse(['status' => 'not_ready'], 503);
        }

        return new JsonResponse(['status' => 'ready']);
    }
}
