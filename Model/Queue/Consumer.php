<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mail\Model\Queue;

use Magento\Framework\Mail\TransportInterfaceFactory;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use Zero\Mail\Model\Config;
use Zero\Mail\Model\Mailer;
use Zero\Mail\Model\MailLog;

/**
 * The consumer zero.mail.send: a queued Email Log entry sent through the
 * transport set now (SES, or Magento's own with the message as it was
 * built). A failure puts the entry back in the queue, up to ATTEMPTS times;
 * the entry then stays failed, with the error, for Resend.
 */
class Consumer
{
    public const ATTEMPTS = 3;

    public function __construct(
        private readonly MailLog $log,
        private readonly Mailer $mailer,
        private readonly Config $config,
        private readonly PublisherInterface $publisher,
        private readonly TransportInterfaceFactory $transportFactory,
        private readonly LoggerInterface $logger,
        private readonly MessageCodec $codec
    ) {
    }

    /**
     * @param string $body {"log_id": 12, "attempt": 1}
     */
    public function process(string $body): void
    {
        $data = json_decode($body, true);
        $id = (int) ($data['log_id'] ?? 0);
        $attempt = max(1, (int) ($data['attempt'] ?? 1));
        $entry = $id > 0 ? $this->log->get($id) : null;
        if ($entry === null || $entry['status'] !== MailLog::QUEUED || !$entry['queued_message']) {
            return; // sent, resent or purged meanwhile
        }
        $stored = (string) $entry['queued_message'];
        try {
            $message = $this->codec->decode($stored);
        } catch (\RuntimeException $e) {
            $this->logger->error(sprintf('Zero Mail: entry #%d refused, stored message unreadable: %s', $id, $e->getMessage()));
            $this->log->finish($id, MailLog::FAILED, 'The queued message could not be read.');
            return;
        }
        try {
            $this->mailer->deliver(
                $id,
                $this->config->transport(),
                $message,
                fn () => $this->transportFactory->create(['message' => new StoredMessage($message)])->sendMessage()
            );
        } catch (\Throwable $e) {
            if ($attempt >= self::ATTEMPTS) {
                $this->logger->error(sprintf('Zero Mail: entry #%d not sent after %d tries: %s', $id, $attempt, $e->getMessage()));
                return;
            }
            $this->log->queued($id, $stored, sprintf('try %d: %s', $attempt, $e->getMessage()));
            $this->publisher->publish(Mailer::TOPIC, json_encode(['log_id' => $id, 'attempt' => $attempt + 1]));
        }
    }
}
