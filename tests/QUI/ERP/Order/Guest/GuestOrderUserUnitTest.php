<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Address;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\Session;

class GuestOrderUserUnitTest extends TestCase
{
    private function createUserWithoutSession(): GuestOrderUser
    {
        return new class () extends GuestOrderUser {
            protected function getSessionInstance(): \QUI\Session | \QUI\System\Console\Session | null
            {
                return null;
            }
        };
    }

    private function createUserWithSession(Session $Session): GuestOrderUser
    {
        return new class ($Session) extends GuestOrderUser {
            public function __construct(private readonly Session $Session)
            {
                parent::__construct();
            }

            protected function getSessionInstance(): Session | \QUI\System\Console\Session | null
            {
                return $this->Session;
            }
        };
    }

    public function testGetIdFallsBackToNobodyIdWithoutSession(): void
    {
        $User = $this->createUserWithoutSession();

        $this->assertSame(6, $User->getId());
    }

    public function testGetIdUsesCustomerIdFromSession(): void
    {
        $Session = $this->createMock(Session::class);
        $Session->method('get')->willReturnMap([
            [GuestOrder::CUSTOMER_ID, 42]
        ]);
        $User = $this->createUserWithSession($Session);

        self::assertSame(42, $User->getId());
    }

    public function testGetUuidCreatesAndReusesSessionUuid(): void
    {
        $values = [];
        $Session = $this->createMock(Session::class);
        $Session->method('get')->willReturnCallback(
            static function (string $name) use (&$values): mixed {
                return $values[$name] ?? null;
            }
        );
        $Session->method('set')->willReturnCallback(
            static function (string $name, mixed $value) use (&$values): void {
                $values[$name] = $value;
            }
        );
        $User = $this->createUserWithSession($Session);

        $uuid = $User->getUUID();

        self::assertIsString($uuid);
        self::assertNotSame('', $uuid);
        self::assertSame($uuid, $User->getUUID());
        self::assertSame($uuid, $values[GuestOrder::CUSTOMER_UUID]);
    }

    public function testConstructorReadsEmailFromSession(): void
    {
        $Session = $this->createMock(Session::class);
        $Session->method('get')->willReturnMap([
            [GuestOrder::EMAIL, 'phpunit-guest@example.com']
        ]);

        $User = $this->createUserWithSession($Session);

        self::assertSame('phpunit-guest@example.com', $User->getAttribute('email'));
    }

    public function testGuestOrderIdAndUniqueIdReuseSessionValue(): void
    {
        $Session = $this->createMock(Session::class);
        $Session->method('get')->willReturnMap([
            ['guest-order-id', 'phpunit-existing-guest-order']
        ]);
        $User = $this->createUserWithSession($Session);

        self::assertSame('phpunit-existing-guest-order', $User->getGuestOrderId());
        self::assertSame('phpunit-existing-guest-order', $User->getUniqueId());
    }

    public function testGuestOrderIdCreatesAndStoresSessionValue(): void
    {
        $values = [];
        $Session = $this->createMock(Session::class);
        $Session->method('get')->willReturnCallback(
            static function (string $name) use (&$values): mixed {
                return $values[$name] ?? null;
            }
        );
        $Session->method('set')->willReturnCallback(
            static function (string $name, mixed $value) use (&$values): void {
                $values[$name] = $value;
            }
        );
        $User = $this->createUserWithSession($Session);

        $guestOrderId = $User->getGuestOrderId();

        self::assertNotSame('', $guestOrderId);
        self::assertSame($guestOrderId, $values['guest-order-id']);
        self::assertSame($guestOrderId, $User->getGuestOrderId());
    }

    public function testSetCompanyStatusUpdatesCompanyState(): void
    {
        $User = $this->createUserWithoutSession();

        $User->setCompanyStatus(true);

        self::assertTrue($User->isCompany());
    }

    public function testLogoutDestroysSession(): void
    {
        $Session = $this->createMock(Session::class);
        $Session->expects(self::once())->method('destroy');
        $User = $this->createUserWithSession($Session);

        $User->logout();
    }

