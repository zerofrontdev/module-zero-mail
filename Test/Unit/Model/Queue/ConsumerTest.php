<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mail\Test\Unit\Model\Queue;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Framework\Mail\TransportInterfaceFactory;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\TextPart;
use Zero\Mail\Model\Config;
use Zero\Mail\Model\Mailer;
use Zero\Mail\Model\MailLog;
use Zero\Mail\Model\Queue\Consumer;
use Zero\Mail\Model\Queue\MessageCodec;
use Zero\Mail\Model\Queue\StoredMessage;
use Zero\Mail\Model\Ses;

/**
 * A queued Email Log entry sent by the consumer: through SES or Magento's
 * transport, tried again after a failure, failed for good after the last try.
 */
#[AllowMockObjectsWithoutExpectations]
class ConsumerTest extends TestCase
{
    /** @var array<string, string> */
    private array $settings = ['zero_mail/general/transport' => 'ses'];
    private MailLog&MockObject $log;
    private Ses&MockObject $ses;
    private PublisherInterface&MockObject $publisher;
    private TransportInterfaceFactory&MockObject $transportFactory;
    private LoggerInterface&MockObject $logger;
    private Consumer $consumer;
    /** @var array<string, mixed>|null */
    private ?array $entry;
    /** @var array<int, array<int, mixed>> */
    private array $finished = [];

    protected function setUp(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn ($path) => $this->settings[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn ($path) => (bool) ($this->settings[$path] ?? false));
        $config = new Config($scopeConfig);
        $this->log = $this->createMock(MailLog::class);
        $this->log->method('get')->willReturnCallback(fn () => $this->entry);
        $this->log->method('finish')->willReturnCallback(function (...$args) {
            $this->finished[] = $args;
        });
        $this->ses = $this->createMock(Ses::class);
        $this->publisher = $this->createMock(PublisherInterface::class);
        $this->transportFactory = $this->createMock(TransportInterfaceFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->consumer = new Consumer(
            $this->log,
            new Mailer($config, $this->log, $this->ses, $this->publisher, new MessageCodec()),
            $config,
            $this->publisher,
            $this->transportFactory,
            $this->logger,
            new MessageCodec()
        );
        $this->entry = [
            'log_id' => 7,
            'status' => MailLog::QUEUED,
            'queued_message' => (new MessageCodec())->encode($this->message()),
        ];
    }

    public function testSentThroughSes(): void
    {
        $this->ses->expects($this->once())->method('send')->with(
            $this->callback(fn (string $raw) => str_contains($raw, 'Subject: Your order #100') && !str_contains($raw, 'Bcc:')),
            'sales@shop.test',
            ['to' => ['olena@gmail.com'], 'cc' => [], 'bcc' => ['archive@shop.test']]
        )->willReturn('ses-id-9');
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->assertSame([[7, MailLog::SENT, null, 'ses-id-9']], $this->finished);
    }

    public function testSentThroughMagentosTransportAsItWasBuilt(): void
    {
        $this->settings['zero_mail/general/transport'] = 'magento';
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects($this->once())->method('sendMessage');
        $this->transportFactory->expects($this->once())->method('create')->with($this->callback(function (array $args) {
            $message = $args['message'];
            return $message instanceof StoredMessage
                && $message->getSymfonyMessage()->getHeaders()->getHeaderBody('Subject') === 'Your order #100'
                && $message->getTo()[0]->getEmail() === 'olena@gmail.com'
                && $message->getBcc()[0]->getEmail() === 'archive@shop.test'
                && $message->getFrom()[0]->getName() === 'Shop';
        }))->willReturn($transport);
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->assertSame([[7, MailLog::SENT, null, null]], $this->finished);
    }

    public function testAFailureQueuesTheEntryAgainUntilTheLastTry(): void
    {
        $this->ses->method('send')->willThrowException(new \RuntimeException('Connection refused'));
        $this->log->expects($this->once())->method('queued')
            ->with(7, $this->entry['queued_message'], 'try 1: Connection refused');
        $this->publisher->expects($this->once())->method('publish')->with('zero.mail.send', '{"log_id":7,"attempt":2}');
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->assertSame([[7, MailLog::FAILED, 'Connection refused', null]], $this->finished);

        $this->finished = [];
        $this->consumer->process('{"log_id":7,"attempt":' . Consumer::ATTEMPTS . '}');
        $this->assertSame([[7, MailLog::FAILED, 'Connection refused', null]], $this->finished);
    }

    public function testAnEntryNoLongerQueuedIsLeftAlone(): void
    {
        $this->entry['status'] = MailLog::SENT;
        $this->ses->expects($this->never())->method('send');
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->entry = null;
        $this->consumer->process('{"log_id":8,"attempt":1}');
        $this->assertSame([], $this->finished);
    }

    public function testAnOldPhpSerializedRowIsStillSent(): void
    {
        // phpcs:ignore Magento2.Security.InsecureFunction
        $this->entry['queued_message'] = serialize($this->message());
        $this->ses->expects($this->once())->method('send')->willReturn('ses-id-1');
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->assertSame([[7, MailLog::SENT, null, 'ses-id-1']], $this->finished);
    }

    public function testAnOldRowWithAForeignClassIsRefusedAndLogged(): void
    {
        $evil = 'O:11:"Evil\\Gadget":1:{s:3:"cmd";s:2:"id";}';
        $this->entry['queued_message'] = $evil;
        $this->ses->expects($this->never())->method('send');
        $this->logger->expects($this->once())->method('error')->with($this->stringContains('refused'));
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->assertSame([[7, MailLog::FAILED, 'The queued message could not be read.', null]], $this->finished);
    }

    public function testAnOldRowWithAForeignClassInsideAMessageIsRefused(): void
    {
        // phpcs:ignore Magento2.Security.InsecureFunction
        $row = serialize(['m' => $this->message(), 'x' => new \stdClass()]);
        $this->entry['queued_message'] = $row;
        $this->ses->expects($this->never())->method('send');
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->assertSame(MailLog::FAILED, $this->finished[0][1]);
    }

    public function testBrokenJsonIsRefusedAndLogged(): void
    {
        $this->entry['queued_message'] = '{"v":1,"headers":[{"n":"To","t":"Evil\\Class","v":"x"}],"body":null}';
        $this->ses->expects($this->never())->method('send');
        $this->logger->expects($this->once())->method('error');
        $this->consumer->process('{"log_id":7,"attempt":1}');
        $this->assertSame(MailLog::FAILED, $this->finished[0][1]);
    }

    private function message(): Message
    {
        $symfony = new Message(null, new TextPart('<p>Thank you</p>', 'utf-8', 'html'));
        $headers = $symfony->getHeaders();
        $headers->addMailboxListHeader('From', [new Address('sales@shop.test', 'Shop')]);
        $headers->addMailboxListHeader('To', ['olena@gmail.com']);
        $headers->addMailboxListHeader('Bcc', ['archive@shop.test']);
        $headers->addTextHeader('Subject', 'Your order #100');
        return $symfony;
    }
}
