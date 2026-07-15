<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\Handler;
use QUI\ERP\Order\OrderInProcess;
use QUI\ERP\Order\OrderProcess;
use QUI\Interfaces\Projects\Site as SiteInterface;
use QUI\Rewrite;
use QUI\Utils\Doctrine;
use ReflectionProperty;
use Throwable;

class OrderProcessFlowDatabaseTest extends TestCase
{
    private mixed $originalSessionUser;
    private mixed $originalConfigSection;
    private array $originalSessionValues = [];
    private array $originalRequest = [];
    private string $guestOrderId;
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalRequest = $_REQUEST;
        $_REQUEST = [];
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $this->originalConfigSection = $Config->getSection('guestorder');
        $Config->setValue('guestorder', 'type', 'noRegistration');
        $Config->save();

        $Session = QUI::getSession();
        self::assertNotNull($Session);

        $sessionKeys = [
            GuestOrder::FLAG,
            GuestOrder::EMAIL,
            GuestOrder::CUSTOMER_UUID,
            GuestOrder::CUSTOMER_ID,
            'guest-order-id'
        ];

        foreach ($sessionKeys as $key) {
            $this->originalSessionValues[$key] = $Session->get($key);
        }

        $this->guestOrderId = 'phpunit-order-process-' . bin2hex(random_bytes(8));
        $this->email = 'phpunit-order-process-' . bin2hex(random_bytes(8)) . '@example.com';
        $Session->set(GuestOrder::FLAG, 1);
        $Session->set(GuestOrder::EMAIL, $this->email);
        $Session->set(GuestOrder::CUSTOMER_UUID, QUI\Utils\Uuid::get());
        $Session->remove(GuestOrder::CUSTOMER_ID);
        $Session->set('guest-order-id', $this->guestOrderId);

