<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\Handler;
use QUI\Utils\Doctrine;
use Throwable;

class GuestOrderUserDatabaseTest extends TestCase
{
    private ?int $processId = null;

    protected function tearDown(): void
    {
        if ($this->processId !== null) {
            try {
                QUI::getDataBaseConnection()->delete(
                    Doctrine::quoteIdentifier(Handler::getInstance()->tableOrderProcess()),
                    [Doctrine::quoteIdentifier('id') => $this->processId]
                );
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testLoadsGuestOrderDataByGuestOrderId(): void
    {
        $guestOrderId = 'phpunit-guest-order-user-' . bin2hex(random_bytes(8));
        $addressInvoice = json_encode(
            ['firstname' => 'PHPUnit GuestOrderUser'],
            JSON_THROW_ON_ERROR
        );
        $Connection = QUI::getDataBaseConnection();

        $Connection->insert(
            Doctrine::quoteIdentifier(Handler::getInstance()->tableOrderProcess()),
            [
                Doctrine::quoteIdentifier('guestOrder') => $guestOrderId,
                Doctrine::quoteIdentifier('addressInvoice') => $addressInvoice,
                Doctrine::quoteIdentifier('status') => 0,
                Doctrine::quoteIdentifier('paid_status') => 0,
                Doctrine::quoteIdentifier('successful') => 0,
                Doctrine::quoteIdentifier('hash') => $guestOrderId,
                Doctrine::quoteIdentifier('c_user') => 'PHPUnit GuestOrderUser'
            ]
        );

        $this->processId = (int)$Connection->lastInsertId();
        $User = $this->createUser($guestOrderId);

        self::assertSame($addressInvoice, $User->loadGuestOrderData()['addressInvoice'] ?? null);
    }

    public function testReturnsFalseForUnknownGuestOrderId(): void
    {
        $User = $this->createUser('phpunit-unknown-guest-order-' . bin2hex(random_bytes(8)));

        self::assertFalse($User->loadGuestOrderData());
    }

    private function createUser(string $guestOrderId): GuestOrderUser
    {
        return new class ($guestOrderId) extends GuestOrderUser {
            public function __construct(private readonly string $guestOrderId)
            {
                parent::__construct();
            }

            protected function getSessionInstance(): QUI\Session | QUI\System\Console\Session | null
            {
                return null;
            }

            /**
             * @return array<string, mixed>|false
             */
            public function loadGuestOrderData(): false | array
            {
                return $this->getGuestOrderData();
            }

            public function getGuestOrderId(): string
            {
                return $this->guestOrderId;
            }
        };
    }
}