    public function testGetAddressReturnsStandardAddress(): void
    {
        $Address = $this->createMock(Address::class);
        $User = new class ($Address) extends GuestOrderUser {
            public function __construct(private readonly Address $Address)
            {
                parent::__construct();
            }

            protected function getSessionInstance(): \QUI\Session | \QUI\System\Console\Session | null
            {
                return null;
            }

            public function getStandardAddress(): Address
            {
                return $this->Address;
            }
        };

        self::assertSame($Address, $User->getAddress('standard'));
    }

    public function testReportsActiveAndNotDeleted(): void
    {
        $User = $this->createUserWithoutSession();

        self::assertTrue($User->isActive());
        self::assertFalse($User->isDeleted());
    }

    public function testGetUuidFallsBackToNobodyIdWithoutSession(): void
    {
        $User = $this->createUserWithoutSession();

        $this->assertSame(6, $User->getUUID());
    }

    public function testGetGuestOrderIdReturnsGeneratedIdWithoutSession(): void
    {
        $User = $this->createUserWithoutSession();

        $guestOrderId = $User->getGuestOrderId();

        $this->assertIsString($guestOrderId);
        $this->assertNotSame('', $guestOrderId);
    }

    public function testLogoutWithoutSessionDoesNotFail(): void
    {
        $User = $this->createUserWithoutSession();

        $User->logout();

        $this->assertTrue(true);
    }

    public function testGetStandardAddressPrefersInvoiceAddress(): void
    {
        $User = new class () extends GuestOrderUser {
            protected function getSessionInstance(): \QUI\Session | \QUI\System\Console\Session | null
            {
                return null;
            }

            protected function getGuestOrderData(): false | array
            {
                return [
                    'addressInvoice' => json_encode([
                        'firstname' => 'InvoiceFirst',
                        'lastname' => 'InvoiceLast',
                        'country' => 'DE'
                    ]),
                    'addressDelivery' => json_encode([
                        'firstname' => 'DeliveryFirst',
                        'lastname' => 'DeliveryLast',
                        'country' => 'DE'
                    ])
                ];
            }
        };

        $Address = $User->getStandardAddress();

        $this->assertSame('InvoiceFirst', $Address->getAttribute('firstname'));
    }

    public function testGetStandardAddressFallsBackToDeliveryAddress(): void
    {
        $User = new class () extends GuestOrderUser {
            protected function getSessionInstance(): \QUI\Session | \QUI\System\Console\Session | null
            {
                return null;
            }

            protected function getGuestOrderData(): false | array
            {
                return [
                    'addressInvoice' => json_encode([
                        'firstname' => ''
                    ]),
                    'addressDelivery' => json_encode([
                        'firstname' => 'DeliveryFirst',
                        'lastname' => 'DeliveryLast',
                        'country' => 'DE'
                    ])
                ];
            }
        };

        $Address = $User->getStandardAddress();

        $this->assertSame('DeliveryFirst', $Address->getAttribute('firstname'));
    }

    public function testGetUsernameContainsEmailIfAvailable(): void
    {
        $User = $this->createUserWithoutSession();
        $User->setAttribute('email', 'guest@example.com');

        $this->assertStringContainsString(':guest@example.com', $User->getUsername());
    }

    public function testIsCompanyUsesUserAttribute(): void
    {
        $User = $this->createUserWithoutSession();
        $User->setAttribute('isCompany', true);

        $this->assertTrue($User->isCompany());
    }

    public function testIsCompanyUsesAddressCompany(): void
    {
        $User = new class () extends GuestOrderUser {
            protected function getSessionInstance(): \QUI\Session | \QUI\System\Console\Session | null
            {
                return null;
            }

            protected function getGuestOrderData(): false | array
            {
                return [
                    'addressInvoice' => json_encode([
                        'firstname' => 'Max',
                        'lastname' => 'Mustermann',
                        'country' => 'DE',
                        'company' => 'ACME'
                    ])
                ];
            }
        };

        $this->assertTrue($User->isCompany());
    }
}