        $Users = QUI::getUsers();
        $SessionUser = new ReflectionProperty($Users, 'Session');
        $this->originalSessionUser = $SessionUser->getValue($Users);
        $SessionUser->setValue($Users, new GuestOrderUser());
    }

    protected function tearDown(): void
    {
        try {
            QUI::getDataBaseConnection()->delete(
                Doctrine::quoteIdentifier(Handler::getInstance()->tableOrderProcess()),
                [Doctrine::quoteIdentifier('guestOrder') => $this->guestOrderId]
            );
        } catch (Throwable) {
        }

        $Users = QUI::getUsers();
        (new ReflectionProperty($Users, 'Session'))->setValue($Users, $this->originalSessionUser);

        $Session = QUI::getSession();

        if ($Session) {
            foreach ($this->originalSessionValues as $key => $value) {
                if ($value === false) {
                    $Session->remove($key);
                } else {
                    $Session->set($key, $value);
                }
            }
        }

        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();

        if ($Config) {
            $Config->setSection(
                'guestorder',
                is_array($this->originalConfigSection) ? $this->originalConfigSection : []
            );
            $Config->save();
        }

        $_REQUEST = $this->originalRequest;
        parent::tearDown();
    }

    public function testCreatesAndReusesGuestOrderProcess(): void
    {
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->with('step')->willReturn(null);

        $FirstOrder = EventHandler::onOrderProcessGetOrder($OrderProcess);
        $SecondOrder = EventHandler::onOrderProcessGetOrder($OrderProcess);

        self::assertInstanceOf(OrderInProcess::class, $FirstOrder);
        self::assertInstanceOf(OrderInProcess::class, $SecondOrder);
        self::assertSame($FirstOrder->getId(), $SecondOrder->getId());

        $data = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(
                Doctrine::quoteIdentifier('guestOrder'),
                Doctrine::quoteIdentifier('customerId'),
                Doctrine::quoteIdentifier('c_user')
            )
            ->from(Doctrine::quoteIdentifier(Handler::getInstance()->tableOrderProcess()))
            ->where(Doctrine::quoteIdentifier('id') . ' = :orderId')
            ->setParameter('orderId', $FirstOrder->getId())
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($data);
        self::assertSame($this->guestOrderId, $data['guestOrder']);
        self::assertSame((string)$FirstOrder->getCustomer()->getUUID(), (string)$data['customerId']);
        self::assertSame((string)$data['customerId'], (string)$data['c_user']);
    }

    public function testResumesGuestOrderProcessByOrderHashDuringProcessing(): void
    {
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->with('step')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $Address = new QUI\ERP\Address([
            'firstname' => 'PHPUnit',
            'lastname' => 'Order Process',
            'country' => 'DE'
        ], $Order->getCustomer());
        $Address->addMail($this->email);
        $Order->setInvoiceAddress($Address);
        $Order->save(QUI::getUsers()->getSystemUser());

        $_REQUEST['step'] = 'Processing';
        $_REQUEST['orderHash'] = $Order->getUUID();
        $Processing = $this->createMock(OrderProcess::class);
        $Processing->method('getAttribute')->with('step')->willReturn('Processing');

        $ResumedOrder = EventHandler::onOrderProcessGetOrder($Processing);

        self::assertInstanceOf(OrderInProcess::class, $ResumedOrder);
        self::assertSame($Order->getId(), $ResumedOrder->getId());
        self::assertSame(
            (string)$Order->getCustomer()->getUUID(),
            (string)QUI::getSession()?->get(GuestOrder::CUSTOMER_UUID)
        );
        self::assertSame(
            (string)$Order->getCustomer()->getId(),
            (string)QUI::getSession()?->get(GuestOrder::CUSTOMER_ID)
        );
    }

    public function testInvoiceRequestValidatesCompleteGuestOrderWithoutCreatingInvoice(): void
    {
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->with('step')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $Address = new QUI\ERP\Address([
            'salutation' => 'mr',
            'firstname' => 'PHPUnit',
            'lastname' => 'Invoice Request',
            'street' => 'Teststraße',
            'street_no' => '42',
            'zip' => '12345',
            'city' => 'Teststadt',
            'country' => 'DE'
        ], $Order->getCustomer());
        $Address->addMail($this->email);
        $Order->setInvoiceAddress($Address);
        $Order->save(QUI::getUsers()->getSystemUser());
        self::assertSame(
            [],
            QUI\ERP\Accounting\Invoice\Utils\Invoice::getMissingAddressData($Address->getAttributes())
        );
        $_REQUEST = [
            'guestorder' => '1',
            't' => 'invoice',
            'o' => $Order->getUUID(),
            'u' => $this->email
        ];

        $this->withInvoicePackageInstalled(function (): void {
            EventHandler::onRequest($this->createMock(Rewrite::class), '/phpunit-invoice');
        });

        self::assertSame($Order->getId(), Handler::getInstance()->getOrderByHash($Order->getUUID())->getId());
    }

    public function testInvoiceRequestRendersMissingAddressForm(): void
    {
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->with('step')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $Address = new QUI\ERP\Address([
            'firstname' => 'PHPUnit',
            'country' => 'DE'
        ], $Order->getCustomer());
        $Address->addMail($this->email);
        $Order->setInvoiceAddress($Address);
        $Order->save(QUI::getUsers()->getSystemUser());
        self::assertNotSame(
            [],
            QUI\ERP\Accounting\Invoice\Utils\Invoice::getMissingAddressData($Address->getAttributes())
        );
        $_REQUEST = [
            'guestorder' => '1',
            't' => 'invoice',
            'o' => $Order->getUUID(),
            'u' => $this->email
        ];
        $originalRewrite = QUI::$Rewrite ?? QUI::getRewrite();
        $Site = $this->createMock(SiteInterface::class);
        $Site->expects(self::exactly(4))->method('setAttribute');
        $Site->method('getAttribute')->willReturn('');
        $Rewrite = $this->createMock(Rewrite::class);
        $Rewrite->method('getProject')->willReturn($originalRewrite->getProject());
        $Rewrite->method('getSite')->willReturn($Site);

        try {
            QUI::$Rewrite = $Rewrite;
            $this->withInvoicePackageInstalled(static function () use ($Rewrite): void {
                EventHandler::onRequest($Rewrite, '/phpunit-invoice-address');
            });
        } finally {
            QUI::$Rewrite = $originalRewrite;
        }
    }

    private function withInvoicePackageInstalled(callable $callback): void
    {
        $PackageManager = QUI::getPackageManager();
        $Installed = new ReflectionProperty($PackageManager, 'installed');
        $originalInstalled = $Installed->getValue($PackageManager);
        $installed = $originalInstalled;
        $installed['quiqqer/invoice'] = true;

        try {
            $Installed->setValue($PackageManager, $installed);
            $callback();
        } finally {
            $Installed->setValue($PackageManager, $originalInstalled);
        }
    }
}
