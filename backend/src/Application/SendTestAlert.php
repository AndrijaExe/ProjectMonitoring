<?php

declare(strict_types=1);

namespace App\Application;

use App\Model\Alert;
use App\Model\AlertChannel;
use App\Model\ProjectRepository;

final class SendTestAlert
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly AlertChannel $channel,
    ) {
    }

    /**
     * @return array{sent: bool, note: string}
     */
    public function execute(): array
    {
        if (!$this->channel->isConfigured()) {
            return [
                'sent' => false,
                'note' => 'Set RESEND_API_KEY and ALERT_EMAIL_TO on the API service first.',
            ];
        }

        $project = $this->projects->all()[0] ?? null;
        if ($project === null) {
            return ['sent' => false, 'note' => 'No projects registered, so there is nothing to name in the mail.'];
        }

        // Unlike the polling path, a failure here is the whole point of pressing the button,
        // so it is reported rather than logged and swallowed.
        try {
            $this->channel->send(Alert::drill($project));
        } catch (\Throwable $exception) {
            return ['sent' => false, 'note' => $exception->getMessage()];
        }

        return ['sent' => true, 'note' => 'Sent. It should arrive at ALERT_EMAIL_TO within a minute.'];
    }
}
