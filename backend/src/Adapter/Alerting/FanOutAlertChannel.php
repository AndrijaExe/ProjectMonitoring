<?php

declare(strict_types=1);

namespace App\Adapter\Alerting;

use App\Model\AlertChannel;
use App\Model\Notification;
use Psr\Log\LoggerInterface;

/**
 * Sends every alert down every route that is set up: the inbox and the phones.
 *
 * The two are not redundant. Mail is the record — it waits, it is searchable, it survives a
 * flat battery. Push is the interruption, and only it arrives while the operator is away from
 * a desk. Losing either would change what the monitor is for, so both get the same alert.
 *
 * Failure is judged by whether the human was reached, not by whether every route worked. The
 * caller records alarm state only when this returns cleanly, so throwing because push failed
 * while the mail went out would re-raise the same alarm on the next poll — and the operator
 * would pay for one broken route with a duplicate mail every ten minutes.
 */
final class FanOutAlertChannel implements AlertChannel
{
    /** @var list<AlertChannel> */
    private readonly array $channels;

    public function __construct(
        ResendAlertChannel $mail,
        ExpoPushAlertChannel $push,
        private readonly LoggerInterface $logger,
    ) {
        $this->channels = [$mail, $push];
    }

    public function isConfigured(): bool
    {
        foreach ($this->channels as $channel) {
            if ($channel->isConfigured()) {
                return true;
            }
        }

        return false;
    }

    public function send(Notification $alert): void
    {
        $reached = 0;
        $failed = 0;
        $last = null;

        foreach ($this->channels as $channel) {
            if (!$channel->isConfigured()) {
                continue;
            }

            try {
                $channel->send($alert);
                ++$reached;
            } catch (\Throwable $exception) {
                ++$failed;
                $last = $exception;

                $this->logger->warning('An alert route failed.', [
                    'route' => $channel::class,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        // Nothing set up at all is not a failure: it is a monitor with no way to tell anybody,
        // which the callers already check for before they get here.
        if ($reached === 0 && $failed > 0) {
            throw new \RuntimeException('No alert route accepted the alert.', 0, $last);
        }
    }
}
