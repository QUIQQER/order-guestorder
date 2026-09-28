<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Controls\OrderProcess\Finish;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\Order;
use QUI\ERP\Order\OrderProcess;
use QUI\ERP\Order\Utils\OrderProcessSteps;

class GuestSessionTest extends TestCase
{
    private mixed $originalConfigSection;

    /** @var array<string, mixed> */
    private array $originalSessionValues = [];

    protected function setUp(): void
    {
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $this->originalConfigSection = $Config->getSection('guestorder');
        $Config->setValue('guestorder', 'type', 'noRegistration');
        $Config->save();

        $Session = QUI::getSession();
        self::assertNotNull($Session);

        foreach ([GuestOrder::FLAG, 'uid', 'auth-primary', 'auth-secondary'] as $key) {
            $this->originalSessionValues[$key] = $Session->get($key);
            $Session->remove($key);
        }

        $Session->set(GuestOrder::FLAG, 1);
    }

    protected function tearDown(): void
    {
        $Session = QUI::getSession();

        foreach ($this->originalSessionValues as $key => $value) {
            if ($value === false) {
                $Session?->remove($key);
            } else {
                $Session?->set($key, $value);
            }
        }

        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        $Config?->setSection('guestorder', is_array($this->originalConfigSection) ? $this->originalConfigSection : []);
        $Config?->save();
    }

    public function testAuthenticatedSessionIsNotReplacedEvenBeforeSecondaryAuthentication(): void
    {
        QUI::getSession()?->set('uid', QUI::getUsers()->getSystemUser()->getUUID());
        QUI::getSession()?->set('auth-primary', 1);

        self::assertTrue(QUI::isFrontend());
        self::assertNull(EventHandler::onUserGetBySession());
    }

    /** @return array<string, array{bool, bool}> */
    public static function guestSessions(): array
    {
        return [
            'no authentication' => [false, false],
            'uid without authentication' => [true, false],
            'authentication without uid' => [false, true]
        ];
    }

    #[DataProvider('guestSessions')]
    public function testUnauthenticatedGuestSessionStillResolves(bool $hasUid, bool $hasPrimaryAuth): void
    {
        if ($hasUid) {
            QUI::getSession()?->set('uid', QUI::getUsers()->getSystemUser()->getUUID());
        }

        if ($hasPrimaryAuth) {
            QUI::getSession()?->set('auth-primary', 1);
        }

        self::assertInstanceOf(GuestOrderUser::class, EventHandler::onUserGetBySession());
    }

    public function testSessionWithoutGuestFlagIsNotReplaced(): void
    {
        QUI::getSession()?->remove(GuestOrder::FLAG);
        self::assertNull(EventHandler::onUserGetBySession());
    }

    /** @return array<string, array{string, string}> */
    public static function authenticationEvents(): array
    {
        return [
            'login' => ['onUserLogin', 'noRegistration'],
            'logout' => ['onUserLogout', 'noRegistration'],
            'login with guest orders disabled' => ['onUserLogin', 'no'],
            'logout with guest orders disabled' => ['onUserLogout', 'no']
        ];
    }

    #[DataProvider('authenticationEvents')]
    public function testAuthenticationEventRemovesFlag(string $event, string $guestOrderType): void
    {
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        $Config?->setValue('guestorder', 'type', $guestOrderType);
        $Config?->save();

        $Xml = simplexml_load_file(dirname(__DIR__, 5) . '/events.xml');
        self::assertNotFalse($Xml);
        $events = $Xml->xpath('./events/event[@on="' . $event . '"]');
        self::assertIsArray($events);
        self::assertCount(1, $events);
        $callback = (string)$events[0]['fire'];
        self::assertIsCallable($callback);
        $callback(QUI::getUsers()->getSystemUser());

        self::assertFalse(QUI::getSession()?->get(GuestOrder::FLAG));
    }

    /** @return array<string, array{bool, bool}> */
    public static function orderStates(): array
    {
        return [
            'successful order' => [true, true],
            'posted order with pending payment' => [false, true],
            'unfinished order' => [false, false]
        ];
    }

    #[DataProvider('orderStates')]
    public function testGuestSessionRemainsAvailableAfterRenderingOrder(
        bool $successful,
        bool $posted
    ): void {
        $Order = $this->createMock(Order::class);
        $Order->method('isSuccessful')->willReturn((int)$successful);
        $Order->method('isPosted')->willReturn($posted);
        $Finish = $this->getMockBuilder(Finish::class)->onlyMethods(['getBody'])->getMock();
        $Finish->setAttribute('Order', $Order);
        $Finish->method('getBody')->willReturnCallback(static function (): string {
            self::assertSame(1, QUI::getSession()?->get(GuestOrder::FLAG));
            return 'Order confirmation';
        });

        EventHandler::onQuiqqerOrderProcessStepsEnd(
            $this->createMock(OrderProcess::class),
            $Order,
            new OrderProcessSteps([$Finish])
        );

        self::assertSame(1, QUI::getSession()?->get(GuestOrder::FLAG));
        self::assertStringContainsString('Order confirmation', $Finish->create());
        self::assertSame(1, QUI::getSession()?->get(GuestOrder::FLAG));
        self::assertInstanceOf(GuestOrderUser::class, EventHandler::onUserGetBySession());
    }
}
