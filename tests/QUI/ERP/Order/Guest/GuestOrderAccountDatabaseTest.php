<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\Mail\Mailer;
use QUI\Users\Address;
use Throwable;

class GuestOrderAccountDatabaseTest extends TestCase
{
    private string | int | null $userUuid = null;

    protected function tearDown(): void
    {
        if ($this->userUuid !== null) {
            try {
                QUI::getUsers()->deleteUser($this->userUuid);
            } catch (Throwable) {
            }
        }

        parent::tearDown();
    }

    public function testCreatesGuestAccountWithCopiedAddressData(): void
    {
        $email = 'phpunit-guest-account-' . bin2hex(random_bytes(8)) . '@example.com';
        $SourceAddress = $this->createMock(Address::class);
        $SourceAddress->method('getAttributes')->willReturn([
            'firstname' => 'PHPUnit',
            'lastname' => 'Guest Account',
            'country' => 'DE',
            'street_no' => 'Teststraße 42',
            'zip' => '12345',
            'city' => 'Teststadt'
        ]);

        $User = GuestOrder::createGuestAccount($email, $SourceAddress);

        self::assertNotNull($User);
        $this->userUuid = $User->getUUID();
        self::assertSame($email, $User->getUsername());
        self::assertSame($email, $User->getAttribute('email'));
        self::assertSame('PHPUnit', $User->getAttribute('firstname'));
        self::assertSame('Guest Account', $User->getAttribute('lastname'));
        self::assertSame('Teststadt', $User->getStandardAddress()?->getAttribute('city'));
    }

    public function testRegistersGuestThroughFrontendUsers(): void
    {
        $email = 'pu-fu-' . bin2hex(random_bytes(6)) . '@example.test';
        $originalPost = $_POST;

        try {
            $User = GuestOrder::triggerFrontendUsersRegistration($email);
        } finally {
            $_POST = $originalPost;
        }

        self::assertNotNull($User);
        $this->userUuid = $User->getUUID();
        self::assertSame($email, $User->getAttribute('email'));
        self::assertTrue(QUI::getUsers()->usernameExists($User->getUsername()));
    }

    public function testActivatesGuestAndMarksGeneratedPasswordForReplacement(): void
    {
        $email = 'pu-password-' . bin2hex(random_bytes(6)) . '@example.test';
        $SystemUser = QUI::getUsers()->getSystemUser();
        $User = QUI::getUsers()->createChild($email, $SystemUser);
        $this->userUuid = $User->getUUID();
        $User->setAttribute('email', $email);
        $User->save($SystemUser);
        $originalDisableMailSending = Mailer::$DISABLE_MAIL_SENDING;

        try {
            Mailer::$DISABLE_MAIL_SENDING = true;
            GuestOrder::sendNewPasswordMail($User);
        } finally {
            Mailer::$DISABLE_MAIL_SENDING = $originalDisableMailSending;
        }

        self::assertTrue($User->isActive());
        self::assertTrue((bool)$User->getAttribute('quiqqer.set.new.password'));
    }
}
