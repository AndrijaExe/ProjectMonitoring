<?php

declare(strict_types=1);

namespace App\Adapter\Alerting;

use App\Model\AlertChannel;
use App\Model\DeviceTokenStore;
use App\Model\Notification;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends alerts to the phones through Expo's push service, which fans out to FCM and APNs.
 *
 * Expo rather than FCM directly, because the credentials for talking to Google live with the
 * build anyway: one service account uploaded once to Expo replaces a signed JWT dance here,
 * and this backend already speaks to everything else over plain HTTPS and a bearer token.
 *
 * A mail waits in an inbox, so a lost one can be found later. A push either wakes the phone
 * now or is worth nothing, which is why the priority is high and the delivery failures are
 * reported rather than swallowed.
 */
final class ExpoPushAlertChannel implements AlertChannel
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    /** Expo accepts a hundred messages per request. */
    private const BATCH = 100;

    /**
     * Must match the channel the app creates on start-up. Android silently drops a
     * notification naming a channel the device has never heard of, and the app creates it
     * before it can possibly have registered a token, so a token in this store implies a
     * channel on that phone.
     */
    private const CHANNEL_ID = 'alerts';

    /** A notification is a glance, and Expo caps the whole payload at 4096 bytes. */
    private const BODY_LIMIT = 400;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly DeviceTokenStore $devices,
        private readonly LoggerInterface $logger,
        // Optional. Only needed once an Expo account turns on enhanced security for pushes.
        #[Autowire('%env(EXPO_ACCESS_TOKEN)%')]
        private readonly string $accessToken = '',
    ) {
    }

    /**
     * Configured means somebody is listening. There is nothing to set up on this side: a phone
     * that signed in registered itself, and no phone means no push worth attempting.
     */
    public function isConfigured(): bool
    {
        return $this->devices->all() !== [];
    }

    public function send(Notification $alert): void
    {
        $tokens = $this->devices->all();
        if ($tokens === []) {
            return;
        }

        $delivered = 0;
        $attempted = 0;
        foreach (array_chunk($tokens, self::BATCH) as $batch) {
            $attempted += count($batch);
            $delivered += $this->push($batch, $alert);
        }

        // One phone out of three is a delivered alert. None of them is a silent failure, and
        // the caller records alarm state on the strength of this returning cleanly.
        if ($delivered === 0 && $attempted > 0) {
            throw new \RuntimeException('Expo accepted none of the push messages.');
        }
    }

    /**
     * @param list<string> $tokens
     *
     * @return int how many Expo accepted
     */
    private function push(array $tokens, Notification $alert): int
    {
        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'timeout' => 15,
                'headers' => array_filter([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'Authorization' => $this->accessToken !== '' ? 'Bearer '.$this->accessToken : null,
                ]),
                'json' => array_map(
                    fn (string $token): array => $this->message($token, $alert),
                    $tokens,
                ),
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('Expo answered %d: %s', $status, mb_substr($response->getContent(false), 0, 200)));
            }

            /** @var array{data?: list<array{status?: string, message?: string, details?: array{error?: string}}>, errors?: list<array{message?: string}>} $body */
            $body = $response->toArray(false);
        } catch (HttpExceptionInterface $exception) {
            throw new \RuntimeException('Could not reach Expo.', 0, $exception);
        }

        if (isset($body['errors']) && $body['errors'] !== []) {
            throw new \RuntimeException(sprintf('Expo rejected the request: %s', (string) ($body['errors'][0]['message'] ?? 'no reason given')));
        }

        return $this->readTickets($body['data'] ?? [], $tokens);
    }

    /**
     * Tickets come back in the order the messages went out, which is the only thing tying a
     * refusal to the phone that caused it.
     *
     * @param list<array{status?: string, message?: string, details?: array{error?: string}}> $tickets
     * @param list<string>                                                                   $tokens
     */
    private function readTickets(array $tickets, array $tokens): int
    {
        $accepted = 0;

        foreach ($tickets as $index => $ticket) {
            if (($ticket['status'] ?? '') === 'ok') {
                ++$accepted;

                continue;
            }

            $token = $tokens[$index] ?? null;
            $reason = $ticket['details']['error'] ?? 'unknown';

            // The phone is gone: uninstalled, or permission withdrawn. Keeping the token would
            // mean paying for a refusal on every alert from here on.
            if ($token !== null && $reason === 'DeviceNotRegistered') {
                $this->devices->forget($token);
            }

            $this->logger->warning('A phone refused a push alert.', [
                'reason' => $reason,
                'message' => $ticket['message'] ?? '',
            ]);
        }

        return $accepted;
    }

    /**
     * @return array<string, mixed>
     */
    private function message(string $token, Notification $alert): array
    {
        return [
            'to' => $token,
            'title' => $alert->subject(),
            'body' => self::shorten($alert->body()),
            'sound' => 'default',
            // Worth waking a sleeping phone for: the alternative is hearing about an outage
            // whenever Android next feels like opening a connection.
            'priority' => 'high',
            'channelId' => self::CHANNEL_ID,
            'data' => ['gameId' => $alert->project->gameId->value],
        ];
    }

    private static function shorten(string $body): string
    {
        $body = trim(preg_replace('/\s+/', ' ', $body) ?? $body);

        if (mb_strlen($body) <= self::BODY_LIMIT) {
            return $body;
        }

        return mb_substr($body, 0, self::BODY_LIMIT - 1).'…';
    }
}
