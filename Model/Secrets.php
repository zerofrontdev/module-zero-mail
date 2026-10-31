<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mail\Model;

/**
 * What the Email Log must not keep: one-time links (password reset and
 * account confirmation, abandoned cart, stock alert, quote request, social
 * sign-in link, account deletion, license claim: the value of a query
 * parameter named `*token` (token, rp_token, resetPasswordToken, accessToken,
 * claim_token), `key`, `*_key` or `*-key` (confirmation_key, api_key,
 * api-key), camelCase `*Key` (apiKey; case-sensitive, so hotkey stays) or
 * `code`) and
 * gift card codes. An admin who reads the log then can't use them. A redacted
 * email can't be resent either: the customer asks for a new one instead.
 * Lookalikes (keyword, hotkey, monkey) are kept. Path-style tokens
 * (`/token/X/`) are not covered: links that carry a secret use a query string.
 */
class Secrets
{
    public const MARK = '[zero-mail:redacted]';

    private const QUERY_PARAMS = '[a-z0-9_]*token|(?:[a-z0-9]+[_-])*key|(?-i:[a-z0-9]+Key)|code';
    private const GIFT_CARD_CODE = '/\b[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}\b/';

    public static function redact(string $body): string
    {
        $body = (string) preg_replace(
            '/([?&;](?:' . self::QUERY_PARAMS . ')=)[^&"\'<>\s]+/i',
            '$1' . self::MARK,
            $body
        );

        return (string) preg_replace(self::GIFT_CARD_CODE, self::MARK, $body);
    }

    public static function wasRedacted(string $body): bool
    {
        return str_contains($body, self::MARK);
    }
}
