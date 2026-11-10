<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mail\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Address;
use Magento\Framework\Mail\EmailMessage;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\TextPart;
use Zero\Mail\Model\Config;
use Zero\Mail\Model\Mailer;
use Zero\Mail\Model\MailLog;
use Zero\Mail\Model\Ses;

/**
 * What becomes of an email: sent through which transport, kept in the log
 * only, or failed, and what the log says of it.
 *
 * The mocks are shared by the tests; each sets expectations on the ones it's about.
 */
#[AllowMockObjectsWithoutExpectations]
class MailerTest extends TestCase
{
    /** @var array<string, string> */
    private array $settings = [];
    private MailLog&MockObject $log;
    private Ses&MockObject $ses;
    private PublisherInterface&MockObject $publisher;
    private Mailer $mailer;
    /** @var array<string, mixed>|null */
    private ?array $row = null;
    /** @var array<int, array<int, mixed>> */
    private array $finished = [];

    protected function setUp(): void
    {
        $this->settings = [
            'zero_mail/general/transport' => 'magento',
            'zero_mail/general/test_domains' => "example.com\ntest",
        ];
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $this->settings[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn ($path) => (bool) ($this->settings[$path] ?? false));
        $this->log = $this->createMock(MailLog::class);
        $this->log->method('add')->willReturnCallback(function (array $row) {
            $this->row = $row;
            return 7;
        });
        $this->log->method('finish')->willReturnCallback(function (...$args) {
            $this->finished[] = $args;
        });
        $this->ses = $this->createMock(Ses::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->mailer = new Mailer(
            new Config($scopeConfig),
            $this->log,
            $this->ses,
            $this->publisher,
            new \Zero\Mail\Model\Queue\MessageCodec()
        );
    }

    public function testThroughMagentosTransportAndLogged(): void
    {
        $sent = false;
        $this->mailer->send($this->message(['olena@gmail.com' => 'Олена']), function () use (&$sent) {
            $sent = true;
        });

        $this->assertTrue($sent);
        $this->assertSame('magento', $this->row['transport']);
        $this->assertSame('Your order #100', $this->row['subject']);
        $this->assertSame('Shop <sales@shop.test>', $this->row['sender']);
        $this->assertSame('Олена <olena@gmail.com>', $this->row['recipient']);
        $this->assertSame('<p>Thank you</p>', $this->row['body']);
        $this->assertSame('html', $this->row['body_type']);
        $this->assertSame([[7, MailLog::SENT, null, null]], $this->finished);
        $this->assertSame(7, $this->mailer->lastLogId());
    }

    public function testOnlyTestAddressesAreLoggedNotSent(): void
    {
        $this->mailer->send($this->message(['zero-e2e+1@example.com' => '', 'a@shop.test' => '']), function () {
            $this->fail('sent to test addresses');
        });

        $this->assertSame(MailLog::SKIPPED, $this->finished[0][1]);
    }

    public function testATestAddressAmongRealOnesIsSent(): void
    {
        $sent = false;
        $this->mailer->send($this->message(['zero-e2e@example.com' => '', 'olena@gmail.com' => '']), function () use (&$sent) {
            $sent = true;
        });

        $this->assertTrue($sent);
    }

    public function testLogOnlyAndEmailCommunicationsOffSendNothing(): void
    {
        $this->settings['zero_mail/general/transport'] = 'log';
        $this->mailer->send($this->message(['olena@gmail.com' => '']), fn () => $this->fail('sent'));
        $this->settings['system/smtp/disable'] = '1';
        $this->mailer->send($this->message(['olena@gmail.com' => '']), fn () => $this->fail('sent'));

        $this->assertSame([MailLog::LOGGED, MailLog::DISABLED], array_column($this->finished, 1));
    }

    public function testSesGetsTheRawMessageWithoutBccTheSenderSetAndAllRecipients(): void
    {
        $this->settings['zero_mail/general/transport'] = 'ses';
        $this->settings['zero_mail/general/sender_email'] = 'orders@fixstacks.com';
        $this->ses->expects($this->once())->method('send')->with(
            $this->callback(fn (string $raw) => str_contains($raw, 'From: Shop <orders@fixstacks.com>')
                && !str_contains($raw, 'Bcc:') && str_contains($raw, 'Subject: Your order #100')),
            'orders@fixstacks.com',
            ['to' => ['olena@gmail.com'], 'cc' => [], 'bcc' => ['archive@shop.test']]
        )->willReturn('ses-id-1');

        $this->mailer->send($this->message(['olena@gmail.com' => ''], ['archive@shop.test' => '']), fn () => $this->fail('magento'));

        $this->assertSame('Shop <orders@fixstacks.com>', $this->row['sender']);
        $this->assertSame([[7, MailLog::SENT, null, 'ses-id-1']], $this->finished);
    }

    public function testAFailureIsLoggedAndPassedOn(): void
    {
        $this->expectExceptionMessage('Connection refused');
        try {
            $this->mailer->send($this->message(['olena@gmail.com' => '']), function () {
                throw new \RuntimeException('Connection refused');
            });
        } finally {
            $this->assertSame([[7, MailLog::FAILED, 'Connection refused', null]], $this->finished);
        }
    }

    public function testThroughTheQueueTheEntryWaitsForTheConsumer(): void
    {
        $this->settings['zero_mail/general/queue'] = '1';
        $this->settings['zero_mail/general/transport'] = 'ses';
        $this->ses->expects($this->never())->method('send');
        $this->log->expects($this->once())->method('queued')->with(
            7,
            $this->callback(
                fn (string $stored) => str_contains($stored, 'Thank you')
                    && (new \Zero\Mail\Model\Queue\MessageCodec())->decode($stored) instanceof \Symfony\Component\Mime\Message
            ),
            null
        );
        $this->publisher->expects($this->once())->method('publish')->with('zero.mail.send', '{"log_id":7,"attempt":1}');
        $this->mailer->send($this->message(['olena@gmail.com' => '']), fn () => $this->fail('magento'));
        $this->assertSame([], $this->finished);
    }

    public function testTestAddressesAndLogOnlyAreNotQueued(): void
    {
        $this->settings['zero_mail/general/queue'] = '1';
        $this->publisher->expects($this->never())->method('publish');
        $this->mailer->send($this->message(['zero-e2e@example.com' => '']), fn () => $this->fail('sent'));
        $this->settings['zero_mail/general/transport'] = 'log';
        $this->mailer->send($this->message(['olena@gmail.com' => '']), fn () => $this->fail('sent'));
        $this->assertSame([MailLog::SKIPPED, MailLog::LOGGED], array_column($this->finished, 1));
    }

    public function testAResentEmailPointsToItsEntry(): void
    {
        $this->mailer->resending(3, fn () => $this->mailer->send($this->message(['olena@gmail.com' => '']), fn () => null));
        $this->assertSame(3, $this->row['resent_from']);

        $this->mailer->send($this->message(['olena@gmail.com' => '']), fn () => null);
        $this->assertNull($this->row['resent_from']);
    }

    /**
     * @param array<string, string> $to email => name
     * @param array<string, string> $bcc
     */
    private function message(array $to, array $bcc = []): EmailMessage
    {
        $symfony = new Message(null, new TextPart('<p>Thank you</p>', 'utf-8', 'html'));
        $headers = $symfony->getHeaders();
        $headers->addMailboxListHeader('From', [new \Symfony\Component\Mime\Address('sales@shop.test', 'Shop')]);
        $headers->addMailboxListHeader('To', array_keys($to));
        if ($bcc) {
            $headers->addMailboxListHeader('Bcc', array_keys($bcc));
        }
        $headers->addTextHeader('Subject', 'Your order #100');
        $address = static fn (string $email, string $name) => new Address($email, $name ?: null);
        $message = $this->createStub(EmailMessage::class);
        $message->method('getSymfonyMessage')->willReturn($symfony);
        $message->method('getSubject')->willReturn('Your order #100');
        $message->method('getTo')->willReturn(array_map($address, array_keys($to), $to));
        $message->method('getCc')->willReturn(null);
        $message->method('getBcc')->willReturn(array_map($address, array_keys($bcc), $bcc));
        // As EmailMessage: read from the headers, so a sender set by the Mailer shows.
        $message->method('getFrom')->willReturnCallback(static function () use ($symfony) {
            $from = $symfony->getHeaders()->get('From')->getAddresses()[0];
            return [new Address($from->getAddress(), $from->getName() ?: null)];
        });

        return $message;
    }
}
