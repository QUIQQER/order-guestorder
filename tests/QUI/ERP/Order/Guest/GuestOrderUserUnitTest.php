<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Order\Guest\GuestOrderUser;

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

    public function testGetIdFallsBackToNobodyIdWithoutSession(): void
    {
        $User = $this->createUserWithoutSession();

        $this->assertSame(6, $User->getId());
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
