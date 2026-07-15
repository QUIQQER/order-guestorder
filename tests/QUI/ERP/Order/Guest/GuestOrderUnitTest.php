<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\User;

class GuestOrderUnitTest extends TestCase
{
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
}
