<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Mail\Model\Queue;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\DateHeader;
use Symfony\Component\Mime\Header\HeaderInterface;
use Symfony\Component\Mime\Header\Headers;
use Symfony\Component\Mime\Header\IdentificationHeader;
use Symfony\Component\Mime\Header\MailboxHeader;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Header\PathHeader;
use Symfony\Component\Mime\Message as SymfonyMessage;
use Symfony\Component\Mime\Part\AbstractMultipartPart;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;

/**
 * A queued email as the Email Log stores it: JSON, version 1 (headers, and
 * the body as a tree of text, attachment and multipart parts). Reading it
 * builds only the known Symfony classes, from scalars, so a changed row
 * cannot instantiate anything else.
 *
 * Rows queued before this format are PHP-serialized; they are read with an
 * allow-list of the Symfony Mime classes a message is made of (never File,
 * which would read a path from the row) and can be dropped once the queue
 * has drained.
 */
class MessageCodec
{
    public const VERSION = 1;
    private const MAX_DEPTH = 8;
    private const MULTIPART = [
        'mixed' => MixedPart::class,
        'alternative' => AlternativePart::class,
        'related' => RelatedPart::class,
    ];
    private const LEGACY_CLASSES = [
        SymfonyMessage::class, Email::class, Headers::class, Address::class,
        MailboxListHeader::class, MailboxHeader::class, PathHeader::class, DateHeader::class,
        IdentificationHeader::class, ParameterizedHeader::class,
        \Symfony\Component\Mime\Header\UnstructuredHeader::class,
        TextPart::class, DataPart::class, MixedPart::class, AlternativePart::class, RelatedPart::class,
        \DateTimeImmutable::class, \DateTimeZone::class,
    ];

