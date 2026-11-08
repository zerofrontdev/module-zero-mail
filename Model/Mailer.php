<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mail\Model;

use Magento\Framework\Mail\Address;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\MessageQueue\PublisherInterface;
use Symfony\Component\Mime\Address as MimeAddress;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\TextPart;
use Zero\Mail\Model\Queue\MessageCodec;

/**
 * Every email on its way out (Plugin\Transport): the sender address set,
 * logged (without its one-time links and gift card codes: Secrets), then sent
 * through the transport chosen, or not: test addresses and "log only" stay in
 * the log. With Send Through the Queue, the entry waits as queued and
 * Queue\Consumer sends it (deliver()).
 */
class Mailer
{
    public const TOPIC = 'zero.mail.send';

    private ?int $resending = null;
    private ?int $lastLogId = null;

    public function __construct(
        private readonly Config $config,
        private readonly MailLog $log,
        private readonly Ses $ses,
        private readonly PublisherInterface $publisher,
        private readonly MessageCodec $codec = new MessageCodec()
    ) {
    }

    /**
     * @param callable(): void $magento Magento's own transport
     * @throws \Throwable what the transport threw, logged first
     */
    public function send(EmailMessage $message, callable $magento): void
    {
        $this->setSender($message);
        [$body, $type] = $this->body($message);
        $from = $message->getFrom()[0] ?? null;
        $transport = $this->config->transport();
        $id = $this->log->add([
            'transport' => $transport,
            'subject' => mb_substr((string) $message->getSubject(), 0, 255),
            'sender' => $from ? mb_substr($this->format($from), 0, 255) : null,
            'recipient' => mb_substr(implode(', ', array_map([$this, 'format'], $message->getTo())), 0, 512),
            'recipients' => json_encode([
                'to' => $this->pairs($message->getTo()),
                'cc' => $this->pairs($message->getCc() ?? []),
                'bcc' => $this->pairs($message->getBcc() ?? []),
            ], JSON_UNESCAPED_UNICODE),
            'body' => Secrets::redact($body),
            'body_type' => $type,
            'resent_from' => $this->resending,
        ]);
        $this->lastLogId = $id;
        if ($this->config->isDisabled()) {
            $this->log->finish($id, MailLog::DISABLED);
            return;
        }
        $symfony = $message->getSymfonyMessage();
        $all = array_merge(...array_values($this->recipients($symfony)));
        if ($all && count(array_filter($all, [$this->config, 'isTestAddress'])) === count($all)) {
            $this->log->finish($id, MailLog::SKIPPED);
            return;
        }
        if ($transport === Config::TRANSPORT_LOG) {
            $this->log->finish($id, MailLog::LOGGED);
            return;
        }
        if ($this->config->isQueued()) {
            try {
                $this->log->queued($id, $this->codec->encode($symfony));
            } catch (\RuntimeException) {
                // A part the queue format does not hold: sent now rather than lost.
                $this->deliver($id, $transport, $symfony, $magento);
                return;
            }
            $this->publisher->publish(self::TOPIC, json_encode(['log_id' => $id, 'attempt' => 1]));
            return;
        }
        $this->deliver($id, $transport, $symfony, $magento);
    }

    /**
     * Log entry $id sent now, through $transport (SES, or Magento's own:
     * $magento), and finished as sent or failed.
     *
     * @param callable(): void $magento
     * @throws \Throwable what the transport threw, logged first
     */
    public function deliver(int $id, string $transport, SymfonyMessage $message, callable $magento): void
    {
        try {
            $messageId = null;
            if ($transport === Config::TRANSPORT_SES) {
                $from = $this->addresses($message, 'From')[0] ?? '';
                $messageId = $this->ses->send($this->raw($message), $from, $this->recipients($message));
            } else {
                $magento();
            }
        } catch (\Throwable $e) {
            $this->log->finish($id, MailLog::FAILED, $e->getMessage());
            throw $e;
        }
        $this->log->finish($id, MailLog::SENT, null, $messageId);
    }

    /**
     * The Email Log entry of the last email sent in this request.
     */
    public function lastLogId(): ?int
    {
        return $this->lastLogId;
    }

    /**
     * Marks the emails sent while $send runs as resent from log entry $id.
     */
    public function resending(int $id, callable $send): void
    {
        $this->resending = $id;
        try {
            $send();
        } finally {
            $this->resending = null;
        }
    }

    private function setSender(EmailMessage $message): void
    {
        $email = $this->config->senderEmail();
        if ($email === '') {
            return;
        }
        $from = $message->getFrom()[0] ?? null;
        $headers = $message->getSymfonyMessage()->getHeaders();
        $headers->remove('From');
        $headers->addMailboxListHeader('From', [new MimeAddress($email, (string) $from?->getName())]);
    }

    /**
     * The message as SES takes it: MIME, without the Bcc header.
     */
    private function raw(SymfonyMessage $message): string
    {
        $copy = clone $message;
        $copy->getHeaders()->remove('Bcc');
        return $copy->toString();
    }

    /**
     * @return array{to: string[], cc: string[], bcc: string[]}
     */
    private function recipients(SymfonyMessage $message): array
    {
        return [
            'to' => $this->addresses($message, 'To'),
            'cc' => $this->addresses($message, 'Cc'),
            'bcc' => $this->addresses($message, 'Bcc'),
        ];
    }

    /**
     * @return string[] the emails of a mailbox-list header
     */
    private function addresses(SymfonyMessage $message, string $header): array
    {
        $header = $message->getHeaders()->get($header);
        if (!$header instanceof MailboxListHeader) {
            return [];
        }
        return array_values(array_filter(
            array_map(static fn (MimeAddress $a) => $a->getAddress(), $header->getAddresses())
        ));
    }

    /**
     * @return array{0: string, 1: string} the HTML (or plain text) part, and which
     */
    private function body(EmailMessage $message): array
    {
        $part = $message->getSymfonyMessage()->getBody();
        $parts = $part instanceof AbstractMultipartPart ? $part->getParts() : [$part];
        $text = null;
        foreach ($parts as $candidate) {
            if ($candidate instanceof TextPart && $candidate->getMediaSubtype() === 'html') {
                return [$candidate->getBody(), 'html'];
            }
            if ($candidate instanceof TextPart && $text === null) {
                $text = $candidate->getBody();
            }
        }
        return [(string) $text, 'plain'];
    }

    /**
     * @param Address[] $addresses
     * @return array<int, array{email: string, name: string}>
     */
    private function pairs(array $addresses): array
    {
        return array_map(
            static fn (Address $a) => ['email' => (string) $a->getEmail(), 'name' => (string) $a->getName()],
            $addresses
        );
    }

    private function format(Address $address): string
    {
        return $address->getName()
            ? $address->getName() . ' <' . $address->getEmail() . '>'
            : (string) $address->getEmail();
    }
}
