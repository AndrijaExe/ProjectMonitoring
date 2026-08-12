<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\IngestTokenAuthenticator;
use App\Application\IngestMetricBatch;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class MetricsIngestController
{
    public function __construct(
        private readonly IngestTokenAuthenticator $authenticator,
        private readonly IngestMetricBatch $ingestMetricBatch,
    ) {
    }

    #[Route('/api/v1/projects/{gameId}/metrics', name: 'metrics_ingest', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request, string $gameId): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        $this->authenticator->authenticate($gameId, (string) $request->headers->get('X-Ingest-Token', ''));

        try {
            $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Request body must be JSON.');
        }

        if (!is_array($payload) || !isset($payload['metrics']) || !is_array($payload['metrics'])) {
            throw new BadRequestHttpException('Field "metrics" must be an array.');
        }

        /** @var list<array{name?: mixed, value?: mixed, tags?: mixed, recorded_at?: mixed}> $metrics */
        $metrics = array_values($payload['metrics']);

        try {
            $batch = $this->ingestMetricBatch->execute($gameId, $metrics);
        } catch (\InvalidArgumentException $exception) {
            throw new BadRequestHttpException($exception->getMessage());
        }

        return new JsonResponse([
            'accepted' => count($batch->samples),
        ], 202);
    }
}
