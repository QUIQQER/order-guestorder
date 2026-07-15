<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\User;
use QUI\Rewrite;
use QUI\Smarty\Collector;

class EventHandlerFlowTest extends TestCase
{
    public function testResolvesGuestUserByIdAndUuid(): void
    {
        $this->withGuestOrderType('noRegistration', static function (): void {
            $Guest = new GuestOrderUser();

            self::assertInstanceOf(GuestOrderUser::class, EventHandler::onUserGet($Guest->getId()));
            self::assertInstanceOf(GuestOrderUser::class, EventHandler::onUserGet($Guest->getUUID()));
            self::assertNull(EventHandler::onUserGet('phpunit-unknown-guest-user'));
        });
    }

    public function testDoesNotResolveGuestUserWhenGuestOrdersAreDisabled(): void
    {
        $this->withGuestOrderType('no', static function (): void {
            self::assertNull(EventHandler::onUserGet(6));
        });
    }

    public function testExtendsGuestOrderMailWithAccountCreationLink(): void
    {
        $this->withGuestOrderType('noRegistration', function (): void {
            $Collector = new Collector();
            $Order = $this->createGuestOrder();

            EventHandler::extendMail($Collector, $Order, []);

            self::assertStringContainsString('t=account', $Collector->getContent());
            self::assertStringContainsString('phpunit-order-uuid', $Collector->getContent());
        });
    }

    public function testDoesNotExtendMailWhenGuestOrdersAreDisabled(): void
    {
        $this->withGuestOrderType('no', function (): void {
            $Collector = new Collector();

            EventHandler::extendMail($Collector, $this->createGuestOrder(), []);

            self::assertSame('', $Collector->getContent());
        });
    }

    public function testRequestWithoutGuestOrderParametersDoesNothing(): void
    {
        $originalRequest = $_REQUEST;

        try {
            $_REQUEST = [];
            EventHandler::onRequest($this->createMock(Rewrite::class), '/phpunit');
            self::assertSame([], $_REQUEST);
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    public function testCreatedEventIgnoresNonPersistedOrderType(): void
    {
        EventHandler::onQuiqqerOrderCreated($this->createMock(AbstractOrder::class));

        self::assertTrue(true);
    }

    private function createGuestOrder(): AbstractOrder
    {
        $Customer = $this->createMock(User::class);
        $Customer->method('getUUID')->willReturn('');
        $Customer->method('getAttribute')->with('email')->willReturn('phpunit-guest@example.com');
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getCustomer')->willReturn($Customer);
        $Order->method('getUUID')->willReturn('phpunit-order-uuid');

        return $Order;
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
