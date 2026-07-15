<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Order\Guest\Controls\GuestOrderButton;
use QUI\ERP\Order\Guest\Controls\MissingAddressData;

class ControlsUnitTest extends TestCase
{
    public function testGuestOrderButtonConfiguresFrontendControl(): void
    {
        $Control = new GuestOrderButton();

        self::assertSame(
            'package/quiqqer/order-guestorder/bin/frontend/controls/GuestOrderButton',
            $Control->getAttribute('data-qui')
        );
    }

    public function testGuestOrderButtonRendersGuestOrderForm(): void
    {
        $body = (new GuestOrderButton())->getBody();

        self::assertStringContainsString('quiqqer-order-ordering-nobody-guestOrder', $body);
        self::assertStringContainsString('name="guest-order-enter-email"', $body);
    }

    public function testMissingAddressDataConfiguresFrontendControl(): void
    {
        $Control = new MissingAddressData();

        self::assertSame(
            'package/quiqqer/order-guestorder/bin/frontend/controls/MissingAddressData',
            $Control->getAttribute('qui-class')
        );
        self::assertFalse($Control->getAttribute('Order'));
    }

    public function testMissingAddressDataReturnsEmptyBodyWithoutOrder(): void
    {
        self::assertSame('', (new MissingAddressData())->getBody());
    }
}
