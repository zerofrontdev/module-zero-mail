<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mail\Test\Unit\Model\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\TextPart;
use Zero\Mail\Model\Queue\MessageCodec;

/**
 * The stored form of a queued email: JSON out, the same message back.
 */
class MessageCodecTest extends TestCase
{
    private MessageCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new MessageCodec();
    }

    public function testAnEmailWithAttachmentRoundTripsToTheSameWireFormat(): void
    {
        $email = (new Email())
            ->from(new Address('sales@shop.test', 'Shop Ünï'))
            ->to(new Address('olena@gmail.com', 'Олена'))
            ->bcc('archive@shop.test')
            ->replyTo('help@shop.test')
            ->subject('Ваше замовлення #100')
            ->text('Thank you')
            ->html('<p>Thank you</p>')
            ->attach("%PDF-1.4\x00\xff binary", 'invoice.pdf', 'application/pdf');
        $email->getHeaders()->addDateHeader('Date', new \DateTimeImmutable('2026-11-02 10:00:00 +00:00'));
        $email->getHeaders()->addIdHeader('Message-ID', 'fixed@shop.test');

        $stored = $this->codec->encode($email);
        $this->assertJson($stored);
        $back = $this->codec->decode($stored);

        $this->assertSame($this->raw($email), $this->raw($back));
        $this->assertSame('olena@gmail.com', $back->getHeaders()->get('To')->getAddresses()[0]->getAddress());
        $this->assertSame('archive@shop.test', $back->getHeaders()->get('Bcc')->getAddresses()[0]->getAddress());
    }

    public function testASimpleMessageAndABodylessOneRoundTrip(): void
    {
        $message = new Message(null, new TextPart('Hi', 'utf-8', 'html'));
        $message->getHeaders()->addMailboxListHeader('From', ['a@b.test'])->addTextHeader('Subject', 'Hi');
        $this->assertSame($this->raw($message), $this->raw($this->codec->decode($this->codec->encode($message))));

        $empty = new Message();
        $empty->getHeaders()->addMailboxListHeader('From', ['a@b.test']);
        $this->assertNull($this->codec->decode($this->codec->encode($empty))->getBody());
    }

    public function testNestedMultipartRoundTrips(): void
    {
        $body = new MixedPart(
            new AlternativePart(new TextPart('plain'), new TextPart('<b>x</b>', 'utf-8', 'html')),
            new DataPart('abc', 'a.txt', 'text/plain')
        );
        $message = new Message(null, $body);
        $message->getHeaders()->addMailboxListHeader('From', ['a@b.test']);
        $back = $this->codec->decode($this->codec->encode($message));
        $this->assertInstanceOf(MixedPart::class, $back->getBody());
        $this->assertCount(2, $back->getBody()->getParts());
        $this->assertInstanceOf(AlternativePart::class, $back->getBody()->getParts()[0]);
    }

    public function testALegacySerializedMessageIsStillRead(): void
    {
        $email = (new Email())->from('a@b.test')->to('c@d.test')->subject('Old')->text('old row');
        // phpcs:ignore Magento2.Security.InsecureFunction
        $back = $this->codec->decode(serialize($email));
        $this->assertSame('Old', $back->getHeaders()->getHeaderBody('Subject'));
    }

    public function testALegacyRowWithAForeignClassIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        // phpcs:ignore Magento2.Security.InsecureFunction
        $this->codec->decode(serialize(new \stdClass()));
    }

    public function testALegacyAttachmentReadingAPathIsNotAccepted(): void
    {
        $evil = 'O:35:"Symfony\\Component\\Mime\\Part\\File":1:{s:41:"' . "\0" . 'Symfony\\Component\\Mime\\Part\\File' . "\0" . 'path";s:11:"/etc/passwd";}';
        $this->expectException(\RuntimeException::class);
        $this->codec->decode($evil);
    }

    /**
     */
    #[DataProvider('brokenRows')]
    public function testBrokenOrForeignRowsAreRefused(string $row): void
    {
        $this->expectException(\RuntimeException::class);
        $this->codec->decode($row);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brokenRows(): array
    {
        return [
            'not json' => ['{nope'],
            'wrong version' => ['{"v":2,"headers":[],"body":null}'],
            'scalar' => ['{"v":1,"headers":"x"}'],
            'unknown header type' => ['{"v":1,"headers":[{"n":"X","t":"Evil\\\\Cls","v":"1"}],"body":null}'],
            'unknown part type' => ['{"v":1,"headers":[],"body":{"t":"Evil\\\\Cls"}}'],
            'unknown multipart' => ['{"v":1,"headers":[],"body":{"t":"multi","k":"Evil\\\\Cls","parts":[]}}'],
            'bad base64' => ['{"v":1,"headers":[],"body":{"t":"data","body":"***","type":"a/b"}}'],
            'garbage' => ['hello'],
            'empty' => [''],
        ];
    }

    private function raw(Message $m): string
    {
        $headers = $m->getHeaders();
        $headers->has('Date') || $headers->addDateHeader('Date', new \DateTimeImmutable('2026-11-02 10:00:00 +00:00'));
        $headers->has('Message-ID') || $headers->addIdHeader('Message-ID', 'fixed@shop.test');
        // Boundaries are random per message.
        $text = preg_replace('/^--[A-Za-z0-9_-]+?(--)?\r?$/m', '--B$1', $m->toString());
        return preg_replace('/boundary=\S+/', 'boundary=B', (string) $text);
    }
}
