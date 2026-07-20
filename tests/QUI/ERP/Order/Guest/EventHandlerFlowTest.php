<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\ArticleList;
use QUI\ERP\Accounting\PriceFactors\FactorList;
use QUI\ERP\Address;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Controls\AbstractOrderingStep;
use QUI\ERP\Order\Controls\OrderProcess\CustomerData;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\OrderInProcess;
use QUI\ERP\Order\OrderProcess;
use QUI\ERP\Order\SimpleCheckout\Checkout;
use QUI\ERP\Order\Utils\OrderProcessSteps;
use QUI\ERP\User;
use QUI\Interfaces\Users\User as UserInterface;
use QUI\Rewrite;
use QUI\Smarty\Collector;
use ReflectionProperty;

class EventHandlerFlowTest extends TestCase
{
    public function testResolvesGuestUserByIdAndUuid(): void
    {
        $this->withGuestOrderType('noRegistration', static function (): void {
            $Guest = new GuestOrderUser();

            self::assertInstanceOf(GuestOrderUser::class, EventHandler::onUserGet($Guest->getId()));
            self::assertInstanceOf(GuestOrderUser::class, EventHandler::onUserGet($Guest->getUUID()));
            self::assertNull(EventHandler::onUserGet('phpunit-unknown-guest-user'));
        });
    }

    public function testDoesNotResolveGuestUserWhenGuestOrdersAreDisabled(): void
    {
        $this->withGuestOrderType('no', static function (): void {
            self::assertNull(EventHandler::onUserGet(6));
        });
    }

    public function testResolvesGuestUserFromFlaggedFrontendSession(): void
    {
        $Session = QUI::getSession();
        self::assertNotNull($Session);
        $originalFlag = $Session->get(GuestOrder::FLAG);

        try {
            $Session->set(GuestOrder::FLAG, 1);
            $this->withGuestOrderType('noRegistration', static function (): void {
                self::assertInstanceOf(GuestOrderUser::class, EventHandler::onUserGetBySession());
            });
        } finally {
            if ($originalFlag === false) {
                $Session->remove(GuestOrder::FLAG);
            } else {
                $Session->set(GuestOrder::FLAG, $originalFlag);
            }
        }
    }

    public function testExtendsGuestOrderMailWithAccountCreationLink(): void
    {
        $this->withGuestOrderType('noRegistration', function (): void {
            $Collector = new Collector();
            $Order = $this->createGuestOrder();

            EventHandler::extendMail($Collector, $Order, []);

            self::assertStringContainsString('t=account', $Collector->getContent());
            self::assertStringContainsString('phpunit-order-uuid', $Collector->getContent());
        });
    }

    public function testTemplateEventsRenderGuestOrderControls(): void
    {
        $this->withSessionUser(new GuestOrderUser(), function (): void {
            $this->withGuestOrderType('noRegistration', static function (): void {
                $OrderCollector = new Collector();
                $CheckoutCollector = new Collector();

                EventHandler::extendOrder($OrderCollector);
                EventHandler::extendCheckout($CheckoutCollector, null, null);

                self::assertStringContainsString(
                    'quiqqer-order-ordering-nobody-guestOrder',
                    $OrderCollector->getContent()
                );
                self::assertStringContainsString(
                    'name="guest-order-create-account"',
                    $CheckoutCollector->getContent()
                );
            });
        });
    }

    public function testDoesNotExtendMailWhenGuestOrdersAreDisabled(): void
    {
        $this->withGuestOrderType('no', function (): void {
            $Collector = new Collector();

            EventHandler::extendMail($Collector, $this->createGuestOrder(), []);

            self::assertSame('', $Collector->getContent());
        });
    }