    /**
     * @throws \RuntimeException the message has a part this format does not hold
     */
    public function encode(SymfonyMessage $message): string
    {
        $headers = [];
        foreach ($message->getHeaders()->all() as $header) {
            $headers[] = $this->encodeHeader($header);
        }
        $body = $message->getBody();
        try {
            return json_encode(
                ['v' => self::VERSION, 'headers' => $headers, 'body' => $body ? $this->encodePart($body, 0) : null],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
        } catch (\JsonException $e) {
            throw new \RuntimeException('The message cannot be stored as JSON: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @throws \RuntimeException the row is not a message this codec wrote or knows
     */
    public function decode(string $stored): SymfonyMessage
    {
        $first = ltrim($stored)[0] ?? '';
        if ($first === '{') {
            return $this->decodeJson($stored);
        }
        if (!preg_match('/^O:\d+:"/', $stored)) {
            throw new \RuntimeException('The stored message has an unknown format.');
        }
        // A corrupt row warns; that must not become anything but a refusal.
        set_error_handler(static fn () => true);
        try {
            // phpcs:ignore Magento2.Security.InsecureFunction
            $message = unserialize($stored, ['allowed_classes' => self::LEGACY_CLASSES]);
        } finally {
            restore_error_handler();
        }
        if (!$message instanceof SymfonyMessage) {
            throw new \RuntimeException('The stored message is not a known message class.');
        }
        return $message;
    }

    private function decodeJson(string $stored): SymfonyMessage
    {
        try {
            $data = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('The stored message is not valid JSON.', 0, $e);
        }
        if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION || !is_array($data['headers'] ?? null)) {
            throw new \RuntimeException('The stored message has an unknown format.');
        }
        $headers = new Headers();
        foreach ($data['headers'] as $h) {
            $this->decodeHeader($headers, $this->arr($h));
        }
        $body = isset($data['body']) ? $this->decodePart($this->arr($data['body']), 0) : null;
        return new SymfonyMessage($headers, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeHeader(HeaderInterface $header): array
    {
        $out = ['n' => $header->getName()];
        if ($header instanceof MailboxListHeader) {
            return $out + ['t' => 'list', 'v' => array_map([$this, 'encodeAddress'], $header->getAddresses())];
        }
        if ($header instanceof MailboxHeader) {
            return $out + ['t' => 'mailbox', 'v' => $this->encodeAddress($header->getAddress())];
        }
        if ($header instanceof PathHeader) {
            return $out + ['t' => 'path', 'v' => $this->encodeAddress($header->getAddress())];
        }
        if ($header instanceof DateHeader) {
            return $out + ['t' => 'date', 'v' => $header->getDateTime()->format(\DATE_ATOM)];
        }
        if ($header instanceof IdentificationHeader) {
            return $out + ['t' => 'id', 'v' => $header->getIds()];
        }
        if ($header instanceof ParameterizedHeader) {
            return $out + ['t' => 'param', 'v' => $header->getValue(), 'p' => $header->getParameters()];
        }
        $body = $header->getBody();
        return $out + ['t' => 'text', 'v' => is_string($body) ? $body : $header->getBodyAsString()];
    }

    /**
     * @param array<string, mixed> $h
     */
    private function decodeHeader(Headers $headers, array $h): void
    {
        $name = $this->str($h['n'] ?? null);
        $value = $h['v'] ?? null;
        switch ($h['t'] ?? null) {
            case 'list':
                $headers->addMailboxListHeader($name, array_map([$this, 'decodeAddress'], $this->arr($value)));
                break;
            case 'mailbox':
                $headers->addMailboxHeader($name, $this->decodeAddress($value));
                break;
            case 'path':
                $headers->addPathHeader($name, $this->decodeAddress($value));
                break;
            case 'date':
                $headers->addDateHeader($name, new \DateTimeImmutable($this->str($value)));
                break;
            case 'id':
                $headers->addIdHeader($name, array_map([$this, 'str'], $this->arr($value)));
                break;
            case 'param':
                $params = array_map([$this, 'str'], $this->arr($h['p'] ?? []));
                $headers->addParameterizedHeader($name, $this->str($value), $params);
                break;
            case 'text':
                $headers->addTextHeader($name, $this->str($value));
                break;
            default:
                throw new \RuntimeException('The stored message has an unknown header type.');
        }
    }

    /**
     * @return array{a: string, n: string}
     */
    private function encodeAddress(Address $address): array
    {
        return ['a' => $address->getAddress(), 'n' => $address->getName()];
    }

    private function decodeAddress(mixed $a): Address
    {
        $a = $this->arr($a);
        return new Address($this->str($a['a'] ?? null), $this->str($a['n'] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function encodePart(AbstractPart $part, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('The message nests too deep.');
        }
        if ($part instanceof AbstractMultipartPart) {
            $kind = array_search($part::class, self::MULTIPART, true);
            if ($kind === false) {
                throw new \RuntimeException('Unsupported multipart type ' . $part::class);
            }
            $parts = [];
            foreach ($part->getParts() as $child) {
                $parts[] = $this->encodePart($child, $depth + 1);
            }
            return ['t' => 'multi', 'k' => $kind, 'parts' => $parts];
        }
        if ($part instanceof DataPart) {
            return [
                't' => 'data',
                'body' => base64_encode($part->getBody()),
                'file' => $part->getFilename(),
                'type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'disp' => $part->getDisposition(),
                'cid' => $part->hasContentId() ? $part->getContentId() : null,
            ];
        }
        if ($part instanceof TextPart) {
            return [
                't' => 'text',
                'body' => $part->getBody(),
                'sub' => $part->getMediaSubtype(),
                'cs' => $part->getPreparedHeaders()->getHeaderParameter('Content-Type', 'charset'),
                'disp' => $part->getDisposition(),
                'name' => $part->getName(),
            ];
        }
        throw new \RuntimeException('Unsupported message part ' . $part::class);
    }

    /**
     * @param array<string, mixed> $p
     */
    private function decodePart(array $p, int $depth): AbstractPart
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \RuntimeException('The stored message nests too deep.');
        }
        switch ($p['t'] ?? null) {
            case 'multi':
                $class = self::MULTIPART[$this->str($p['k'] ?? null)] ?? null;
                if ($class === null) {
                    throw new \RuntimeException('The stored message has an unknown multipart type.');
                }
                $children = array_map(fn ($c) => $this->decodePart($this->arr($c), $depth + 1), $this->arr($p['parts'] ?? []));
                return new $class(...$children);
            case 'data':
                $bytes = base64_decode($this->str($p['body'] ?? null), true);
                $type = $this->str($p['type'] ?? null);
                if ($bytes === false || substr_count($type, '/') !== 1) {
                    throw new \RuntimeException('The stored attachment is not valid.');
                }
                $data = new DataPart($bytes, isset($p['file']) ? $this->str($p['file']) : null, $type);
                if (isset($p['disp'])) {
                    $data->setDisposition($this->str($p['disp']));
                }
                if (isset($p['cid'])) {
                    $data->setContentId($this->str($p['cid']));
                }
                return $data;
            case 'text':
                $text = new TextPart(
                    $this->str($p['body'] ?? null),
                    isset($p['cs']) ? $this->str($p['cs']) : 'utf-8',
                    $this->str($p['sub'] ?? 'plain')
                );
                if (isset($p['disp'])) {
                    $text->setDisposition($this->str($p['disp']));
                }
                if (isset($p['name'])) {
                    $text->setName($this->str($p['name']));
                }
                return $text;
        }
        throw new \RuntimeException('The stored message has an unknown part type.');
    }

    /**
     * @return array<mixed>
     */
    private function arr(mixed $v): array
    {
        if (!is_array($v)) {
            throw new \RuntimeException('The stored message is malformed.');
        }
        return $v;
    }

    private function str(mixed $v): string
    {
        if (!is_string($v)) {
            throw new \RuntimeException('The stored message is malformed.');
        }
        return $v;
    }
}
