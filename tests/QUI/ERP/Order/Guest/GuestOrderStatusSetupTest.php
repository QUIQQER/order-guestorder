<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\ProcessingStatus\Factory;
use QUI\ERP\Order\ProcessingStatus\Handler;
use QUI\Utils\Singleton;
use ReflectionProperty;

class GuestOrderStatusSetupTest extends TestCase
{
    private array $originalInstances;

    protected function setUp(): void
    {
        $this->originalInstances = (new ReflectionProperty(Singleton::class, 'instances'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Singleton::class, 'instances'))->setValue(null, $this->originalInstances);
    }

    public function testSetupCreatesStatusOnceAndPreservesLaterSelection(): void
    {
        $Config = $this->getMockBuilder(QUI\Config::class)->onlyMethods(['save'])->getMock();
        $Config->setValue('guestorder', 'order_status', '');
        $Config->expects(self::once())->method('save');
        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getName')->willReturn('quiqqer/order-guestorder');
        $Package->method('getConfig')->willReturn($Config);
        $Factory = $this->createMock(Factory::class);
        $Factory->expects(self::once())->method('getNextId')->willReturn(42);
        $Factory->expects(self::once())->method('createProcessingStatus')->with(
            42,
            '#ffc107',
            self::callback(static fn(array $titles): bool => array_keys($titles) === QUI::availableLanguages())
        );
        $Handler = $this->createMock(Handler::class);
        $Handler->expects(self::once())->method('refreshList')->willReturn([]);
        $Handler->expects(self::once())->method('setProcessingStatusNotification')->with(42, false);
        $this->setStatusServices($Factory, $Handler);

        EventHandler::onPackageSetup($Package);
        self::assertSame(42, $Config->getValue('guestorder', 'order_status'));

        EventHandler::onPackageSetup($Package);
        self::assertSame(42, $Config->getValue('guestorder', 'order_status'));

        $Config->setValue('guestorder', 'order_status', 17);
        EventHandler::onPackageSetup($Package);
        self::assertSame(17, $Config->getValue('guestorder', 'order_status'));
    }

    public function testSetupDoesNotReplaceConfiguredStatusEvenIfDeleted(): void
    {
        $Config = $this->getMockBuilder(QUI\Config::class)->onlyMethods(['save'])->getMock();
        $Config->setValue('guestorder', 'order_status', 987654);
        $Config->expects(self::never())->method('save');
        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getName')->willReturn('quiqqer/order-guestorder');
        $Package->method('getConfig')->willReturn($Config);
        $Factory = $this->createMock(Factory::class);
        $Factory->expects(self::never())->method('createProcessingStatus');
        $this->setStatusServices($Factory, $this->createMock(Handler::class));

        EventHandler::onPackageSetup($Package);

        self::assertSame(987654, $Config->getValue('guestorder', 'order_status'));
    }

    public function testSetupIgnoresOtherPackages(): void
    {
        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getName')->willReturn('quiqqer/order');
        $Package->expects(self::never())->method('getConfig');

        EventHandler::onPackageSetup($Package);
    }

    public function testFailedCreationDoesNotSaveStatusSelection(): void
    {
        $Config = $this->getMockBuilder(QUI\Config::class)->onlyMethods(['save'])->getMock();
        $Config->expects(self::never())->method('save');
        $Package = $this->createMock(QUI\Package\Package::class);
        $Package->method('getName')->willReturn('quiqqer/order-guestorder');
        $Package->method('getConfig')->willReturn($Config);
        $Factory = $this->createMock(Factory::class);
        $Factory->method('getNextId')->willReturn(42);
        $Factory->method('createProcessingStatus')->willThrowException(new QUI\Exception('Creation failed'));
        $this->setStatusServices($Factory, $this->createMock(Handler::class));

        $this->expectException(QUI\Exception::class);
        EventHandler::onPackageSetup($Package);
    }

    private function setStatusServices(Factory $Factory, Handler $Handler): void
    {
        $instances = $this->originalInstances;
        $instances[Factory::class] = $Factory;
        $instances[Handler::class] = $Handler;
        (new ReflectionProperty(Singleton::class, 'instances'))->setValue(null, $instances);
    }
}
