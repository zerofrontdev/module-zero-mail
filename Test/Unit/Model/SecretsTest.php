<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Mail\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Zero\Mail\Model\Secrets;

/**
 * The log keeps no password reset or confirmation link, no abandoned cart,
 * stock alert or quote request link, and no gift card code.
 */
class SecretsTest extends TestCase
{
    public function testOneTimeLinksLoseTheirSecret(): void
    {
        $body = '<a href="https://shop.test/customer/account/createPassword/?token=AbC123xyz&amp;id=5">Reset</a>'
            . ' <a href="https://shop.test/customer/account/confirm/?id=5&key=K9y8">Confirm</a>'
            . ' https://shop.test/abandoned/cart/restore?token=t0k3n plain text'
            . ' <a href="https://shop.test/quotes/view?token=q1&utm_source=mail">Quote</a>';

        $redacted = Secrets::redact($body);

        $this->assertStringNotContainsString('AbC123xyz', $redacted);
        $this->assertStringNotContainsString('K9y8', $redacted);
        $this->assertStringNotContainsString('t0k3n', $redacted);
        $this->assertStringNotContainsString('token=q1', $redacted);
        $this->assertStringContainsString('&amp;id=5', $redacted);
        $this->assertStringContainsString('utm_source=mail', $redacted);
        $this->assertStringContainsString('?id=5&key=' . Secrets::MARK, $redacted);
        $this->assertTrue(Secrets::wasRedacted($redacted));
    }

    public function testGiftCardCodesAreGone(): void
    {
        $redacted = Secrets::redact('<p>Your code</p><p>ABCD-EFGH-JKMN-PQRS</p> order #100000123');

        $this->assertStringNotContainsString('ABCD-EFGH-JKMN-PQRS', $redacted);
        $this->assertStringContainsString('order #100000123', $redacted);
    }

    public function testAnOrdinaryEmailIsUntouched(): void
    {
        $body = '<p>Thank you for your order #100000123.</p><a href="https://shop.test/sales/order/view/order_id/12/">Order</a>';

        $this->assertSame($body, Secrets::redact($body));
        $this->assertFalse(Secrets::wasRedacted($body));
    }

    public function testKeyAndTokenVariantsLoseTheirSecret(): void
    {
        $body = '<a href="https://shop.test/sales/guest/cancel/?order_id=MTA=&confirmation_key=AbCd1234">Cancel</a>'
            . ' <a href="https://shop.test/sales/guest/cancel/?order_id=MTA=&amp;confirmation_key=EfGh5678">Cancel</a>'
            . ' https://shop.test/licenses/claim/confirm/?claim_token=x1claim'
            . ' https://shop.test/x?accessToken=y2access&amp;api_key=z3apikey'
            . ' https://shop.test/x?apiKey=w4camel&api-key=v5hyphen&Confirmation_KEY=u6upper'
            . ' https://shop.test/customer/account/createPassword/?rp_token=t7rp&resetPasswordToken=s8reset'
            . ' https://shop.test/sociallogin/link/confirm/?token=r9social&key=q0key&code=p1code';

        $redacted = Secrets::redact($body);

        foreach (['AbCd1234', 'EfGh5678', 'x1claim', 'y2access', 'z3apikey', 'w4camel', 'v5hyphen', 'u6upper', 't7rp', 's8reset', 'r9social', 'q0key', 'p1code'] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted, $secret);
        }
        $this->assertStringContainsString('?order_id=MTA=&confirmation_key=' . Secrets::MARK . '">', $redacted);
        $this->assertStringContainsString('?order_id=MTA=&amp;confirmation_key=' . Secrets::MARK, $redacted, 'the &amp; form too');
        $this->assertStringContainsString('&amp;api_key=' . Secrets::MARK, $redacted);
        $this->assertStringContainsString('?apiKey=' . Secrets::MARK . '&api-key=' . Secrets::MARK, $redacted);
        $this->assertTrue(Secrets::wasRedacted($redacted));
    }

    public function testLookalikeParamsStay(): void
    {
        $body = 'https://shop.test/search?keyword=shoes&hotkey=1&monkey=2&utm_source=m&id=5&token_type=bearer&coupon_code=SAVE10&HOTKEY=3';

        $redacted = Secrets::redact($body);

        $this->assertSame($body, $redacted);
        $this->assertFalse(Secrets::wasRedacted($redacted));
    }
}
