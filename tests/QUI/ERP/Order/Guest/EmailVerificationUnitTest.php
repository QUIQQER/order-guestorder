<?php

namespace QUITests\Order\Guest;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EmailVerification;
use QUI\Verification\Entity\LinkVerification;
use QUI\Verification\Enum\VerificationErrorReason;

class EmailVerificationUnitTest extends TestCase
{
    public function testUsesFrontendUsersEmailVerificationContract(): void
    {
        self::assertInstanceOf(QUI\FrontendUsers\EmailVerification::class, new EmailVerification());
    }

    public function testSuccessMessageContainsOrderRedirectScript(): void
    {
        $message = (new EmailVerification())->getSuccessMessage($this->createVerification());

        self::assertStringContainsString('window.location =', $message);
        self::assertStringContainsString('setTimeout', $message);
    }

    public function testErrorMessageUsesFrontendUsersLocaleMessage(): void
    {
        $Verification = $this->createVerification();
        $Handler = new EmailVerification();

        self::assertSame(
            QUI::getLocale()->get('quiqqer/frontend-users', 'message.registration_error'),
            $Handler->getErrorMessage($Verification, VerificationErrorReason::INVALID_CODE)
        );
    }

    public function testErrorCallbackAcceptsVerificationReason(): void
    {
        $Handler = new EmailVerification();

        $Handler->onError($this->createVerification(), VerificationErrorReason::EXPIRED);

        self::assertTrue(true);
    }

    private function createVerification(): LinkVerification
    {
        $now = new DateTimeImmutable();

        return new LinkVerification(
            'phpunit-verification-uuid',
            'phpunit-verification-identifier',
            'phpunit-verification-code',
            $now,
            $now,
            0,
            'https://example.test/verify'
        );
    }
}
