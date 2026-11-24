<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\Handler;
use QUI\ERP\Order\OrderInProcess;
use QUI\ERP\Order\OrderProcess;
use QUI\Interfaces\Projects\Site as SiteInterface;
use QUI\Mail\Mailer;
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
    private string | int | null $createdUserUuid = null;
    private bool $originalDisableMailSending;
    private array $loginOrderHashes = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDisableMailSending = Mailer::$DISABLE_MAIL_SENDING;
        Mailer::$DISABLE_MAIL_SENDING = true;
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
        foreach ($this->loginOrderHashes as $hash) {
            QUI::getDataBaseConnection()->delete(
                Doctrine::quoteIdentifier(Handler::getInstance()->tableOrderProcess()),
                [Doctrine::quoteIdentifier('hash') => $hash]
            );
        }

        if ($this->createdUserUuid !== null) {
            try {
                QUI::getUsers()->deleteUser($this->createdUserUuid);
            } catch (Throwable) {
            }
        }

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
        Mailer::$DISABLE_MAIL_SENDING = $this->originalDisableMailSending;
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

    public function testLoginTransfersCurrentGuestDraftAndPreservesItsData(): void
    {
        $Order = EventHandler::onOrderProcessGetOrder($this->createMock(OrderProcess::class));
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $hash = $Order->getUUID();
        $this->loginOrderHashes[] = $hash;
        $Order->setData('login-transfer-marker', 'preserved');
        $Order->save(QUI::getUsers()->getSystemUser());
        $User = $this->createLoginCustomer();

        QUI::getUsers()->withSessionUser($User, static function () use ($User, $hash): void {
            EventHandler::onUserLogin($User);
            $Transferred = Handler::getInstance()->getOrderInProcessByHash($hash);

            self::assertSame($User->getUUID(), $Transferred->getCustomer()->getUUID());
            self::assertSame($User->getUUID(), $Transferred->getCreateUser()->getUUID());
            self::assertSame('preserved', $Transferred->getDataEntry('login-transfer-marker'));
            $Transferred->save($User);

            // A second login callback must leave the adopted order intact.
            EventHandler::onUserLogin($User);
            self::assertSame($hash, $Transferred->getUUID());
        });

        self::assertEmpty(QUI::getSession()->get(GuestOrder::FLAG));
    }

    public function testLoginDoesNotTransferDraftsFromAnotherGuestSession(): void
    {
        $Order = EventHandler::onOrderProcessGetOrder($this->createMock(OrderProcess::class));
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $hash = $Order->getUUID();
        $this->loginOrderHashes[] = $hash;
        $guestUuid = $Order->getCustomer()->getUUID();
        QUI::getSession()->set('guest-order-id', 'another-guest-session');
        $User = $this->createLoginCustomer();

        QUI::getUsers()->withSessionUser($User, static function () use ($User): void {
            EventHandler::onUserLogin($User);
        });

        $Unchanged = Handler::getInstance()->getOrderInProcessByHash($hash);
        self::assertSame($guestUuid, $Unchanged->getCustomer()->getUUID());
    }

    public function testLoginDoesNotTransferDraftsAlreadySubmittedForPayment(): void
    {
        $Order = EventHandler::onOrderProcessGetOrder($this->createMock(OrderProcess::class));
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $hash = $Order->getUUID();
        $this->loginOrderHashes[] = $hash;
        $guestUuid = $Order->getCustomer()->getUUID();
        $Order->setData('orderedWithCosts', true);
        $Order->save(QUI::getUsers()->getSystemUser());
        $User = $this->createLoginCustomer();

        QUI::getUsers()->withSessionUser($User, static function () use ($User): void {
            EventHandler::onUserLogin($User);
        });

        $Unchanged = Handler::getInstance()->getOrderInProcessByHash($hash);
        self::assertSame($guestUuid, $Unchanged->getCustomer()->getUUID());
    }

    private function createLoginCustomer(): QUI\Users\User
    {
        $SystemUser = QUI::getUsers()->getSystemUser();
        $User = QUI::getUsers()->createChild($this->email, $SystemUser);
        $User->setAttribute('email', $this->email);
        $User->save($SystemUser);
        $this->createdUserUuid = $User->getUUID();

        return $User;
    }

    public function testAnonymousOrderStatusIsSavedAndLaterChangesArePreserved(): void
    {
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $Config->setValue('guestorder', 'type', 'anonymous');
        $statusId = $this->configureGuestStatus();
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $originalStatus = $Order->getProcessingStatus();
        self::assertNotNull($originalStatus);
        $OrderProcess->method('getOrder')->willReturn($Order);

        EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

        $ReloadedOrder = new OrderInProcess($Order->getId());
        self::assertSame($statusId, $ReloadedOrder->getProcessingStatus()?->getId());
        self::assertTrue($ReloadedOrder->getDataEntry('guest-order-status-assigned'));
        self::assertContains(
            QUI::getLocale()->get('quiqqer/order', 'message.change.order.status', [
                'status' => $ReloadedOrder->getProcessingStatus()?->getTitle(),
                'statusId' => $statusId
            ]),
            array_column($ReloadedOrder->getHistory()->toArray(), 'message')
        );

        $Order->setProcessingStatus($originalStatus);
        $Order->save(QUI::getUsers()->getSystemUser());
        EventHandler::onQuiqqerOrderProcessSend($OrderProcess);

        $ReloadedOrder = new OrderInProcess($Order->getId());
        self::assertSame($originalStatus->getId(), $ReloadedOrder->getProcessingStatus()?->getId());
    }

    #[DataProvider('guestOrderCommentStates')]
    public function testGuestOrderCommentIsAddedOnlyOnceAcrossReloadedCheckoutEvents(
        bool $existingComment,
        bool $alreadyMarked
    ): void {
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $Config->setValue('guestorder', 'type', 'anonymous');
        $Config->save();

        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);

        $message = QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.order.type');
        $Order->addComment('Please leave the parcel at the door.');

        if ($existingComment) {
            $Order->addComment($message);
        }

        if ($alreadyMarked) {
            $Order->setData('guest-order-comment-added', true);
        }

        $Order->save(QUI::getUsers()->getSystemUser());
        $OrderProcess->method('getOrder')->willReturnCallback(static function () use (&$Order): OrderInProcess {
            return $Order;
        });

        EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $Order = new OrderInProcess($Order->getId());
            EventHandler::onQuiqqerOrderProcessSend($OrderProcess);
        }

        $ReloadedOrder = new OrderInProcess($Order->getId());
        $messages = array_column($ReloadedOrder->getComments()->toArray(), 'message');
        $expected = ['Please leave the parcel at the door.'];

        if (!$alreadyMarked || $existingComment) {
            $expected[] = $message;
        }

        self::assertSame($expected, $messages);
        self::assertTrue($ReloadedOrder->getDataEntry('guest-order-comment-added'));
    }

    public static function guestOrderCommentStates(): array
    {
        return [
            'new guest order' => [false, false],
            'existing unmarked guest comment' => [true, false],
            'existing marked guest comment' => [true, true],
            'intentionally removed guest comment' => [false, true]
        ];
    }

    public function testGuestCustomerReceivesConfiguredOrderStatus(): void
    {
        $statusId = $this->configureGuestStatus();
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $OrderProcess->method('getOrder')->willReturn($Order);

        try {
            EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

            $ReloadedOrder = new OrderInProcess($Order->getId());
            self::assertSame($statusId, $ReloadedOrder->getProcessingStatus()?->getId());
            self::assertTrue($ReloadedOrder->getDataEntry('guest-order-status-assigned'));
        } finally {
            if (QUI::getUsers()->usernameExists($this->email)) {
                $this->createdUserUuid = QUI::getUsers()->getUserByName($this->email)->getUUID();
            }
        }
    }

    public function testAccountRegistrationKeepsRegularOrderStatus(): void
    {
        $this->email = 'pu-status-' . bin2hex(random_bytes(6)) . '@example.test';
        QUI::getSession()?->set(GuestOrder::EMAIL, $this->email);
        $this->configureGuestStatus();
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $originalStatusId = $Order->getProcessingStatus()?->getId();
        $OrderProcess->method('getOrder')->willReturn($Order);
        $originalPost = $_POST;
        $originalDisableMailSending = Mailer::$DISABLE_MAIL_SENDING;

        try {
            Mailer::$DISABLE_MAIL_SENDING = true;
            $_REQUEST['guest-order-create-account'] = 1;
            RegistrationRequestFixture::run(
                $this->email,
                static fn() => EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess)
            );

            self::assertTrue(QUI::getUsers()->usernameExists($this->email));
            $ReloadedOrder = new OrderInProcess($Order->getId());
            self::assertSame($originalStatusId, $ReloadedOrder->getProcessingStatus()?->getId());
            self::assertEmpty($ReloadedOrder->getDataEntry('guest-order-status-assigned'));
        } finally {
            if (QUI::getUsers()->usernameExists($this->email)) {
                $this->createdUserUuid = QUI::getUsers()->getUserByName($this->email)->getUUID();
            }

            $_POST = $originalPost;
            Mailer::$DISABLE_MAIL_SENDING = $originalDisableMailSending;
        }
    }

    public function testGuestOrderForExistingCustomerReceivesGuestStatus(): void
    {
        $User = QUI::getUsers()->createChild($this->email, QUI::getUsers()->getSystemUser());
        $this->createdUserUuid = $User->getUUID();
        $statusId = $this->configureGuestStatus();
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $OrderProcess->method('getOrder')->willReturn($Order);

        EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

        $ReloadedOrder = new OrderInProcess($Order->getId());
        self::assertSame($statusId, $ReloadedOrder->getProcessingStatus()?->getId());
        self::assertSame($User->getUUID(), $ReloadedOrder->getCustomer()->getUUID());
    }

    public function testRegularCustomerOrderKeepsItsStatus(): void
    {
        $this->configureGuestStatus();
        $User = QUI::getUsers()->createChild($this->email, QUI::getUsers()->getSystemUser());
        $this->createdUserUuid = $User->getUUID();
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $Order->setCustomer($User);
        $Order->save(QUI::getUsers()->getSystemUser());
        $originalStatusId = $Order->getProcessingStatus()?->getId();
        $OrderProcess->method('getOrder')->willReturn($Order);

        EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

        $ReloadedOrder = new OrderInProcess($Order->getId());
        self::assertSame($originalStatusId, $ReloadedOrder->getProcessingStatus()?->getId());
        self::assertEmpty($ReloadedOrder->getDataEntry('guest-order-status-assigned'));
    }

    public function testDeletedGuestStatusDoesNotPreventCheckout(): void
    {
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $Config->setValue('guestorder', 'type', 'anonymous');
        $Config->setValue('guestorder', 'order_status', 2147483647);
        $Config->save();
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $originalStatusId = $Order->getProcessingStatus()?->getId();
        $OrderProcess->method('getOrder')->willReturn($Order);

        EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

        $ReloadedOrder = new OrderInProcess($Order->getId());
        self::assertSame($originalStatusId, $ReloadedOrder->getProcessingStatus()?->getId());
        self::assertTrue($ReloadedOrder->getDataEntry('guest-order-status-assigned'));
    }

    private function configureGuestStatus(): int
    {
        $statuses = QUI\ERP\Order\ProcessingStatus\Handler::getInstance()->getList();
        $defaultStatus = (int)QUI\ERP\Order\Settings::getInstance()->get('orderStatus', 'standard');
        unset($statuses[$defaultStatus]);
        self::assertNotEmpty($statuses);
        $statusId = (int)array_key_first($statuses);
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $Config->setValue('guestorder', 'order_status', $statusId);
        $Config->save();

        return $statusId;
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

    public function testAccountRequestCreatesUserAndAssignsItToGuestOrder(): void
    {
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->with('step')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);
        $content = RegistrationRequestFixture::run(
            $this->email,
            fn() => $this->requestAccountCreation($Order)
        );
        $User = QUI::getUsers()->getUserByMail($this->email);
        $this->createdUserUuid = $User->getUUID();
        $StoredOrder = Handler::getInstance()->getOrderByHash($Order->getUUID());

        self::assertSame($User->getUUID(), $StoredOrder->getCustomer()?->getUUID());
        self::assertTrue($User->isActive());
        self::assertTrue((bool)$User->getAttribute('quiqqer.set.new.password'));
        self::assertIsString($content);
        self::assertStringContainsString('message-success', $content);
    }

    public function testAccountRequestActivatesExistingInactiveUser(): void
    {
        $SystemUser = QUI::getUsers()->getSystemUser();
        $User = QUI::getUsers()->createChild($this->email, $SystemUser);
        $this->createdUserUuid = $User->getUUID();
        $User->setAttribute('email', $this->email);
        $User->save($SystemUser);
        self::assertFalse($User->isActive());
        $OrderProcess = $this->createMock(OrderProcess::class);
        $OrderProcess->method('getAttribute')->with('step')->willReturn(null);
        $Order = EventHandler::onOrderProcessGetOrder($OrderProcess);
        self::assertInstanceOf(OrderInProcess::class, $Order);

        $content = $this->requestAccountCreation($Order);

        self::assertTrue($User->isActive());
        self::assertTrue((bool)$User->getAttribute('quiqqer.set.new.password'));
        self::assertIsString($content);
        self::assertStringContainsString('message-success', $content);
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

    private function requestAccountCreation(OrderInProcess $Order): ?string
    {
        $_REQUEST = [
            'guestorder' => '1',
            't' => 'account',
            'o' => $Order->getUUID(),
            'u' => $this->email
        ];
        $originalRewrite = QUI::$Rewrite ?? QUI::getRewrite();
        $originalPost = $_POST;
        $originalDisableMailSending = Mailer::$DISABLE_MAIL_SENDING;
        $FrontendUsersConfig = QUI::getPackage('quiqqer/frontend-users')->getConfig();
        self::assertNotNull($FrontendUsersConfig);
        $originalRegistration = $FrontendUsersConfig->getSection('registration');
        $content = null;
        $Site = $this->createMock(SiteInterface::class);
        $Site->method('setAttribute')->willReturnCallback(
            static function (string $name, mixed $value) use (&$content): void {
                if ($name === 'content') {
                    $content = $value;
                }
            }
        );
        $Site->method('getAttribute')->willReturnCallback(
            static fn(string $name): mixed => $originalRewrite->getSite()?->getAttribute($name)
        );
        $Rewrite = $this->createMock(Rewrite::class);
        $Rewrite->method('getProject')->willReturn($originalRewrite->getProject());
        $Rewrite->method('getSite')->willReturn($Site);

        try {
            $FrontendUsersConfig->setValue('registration', 'addressInput', 1);
            $FrontendUsersConfig->setValue('registration', 'addressFields', json_encode([
                'firstname' => ['show' => true, 'required' => true]
            ]));
            $FrontendUsersConfig->save();
            QUI::$Rewrite = $Rewrite;
            Mailer::$DISABLE_MAIL_SENDING = true;
            EventHandler::onRequest($Rewrite, '/phpunit-account');
        } finally {
            QUI::$Rewrite = $originalRewrite;
            $_POST = $originalPost;
            Mailer::$DISABLE_MAIL_SENDING = $originalDisableMailSending;
            $FrontendUsersConfig->setSection(
                'registration',
                is_array($originalRegistration) ? $originalRegistration : []
            );
            $FrontendUsersConfig->save();
        }

        return $content;
    }
}