    public function testRequestWithoutGuestOrderParametersDoesNothing(): void
    {
        $originalRequest = $_REQUEST;

        try {
            $_REQUEST = [];
            EventHandler::onRequest($this->createMock(Rewrite::class), '/phpunit');
            self::assertSame([], $_REQUEST);
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    public function testCreatedEventIgnoresNonPersistedOrderType(): void
    {
        EventHandler::onQuiqqerOrderCreated($this->createMock(AbstractOrder::class));

        self::assertTrue(true);
    }

    public function testAnonymousCheckoutDisablesHiddenAddressAndShippingValidation(): void
    {
        $this->withGuestOrderType('anonymous', function (): void {
            $Checkout = $this->createAnonymousCheckout();
            $showDelivery = true;
            $showShipping = true;
            $showBillingAddress = true;
            $validateAddress = true;
            $validateShipping = true;

            EventHandler::onQuiqqerSimpleCheckoutBodyEnd(
                $Checkout,
                $showDelivery,
                $showShipping,
                $showBillingAddress
            );
            EventHandler::onQuiqqerSimpleCheckoutValidation(
                $Checkout,
                $validateAddress,
                $validateShipping
            );

            self::assertFalse($showDelivery);
            self::assertFalse($showShipping);
            self::assertFalse($showBillingAddress);
            self::assertFalse($validateAddress);
            self::assertFalse($validateShipping);
        });
    }

    public function testAnonymousOrderProcessRemovesCustomerStepAndKeepsOtherSteps(): void
    {
        $this->withGuestOrderType('anonymous', function (): void {
            $Articles = $this->createMock(ArticleList::class);
            $Articles->method('getCalculations')->willReturn(['sum' => 0]);
            $Order = $this->createMock(AbstractOrder::class);
            $Order->method('getArticles')->willReturn($Articles);
            $CustomerData = new CustomerData();
            $OtherStep = $this->createMock(AbstractOrderingStep::class);
            $Steps = new OrderProcessSteps([$CustomerData, $OtherStep]);

            EventHandler::onQuiqqerOrderProcessStepsEnd(
                $this->createMock(OrderProcess::class),
                $Order,
                $Steps
            );

            self::assertSame([$OtherStep], $Steps->toArray());
        });
    }

    public function testOrderProcessSendEventsForwardOrderToGuestAssignment(): void
    {
        $this->withGuestOrderType('noRegistration', function (): void {
            $Nobody = User::convertUserToErpUser(QUI::getUsers()->getNobody());
            $Order = $this->createMock(AbstractOrder::class);
            $Order->expects(self::exactly(2))->method('getCustomer')->willReturn($Nobody);
            $OrderProcess = $this->createMock(OrderProcess::class);
            $OrderProcess->expects(self::exactly(2))->method('getOrder')->willReturn($Order);

            EventHandler::onQuiqqerOrderProcessSend($OrderProcess);
            EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);
        });
    }

    public function testOrderProcessSendEventsIgnoreMissingOrder(): void
    {
        $this->withGuestOrderType('noRegistration', function (): void {
            $OrderProcess = $this->createMock(OrderProcess::class);
            $OrderProcess->expects(self::exactly(2))->method('getOrder')->willReturn(null);

            EventHandler::onQuiqqerOrderProcessSend($OrderProcess);
            EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

            self::assertTrue(true);
        });
    }

    public function testOrderProcessSendEventsHandleOrderLookupFailure(): void
    {
        $this->withGuestOrderType('noRegistration', function (): void {
            $OrderProcess = $this->createMock(OrderProcess::class);
            $OrderProcess->expects(self::exactly(2))
                ->method('getOrder')
                ->willThrowException(new QUI\Exception('PHPUnit order lookup failure'));

            EventHandler::onQuiqqerOrderProcessSend($OrderProcess);
            EventHandler::onQuiqqerOrderProcessSendCreateOrder($OrderProcess);

            self::assertTrue(true);
        });
    }

    public function testAssignsAnonymousGuestCustomerWithoutCreatingAccount(): void
    {
        $Session = QUI::getSession();
        self::assertNotNull($Session);
        $sessionKeys = [GuestOrder::EMAIL, GuestOrder::CUSTOMER_UUID];
        $originalValues = [];

        foreach ($sessionKeys as $key) {
            $originalValues[$key] = $Session->get($key);
        }

        $email = 'phpunit-anonymous-order-' . bin2hex(random_bytes(8)) . '@example.com';
        $customerUuid = QUI\Utils\Uuid::get();

        try {
            $Session->set(GuestOrder::EMAIL, $email);
            $Session->set(GuestOrder::CUSTOMER_UUID, $customerUuid);

            $this->withGuestOrderType('anonymous', function () use ($email, $customerUuid): void {
                $CustomerAddress = $this->createMock(Address::class);
                $Customer = $this->createMock(User::class);
                $Customer->method('getUUID')->willReturn($customerUuid);
                $Customer->method('getAddress')->willReturn($CustomerAddress);
                $PriceFactors = new FactorList();
                $Articles = $this->createMock(ArticleList::class);
                $Articles->method('getPriceFactors')->willReturn($PriceFactors);
                $Order = $this->createMock(AbstractOrder::class);
                $Order->method('getCustomer')->willReturn($Customer);
                $Order->method('getArticles')->willReturn($Articles);
                $Order->expects(self::once())->method('addComment');
                $Order->expects(self::once())->method('setCustomer')->with(
                    self::callback(
                        static fn(mixed $User): bool => $User instanceof GuestOrderUser
                            && $User->getAttribute('email') === $email
                    )
                );
                $Handler = new class () extends EventHandler {
                    public static function assignGuestCustomer(AbstractOrder $Order): void
                    {
                        parent::assignGuestOrderCustomer($Order);
                    }
                };

                $Handler::assignGuestCustomer($Order);
            });
        } finally {
            foreach ($originalValues as $key => $value) {
                if ($value === false) {
                    $Session->remove($key);
                } else {
                    $Session->set($key, $value);
                }
            }
        }
    }

    private function createGuestOrder(): AbstractOrder
    {
        $Customer = $this->createMock(User::class);
        $Customer->method('getUUID')->willReturn('');
        $Customer->method('getAttribute')->with('email')->willReturn('phpunit-guest@example.com');
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getCustomer')->willReturn($Customer);
        $Order->method('getUUID')->willReturn('phpunit-order-uuid');

        return $Order;
    }

    private function createAnonymousCheckout(): Checkout
    {
        $Articles = $this->createMock(ArticleList::class);
        $Articles->method('getCalculations')->willReturn(['sum' => 0]);
        $Order = $this->createMock(OrderInProcess::class);
        $Order->method('getArticles')->willReturn($Articles);
        $Checkout = $this->createMock(Checkout::class);
        $Checkout->method('getOrder')->willReturn($Order);

        return $Checkout;
    }

    private function withGuestOrderType(string $type, callable $callback): void
    {
        $Config = QUI::getPackage('quiqqer/order-guestorder')->getConfig();
        self::assertNotNull($Config);
        $originalSection = $Config->getSection('guestorder');

        try {
            $Config->setValue('guestorder', 'type', $type);
            $Config->save();
            $callback();
        } finally {
            $Config->setSection('guestorder', is_array($originalSection) ? $originalSection : []);
            $Config->save();
        }
    }

    private function withSessionUser(UserInterface $User, callable $callback): void
    {
        $Users = QUI::getUsers();
        $SessionUser = new ReflectionProperty($Users, 'Session');
        $originalUser = $SessionUser->getValue($Users);

        try {
            $SessionUser->setValue($Users, $User);
            $callback();
        } finally {
            $SessionUser->setValue($Users, $originalUser);
        }
    }
}
