<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\Handler;
use QUI\ERP\Order\Order;
use QUI\ERP\Order\OrderInProcess;
use QUI\Utils\Doctrine;
use ReflectionProperty;
use Throwable;

class EventHandlerOrderLifecycleDatabaseTest extends TestCase
{
    /**
     * @var list<int>
     */
    private array $processIds = [];
    private mixed $originalConfigSection;
    private mixed $originalSessionUser;
    private mixed $originalGuestFlag;
    private mixed $originalGuestOrderId;
    private string $guestOrderId;

    protected function setUp(): void
    {
        parent::setUp();

        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $this->originalConfigSection = $Config->getSection('guestorder');
        $Config->setValue('guestorder', 'type', 'noRegistration');
        $Config->save();

        $Session = QUI::getSession();
        self::assertNotNull($Session);
        $this->originalGuestFlag = $Session->get(GuestOrder::FLAG);
        $this->originalGuestOrderId = $Session->get('guest-order-id');
        $this->guestOrderId = 'phpunit-order-lifecycle-' . bin2hex(random_bytes(8));
        $Session->set(GuestOrder::FLAG, 1);
        $Session->set('guest-order-id', $this->guestOrderId);

        $Users = QUI::getUsers();
        $SessionUser = new ReflectionProperty($Users, 'Session');
        $this->originalSessionUser = $SessionUser->getValue($Users);
        $SessionUser->setValue($Users, new GuestOrderUser());
    }

    protected function tearDown(): void
    {
        foreach ($this->processIds as $processId) {
            try {
                QUI::getDataBaseConnection()->delete(
                    $this->getTable(),
                    [Doctrine::quoteIdentifier('id') => $processId]
                );
            } catch (Throwable) {
            }
        }

        (new ReflectionProperty(QUI::getUsers(), 'Session'))->setValue(
            QUI::getUsers(),
            $this->originalSessionUser
        );

        $Session = QUI::getSession();

        if ($Session) {
            $this->restoreSessionValue(GuestOrder::FLAG, $this->originalGuestFlag);
            $this->restoreSessionValue('guest-order-id', $this->originalGuestOrderId);
        }

        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();

        if ($Config) {
            $Config->setSection(
                'guestorder',
                is_array($this->originalConfigSection) ? $this->originalConfigSection : []
            );
            $Config->save();
        }

        parent::tearDown();
    }

    public function testCreatedOrderIsLinkedToGuestSession(): void
    {
        $orderProcessHash = 'phpunit-created-order-' . bin2hex(random_bytes(8));
        $this->insertProcess($this->guestOrderId, $orderProcessHash);
        $Order = $this->createMock(Order::class);
        $Order->method('getAttribute')->with('order_process_id')->willReturn($orderProcessHash);
        $Order->expects(self::once())->method('setData')->with('guest-order-hash', $this->guestOrderId);
        $Order->expects(self::once())->method('update')->with(QUI::getUsers()->getSystemUser());
        $Order->method('getUUID')->willReturn('phpunit-created-order-uuid');

        EventHandler::onQuiqqerOrderCreated($Order);

        self::assertSame('phpunit-created-order-uuid', QUI::getSession()?->get('guest-order-id'));
    }

    public function testClearedOrderProcessIsReassignedToCurrentGuest(): void
    {
        $currentProcessId = $this->insertProcess(null);
        $staleProcessId = $this->insertProcess($this->guestOrderId);
        $otherProcessId = $this->insertProcess('phpunit-other-guest-' . bin2hex(random_bytes(8)));
        $Order = $this->createMock(OrderInProcess::class);
        $Order->method('getId')->willReturn($currentProcessId);

        EventHandler::onQuiqqerOrderClear($Order);

        self::assertSame($this->guestOrderId, $this->getGuestOrderId($currentProcessId));
        self::assertFalse($this->processExists($staleProcessId));
        self::assertTrue($this->processExists($otherProcessId));
    }

    private function insertProcess(?string $guestOrderId, ?string $hash = null): int
    {
        $Connection = QUI::getDataBaseConnection();
        $Connection->insert(
            $this->getTable(),
            [
                Doctrine::quoteIdentifier('guestOrder') => $guestOrderId,
                Doctrine::quoteIdentifier('status') => 0,
                Doctrine::quoteIdentifier('paid_status') => 0,
                Doctrine::quoteIdentifier('successful') => 0,
                Doctrine::quoteIdentifier('hash') => $hash ?? 'phpunit-clear-' . bin2hex(random_bytes(8)),
                Doctrine::quoteIdentifier('c_user') => 'PHPUnit EventHandler lifecycle'
            ]
        );

        $processId = (int)$Connection->lastInsertId();
        $this->processIds[] = $processId;

        return $processId;
    }

    private function getGuestOrderId(int $processId): string | false
    {
        $guestOrderId = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('guestOrder'))
            ->from($this->getTable())
            ->where(Doctrine::quoteIdentifier('id') . ' = :processId')
            ->setParameter('processId', $processId)
            ->executeQuery()
            ->fetchOne();

        return is_string($guestOrderId) ? $guestOrderId : false;
    }

    private function processExists(int $processId): bool
    {
        return QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('id'))
            ->from($this->getTable())
            ->where(Doctrine::quoteIdentifier('id') . ' = :processId')
            ->setParameter('processId', $processId)
            ->executeQuery()
            ->fetchOne() !== false;
    }

    private function restoreSessionValue(string $key, mixed $value): void
    {
        $Session = QUI::getSession();

        if (!$Session) {
            return;
        }

        if ($value === false) {
            $Session->remove($key);
            return;
        }

        $Session->set($key, $value);
    }

    private function getTable(): string
    {
        return Doctrine::quoteIdentifier(Handler::getInstance()->tableOrderProcess());
    }
}
