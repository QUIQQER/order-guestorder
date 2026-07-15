<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\User;

class GuestOrderUnitTest extends TestCase
{
    public function testGuestOrderModesAreInterpretedCorrectly(): void
    {
        $this->withGuestOrderType('no', static function (): void {
            self::assertFalse(GuestOrder::isActive());
            self::assertFalse(GuestOrder::isAnonymousOrder());
        });

        $this->withGuestOrderType('noRegistration', static function (): void {
            self::assertTrue(GuestOrder::isActive());
            self::assertFalse(GuestOrder::isAnonymousOrder());
        });

        $this->withGuestOrderType('anonymous', static function (): void {
            self::assertTrue(GuestOrder::isActive());
            self::assertTrue(GuestOrder::isAnonymousOrder());
        });
    }

    public function testGuestOrderFlagCanBeSetAndRemoved(): void
    {
        $Session = QUI::getSession();
        self::assertNotNull($Session);
        $originalValue = $Session->get(GuestOrder::FLAG);

        try {
            $this->withGuestOrderType('noRegistration', static function () use ($Session): void {
                GuestOrder::setGuestOrderFlag();
                self::assertSame(1, $Session->get(GuestOrder::FLAG));

                GuestOrder::removeGuestOrderFlag();
                self::assertFalse($Session->get(GuestOrder::FLAG));
            });
        } finally {
            if ($originalValue === false) {
                $Session->remove(GuestOrder::FLAG);
            } else {
                $Session->set(GuestOrder::FLAG, $originalValue);
            }
        }
    }

    public function testInvoiceCreationLinkContainsOrderAndCustomerData(): void
    {
        $query = $this->getLinkQuery(GuestOrder::getInvoiceCreationLink($this->createOrder()));

        self::assertSame('1', $query['guestorder']);
        self::assertSame('invoice', $query['t']);
        self::assertSame('phpunit-guest@example.com', $query['u']);
        self::assertSame('phpunit-order-uuid', $query['o']);
    }

    public function testAccountCreationLinkContainsOrderAndCustomerData(): void
    {
        $query = $this->getLinkQuery(GuestOrder::getAccountCreationLink($this->createOrder()));

        self::assertSame('1', $query['guestorder']);
        self::assertSame('account', $query['t']);
        self::assertSame('phpunit-guest@example.com', $query['u']);
        self::assertSame('phpunit-order-uuid', $query['o']);
    }

    private function createOrder(): AbstractOrder
    {
        $Customer = $this->createMock(User::class);
        $Customer->method('getAttribute')->with('email')->willReturn('phpunit-guest@example.com');
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getCustomer')->willReturn($Customer);
        $Order->method('getUUID')->willReturn('phpunit-order-uuid');

        return $Order;
    }

    /**
     * @return array<string, string>
     */
    private function getLinkQuery(string $link): array
    {
        $query = [];
        parse_str((string)parse_url($link, PHP_URL_QUERY), $query);

        return $query;
    }

    private function withGuestOrderType(string $type, callable $callback): void
    {
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $originalSection = $Config->getSection('guestorder');

        try {
            $Config->setValue('guestorder', 'type', $type);
            $Config->save();
            $callback();
        } finally {
            $Config->setSection('guestorder', is_array($originalSection) ? $originalSection : []);
            $Config->save();
        }
    }
}
