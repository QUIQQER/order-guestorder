<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\Handler;
use QUI\Utils\Doctrine;
use Throwable;

class EventHandlerDatabaseTest extends TestCase
{
    /**
     * @var list<int>
     */
    private array $processIds = [];

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

        parent::tearDown();
    }

    public function testClearingGuestOrderOnlyDeletesOtherProcessesOfSameGuest(): void
    {
        $guestOrderId = 'phpunit-cleared-guest-' . bin2hex(random_bytes(8));
        $otherGuestOrderId = 'phpunit-unrelated-guest-' . bin2hex(random_bytes(8));
        $currentProcessId = $this->insertProcess(null);
        $staleProcessId = $this->insertProcess($guestOrderId);
        $unrelatedProcessId = $this->insertProcess($otherGuestOrderId);
        $EventHandler = new class () extends EventHandler {
            public static function assignClearedOrder(int | string $orderId, string $guestOrderId): void
            {
                parent::assignClearedOrderToGuest($orderId, $guestOrderId);
            }
        };

        $EventHandler::assignClearedOrder($currentProcessId, $guestOrderId);

        self::assertSame($guestOrderId, $this->getGuestOrderId($currentProcessId));
        self::assertFalse($this->processExists($staleProcessId));
        self::assertTrue($this->processExists($unrelatedProcessId));
    }

    private function insertProcess(?string $guestOrderId): int
    {
        $hash = 'phpunit-order-clear-' . bin2hex(random_bytes(8));
        $Connection = QUI::getDataBaseConnection();

        $Connection->insert(
            $this->getTable(),
            [
                Doctrine::quoteIdentifier('guestOrder') => $guestOrderId,
                Doctrine::quoteIdentifier('status') => 0,
                Doctrine::quoteIdentifier('paid_status') => 0,
                Doctrine::quoteIdentifier('successful') => 0,
                Doctrine::quoteIdentifier('hash') => $hash,
                Doctrine::quoteIdentifier('c_user') => 'PHPUnit EventHandler'
            ]
        );

        $processId = (int)$Connection->lastInsertId();
        $this->processIds[] = $processId;

        return $processId;
    }

    private function getGuestOrderId(int $processId): string | false
    {
        $result = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('guestOrder'))
            ->from($this->getTable())
            ->where(Doctrine::quoteIdentifier('id') . ' = :processId')
            ->setParameter('processId', $processId)
            ->executeQuery()
            ->fetchOne();

        return is_string($result) ? $result : false;
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

    private function getTable(): string
    {
        return Doctrine::quoteIdentifier(Handler::getInstance()->tableOrderProcess());
    }
}
