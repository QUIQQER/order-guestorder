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
        $Session->set(GuestOrder::FLAG, 1);
        $Session->set(GuestOrder::EMAIL, 'phpunit-order-process@example.com');
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
        $Address->addMail('phpunit-order-process@example.com');
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
}
