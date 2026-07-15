<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EmailVerification;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\Mail\Manager as MailManager;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Rewrite;
use QUI\Utils\Doctrine;
use QUI\Verification\VerificationRepository;
use ReflectionProperty;
use Throwable;

class GuestOrderVerificationDatabaseTest extends TestCase
{
    private MailManager $originalMailManager;
    private Rewrite $originalRewrite;
    private mixed $originalSessionUser;
    private mixed $originalCustomerUuid;
    private string $identifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalMailManager = QUI::getMailManager();
        $this->originalRewrite = QUI::$Rewrite ?? QUI::getRewrite();
        $Session = QUI::getSession();
        self::assertNotNull($Session);
        $this->originalCustomerUuid = $Session->get(GuestOrder::CUSTOMER_UUID);
        $Session->set(GuestOrder::CUSTOMER_UUID, QUI\Utils\Uuid::get());
        $Guest = new GuestOrderUser();
        $this->identifier = 'confirmemail-' . $Guest->getUUID();
        $Users = QUI::getUsers();
        $SessionUser = new ReflectionProperty($Users, 'Session');
        $this->originalSessionUser = $SessionUser->getValue($Users);
        $SessionUser->setValue($Users, $Guest);
    }

    protected function tearDown(): void
    {
        try {
            QUI::getDataBaseConnection()->delete(
                Doctrine::quoteIdentifier(
                    QUI::getDBTableName(VerificationRepository::TBL_VERIFICATION_PROCESSES)
                ),
                [Doctrine::quoteIdentifier('identifier') => $this->identifier]
            );
        } catch (Throwable) {
        }

        QUI::$MailManager = $this->originalMailManager;
        QUI::$Rewrite = $this->originalRewrite;
        $Users = QUI::getUsers();
        (new ReflectionProperty($Users, 'Session'))->setValue($Users, $this->originalSessionUser);
        $Session = QUI::getSession();

        if ($Session) {
            if ($this->originalCustomerUuid === false) {
                $Session->remove(GuestOrder::CUSTOMER_UUID);
            } else {
                $Session->set(GuestOrder::CUSTOMER_UUID, $this->originalCustomerUuid);
            }
        }

        parent::tearDown();
    }

    public function testCreatesGuestOrderVerificationAndPassesMailToManager(): void
    {
        $email = 'phpunit-verification-' . bin2hex(random_bytes(8)) . '@example.com';
        $VerifierSite = $this->createMock(Site::class);
        $VerifierSite->method('getUrlRewrittenWithHost')->willReturn('https://example.test/verify');
        $Project = $this->createMock(Project::class);
        $Project->method('getSitesIds')->willReturn([['id' => 1]]);
        $Project->method('get')->with(1)->willReturn($VerifierSite);
        $Rewrite = $this->createMock(Rewrite::class);
        $Rewrite->method('getProject')->willReturn($Project);
        QUI::$Rewrite = $Rewrite;
        $MailManager = $this->createMock(MailManager::class);
        $MailManager->expects(self::once())
            ->method('send')
            ->with(
                $email,
                self::isType('string'),
                self::callback(static fn(string $body): bool => str_contains($body, $email))
            );
        QUI::$MailManager = $MailManager;

        GuestOrder::sendEmailVerification(
            $email,
            QUI::getProjectManager()->getStandard()
        );

        $handler = QUI::getDataBaseConnection()->createQueryBuilder()
            ->select(Doctrine::quoteIdentifier('verificationHandler'))
            ->from(Doctrine::quoteIdentifier(
                QUI::getDBTableName(VerificationRepository::TBL_VERIFICATION_PROCESSES)
            ))
            ->where(Doctrine::quoteIdentifier('identifier') . ' = :identifier')
            ->setParameter('identifier', $this->identifier)
            ->executeQuery()
            ->fetchOne();

        self::assertSame(EmailVerification::class, $handler);
    }
}
