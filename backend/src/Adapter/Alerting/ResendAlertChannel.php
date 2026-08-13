<?php

declare(strict_types=1);

namespace App\Adapter\Alerting;

use App\Model\AlertChannel;
use App\Model\Notification;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends alerts through Resend's HTTPS API.
 *
 * Not SMTP, and not by choice: Render blocks outbound traffic to ports 25, 465 and 587 on
 * free web services, so a normal mailer would work on a laptop and time out in production.
 * Port 443 is open everywhere.
 */
final class ResendAlertChannel implements AlertChannel
{
    private const ENDPOINT = 'https://api.resend.com/emails';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(RESEND_API_KEY)%')]
        private readonly string $apiKey = '',
        #[Autowire('%env(ALERT_EMAIL_TO)%')]
        private readonly string $to = '',
        #[Autowire('%env(ALERT_EMAIL_FROM)%')]
        private readonly string $from = 'onboarding@resend.dev',
        // The console URL is already known: it is the origin browsers are allowed to call
        // from. Reusing it keeps the alert clickable without another variable to forget.
        #[Autowire('%env(CORS_ALLOWED_ORIGINS)%')]
        private readonly string $allowedOrigins = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->to) !== '';
    }

    public function send(Notification $alert): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        try {
            $response = $this->httpClient->request('POST', self::ENDPOINT, [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'from' => $this->from !== '' ? $this->from : 'onboarding@resend.dev',
                    'to' => array_map(trim(...), explode(',', $this->to)),
                    'subject' => $alert->subject(),
                    'text' => $this->text($alert),
                ],
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('Resend answered %d: %s', $status, mb_substr($response->getContent(false), 0, 200)));
            }
        } catch (HttpExceptionInterface $exception) {
            throw new \RuntimeException('Could not reach Resend.', 0, $exception);
        }
    }

    private function text(Notification $alert): string
    {
        $body = $alert->body();

        $console = $this->consoleUrl();
        if ($console !== null) {
            $body .= sprintf("\n\n%s/projects/%s", $console, $alert->project->gameId->value);
        }

        return $body;
    }

    private function consoleUrl(): ?string
    {
        foreach (explode(',', $this->allowedOrigins) as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin !== '') {
                return $origin;
            }
        }

        return null;
    }
}
