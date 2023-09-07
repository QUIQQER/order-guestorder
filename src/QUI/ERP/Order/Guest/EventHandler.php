<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Guest\Controls\GuestOrderButton;
use QUI\ERP\Order\Settings;
use QUI\ERP\Order\Utils\OrderProcessSteps;
use QUI\Mail\Mailer;
use QUI\Rewrite;
use QUI\Smarty\Collector;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use function date;
use function floatval;

class EventHandler
{
    const FLAG = 'guest-order-is-guest';
    const EMAIL = 'guest-order-email';

    /**
     * sets the flag, so we know if we are in a guest order
     *
     * @return void
     */
    public static function setGuestOrderFlag()
    {
        if (GuestOrder::isActive()) {
            QUI::getSession()->set(self::FLAG, 1);
        }
    }

    /**
     * remove the guest order flag
     * so, we are not in a guest order anymore
     */
    public static function removeGuestOrderFlag()
    {
        if (GuestOrder::isActive()) {
            QUI::getSession()->remove(self::FLAG);
        }
    }

    public static function onRequest(Rewrite $Rewrite, string $url)
    {
        if (
            !isset($_REQUEST['guestorder'])
            && !isset($_REQUEST['t'])
            || (isset($_REQUEST['guestorder']) && (int)$_REQUEST['guestorder'] !== 1)
        ) {
            return;
        }

        // account creation
        if ($_REQUEST['t'] === 'account') {
            if (!isset($_REQUEST['u'])) {
                return;
            }

            $user = $_REQUEST['u'];
            $User = null;

            try {
                $User = QUI::getUsers()->getUserByName($user);
            } catch (QUI\Exception $exception) {
            }

            if (!$User) {
                try {
                    $User = QUI::getUsers()->getUserByMail($user);
                } catch (QUI\Exception $exception) {
                }
            }

            if (!$User) {
                // create user via frontend users?
            }

            if ($User->isActive()) {
                $Redirect = new RedirectResponse(QUI::getRewrite()->getProject()->getVHost(true, true));
                $Redirect->setStatusCode(Response::HTTP_SEE_OTHER);
                $Redirect->send();
                exit;
            }

            // password mail and activation mail
            $newPassword = QUI\Security\Password::generateRandom();

            $User->setPassword($newPassword, QUI::getUsers()->getSystemUser());
            $User->setAttribute('quiqqer.set.new.password', true);
            $User->save(QUI::getUsers()->getSystemUser());

            if (!$User->isActive()) {
                $User->activate(false, QUI::getUsers()->getSystemUser());
            }

            // send mail
            $email = $User->getAttribute('email');

            $Mailer = new Mailer();
            $Mailer->addRecipient($email);

            $Mailer->setSubject(
                QUI::getLocale()->get('quiqqer/quiqqer', 'mails.user.new_password.subject')
            );

            $body = QUI::getLocale()->get('quiqqer/quiqqer', 'mails.user.new_password.body', [
                'name' => $User->getName(),
                'password' => $newPassword,
                'forceNewMsg' => QUI::getLocale()->get('quiqqer/quiqqer', 'mails.user.new_password.body.force_new')
            ]);

            $Mailer->setBody($body);
            $Mailer->send();


            $Site = $Rewrite->getSite();
            $Site->setAttribute('short', '');
            $Site->setAttribute('type', 'standard');
            $Site->setAttribute('meta.canonical', $Site->getUrlRewrittenWithHost());
            $Site->setAttribute('quiqqer.bricks.areas', '');

            $Site->setAttribute(
                'content',

                '<div class="messages message-success">' .
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.registration.password.info') .
                '</div>'
            );


            return;
        }


        // invoice creation
        // @todo
        if ($_REQUEST['t'] === 'invoice') {
            if (!isset($_REQUEST['o'])) {
                return;
            }

            $order = $_REQUEST['o'];


            return;
        }
    }

    /**
     * event that hooks into the onUserGetBySession.
     * if we are in a guest order, this event returns a GuestOrderUser object to the user handler
     *
     * @return GuestOrderUser|null
     */
    public static function onUserGetBySession(): ?GuestOrderUser
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        if (!QUI::isFrontend()) {
            return null;
        }

        if (!QUI::getSession()->get(self::FLAG)) {
            return null;
        }

        return new GuestOrderUser();
    }

    /**
     * event that hooks into the UserManager->get().
     * if the desired user is a GuestOrderUser (id=6), then this is returned.
     *
     * @param int $id
     * @return GuestOrderUser|null
     */
    public static function onUserGet(int $id): ?GuestOrderUser
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        $Guest = new GuestOrderUser();

        if ($Guest->getId() === $id) {
            return $Guest;
        }

        return null;
    }

    /**
     * event that hooks into the order process
     *
     * the order process does not know about a guest order. the guest order has additional
     * properties to assign an order in process to the guest user.
     * this event hooks into the getOrder process and returns the guest order if necessary
     *
     * @param $OrderProcess
     * @return QUI\ERP\Order\OrderInProcess|null
     * @throws QUI\Database\Exception
     */
    public static function onOrderProcessGetOrder($OrderProcess): ?QUI\ERP\Order\OrderInProcess
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        if (!QUI::isFrontend()) {
            return null;
        }

        $SessionUser = QUI::getUserBySession();
        $Handler = QUI\ERP\Order\Handler::getInstance();

        if (!($SessionUser instanceof GuestOrderUser)) {
            return null;
        }

        $guestId = $SessionUser->getGuestOrderId();
        $sessId = $SessionUser->getId();

        $result = QUI::getDataBase()->fetch([
            'from' => $Handler->tableOrderProcess(),
            'where' => [
                'customerId' => $sessId,
                'successful' => 0,
                'guestOrder' => $guestId
            ],
            'limit' => 1,
            'order' => 'c_date DESC'
        ]);

        if (isset($result[0]['id'])) {
            $orderId = $result[0]['id'];
        } else {
            $status = QUI\ERP\Constants::ORDER_STATUS_CREATED;

            if (Settings::getInstance()->get('orderStatus', 'standard')) {
                $status = (int)Settings::getInstance()->get('orderStatus', 'standard');
            }

            QUI::getDataBase()->insert($Handler->tableOrderProcess(), [
                'id_prefix' => QUI\ERP\Order\Utils\Utils::getOrderPrefix(),
                'c_user' => $sessId,
                'c_date' => date('Y-m-d H:i:s'),
                'hash' => QUI\Utils\Uuid::get(),
                'customerId' => $sessId,
                'status' => $status,
                'paid_status' => QUI\ERP\Constants::PAYMENT_STATUS_OPEN,
                'successful' => 0,
                'guestOrder' => $guestId
            ]);

            $orderId = QUI::getDatabase()->getPDO()->lastInsertId();
        }

        try {
            return $Handler->getOrderInProcess($orderId);
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * when the order is sent, it will be checked if this is a guest order.
     * if so, we create a guest user account
     *
     * @param QUI\ERP\Order\OrderProcess $OrderProcess
     * @return void
     */
    public static function onQuiqqerOrderProcessSendCreateOrder(QUI\ERP\Order\OrderProcess $OrderProcess)
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        try {
            $Order = $OrderProcess->getOrder();
        } catch (\Exception $Exception) {
            return;
        }

        $Customer = $Order->getCustomer();
        $GuestUser = new GuestOrderUser();

        // no guest user? we have nothing to do
        // if yes, we have to create the user
        if ($Customer->getId() !== $GuestUser->getId()) {
            return;
        }

        $CustomerAddress = $Customer->getAddress();
        $SystemUser = QUI::getUsers()->getSystemUser();
        $email = QUI::getSession()->get(self::EMAIL);

        if (empty($_REQUEST['guest-order-create-account'])) {
            // create normal account
            if (QUI::getUsers()->usernameExists($email)) {
                // user already exists
                $User = QUI::getUsers()->getUserByName($email);
            } else {
                // create user account -> guest user
                $User = QUI::getUsers()->createChild($email, $SystemUser);
                $Address = $User->getStandardAddress();
                $Address->setAttributes($CustomerAddress->getAttributes());
                $Address->save($SystemUser);

                $User->setAttribute('firstname', $CustomerAddress->getAttribute('firstname'));
                $User->setAttribute('lastname', $CustomerAddress->getAttribute('lastname'));
                $User->setAttribute('email', $email);

                try {
                    if (QUI::getPackageManager()->isInstalled('quiqqer/customer')) {
                        $User->addToGroup(QUI\ERP\Customer\Customers::getInstance()->getCustomerGroupId());
                    }
                } catch (QUI\Exception $exception) {
                }

                $User->save($SystemUser);
            }

            $Order->setCustomer($User);
            $Order->setInvoiceAddress($Address);
            $Order->save($SystemUser);

            return;
        }

        // the user wanted an account after all, and he has checked the checkbox
        // we have to create an account via frontend users because of the mail auth stuff
        $_POST['registration'] = true;
        $_POST['termsOfUseAccepted'] = true;
        $_POST['email'] = QUI::getSession()->get(self::EMAIL);

        $EmailRegistrar = new QUI\FrontendUsers\Registrars\Email\Registrar();
        $EmailRegistrar->setAttribute('email', $email);

        $Registration = new QUI\FrontendUsers\Controls\Registration();
        $Registration->setAttribute('Registrar', $EmailRegistrar);
        $Registration->register();

        $User = $Registration->getRegisteredUser();

        $Address = $User->getStandardAddress();
        $Address->setAttributes($CustomerAddress->getAttributes());
        $Address->save($SystemUser);

        $User->setAttribute('firstname', $CustomerAddress->getAttribute('firstname'));
        $User->setAttribute('lastname', $CustomerAddress->getAttribute('lastname'));
        $User->setAttribute('email', $email);

        try {
            if (QUI::getPackageManager()->isInstalled('quiqqer/customer')) {
                $User->addToGroup(QUI\ERP\Customer\Customers::getInstance()->getCustomerGroupId());
            }
        } catch (QUI\Exception $exception) {
        }

        $User->save($SystemUser);

        $Order->setCustomer($User);
        $Order->setInvoiceAddress($Address);
        $Order->save($SystemUser);
    }

    /**
     * from basket to order, the current order is cleared. the guest order must take this into account.
     * the guest order must adjust the new order entry in the order-processing table for itself again,
     * so that the guest user is assigned to it.
     *
     * @param AbstractOrder $Order
     * @return void
     */
    public static function onQuiqqerOrderClear(AbstractOrder $Order)
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        if (!QUI::isFrontend()) {
            return;
        }

        $SessionUser = self::onUserGetBySession();

        if (!($SessionUser instanceof GuestOrderUser)) {
            return;
        }

        // we have to check, only order in process
        if (!($Order instanceof QUI\ERP\Order\OrderInProcess)) {
            return;
        }

        try {
            $SessionUser = QUI::getUserBySession();
            $Handler = QUI\ERP\Order\Handler::getInstance();

            $guestOrderId = $SessionUser->getGuestOrderId();
            $orderId = $Order->getId();

            $result = QUI::getDataBase()->fetch([
                'from' => $Handler->tableOrderProcess(),
                'where' => [
                    'id' => $orderId
                ],
                'limit' => 1
            ]);

            if (empty($result[0])) {
                return;
            }

            if ($result[0]['guestOrder'] === $guestOrderId) {
                return;
            }

            QUI::getDataBase()->update(
                $Handler->tableOrderProcess(),
                ['guestOrder' => $guestOrderId],
                ['id' => $orderId]
            );

            QUI::getDataBase()->delete($Handler->tableOrderProcess(), [
                'id' => [
                    'type' => 'NOT',
                    'value' => $orderId
                ]
            ]);
        } catch (\Exception $exception) {
            QUI\System\Log::addError($exception->getMessage());
        }
    }

    //region Anonymous Order

    /**
     * @param $OrderProcess
     * @param AbstractOrder|null $Order
     *
     * @return void
     */
    public static function onQuiqqerOrderProcessStepsEnd(
        $OrderProcess,
        ?AbstractOrder $Order,
        OrderProcessSteps $Steps
    ) {
        if (!GuestOrder::isActive()) {
            return;
        }

        if (!GuestOrder::isAnonymousOrder()) {
            return;
        }

        if (!$Order) {
            return;
        }

        $calculations = $Order->getArticles()->getCalculations();
        $sum = $calculations['sum'];

        $maxTotal = QUI::getPackage('quiqqer/order-guestorder')->getConfig()->getValue(
            'guestorder',
            'anonymous_max_sum'
        );

        $maxTotal = floatval($maxTotal);

        if (!empty($maxTotal) && $sum > $maxTotal) {
            return;
        }

        // don't show the shipping tab
        // don't show customer tab
        $steps = $Steps->toArray();
        $Steps->clear();

        foreach ($steps as $Step) {
            if (
                $Step instanceof QUI\ERP\Order\Controls\OrderProcess\CustomerData
                || $Step instanceof QUI\ERP\Shipping\Order\Shipping
            ) {
                continue;
            }

            $Steps->append($Step);
        }
    }

    //endregion

    //region extend templates

    public static function extendOrder(Collector $Collector)
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        if (!QUI::isFrontend()) {
            return null;
        }

        $GuestInit = new GuestOrderButton();
        $Collector->append($GuestInit->create());
    }

    public static function extendCheckout(Collector $Collector, $User, $Order)
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        if (!QUI::isFrontend()) {
            return;
        }

        if (GuestOrder::isAnonymousOrder()) {
            return;
        }

        $locale = QUI::getLocale()->get('quiqqer/order-guestorder', 'order.create.account.message');

        $Collector->append(
            '<div class="quiqqer-order-step-checkout-notice" style="margin-top: 10px;">
                <label>
                    <input type="checkbox" name="guest-order-create-account" />' . $locale . '
                </label>
            </div>'
        );
    }

    public static function extendMail(Collector $Collector, AbstractOrder $Order, $Articles)
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        // activated users do not need activation links
        $Customer = $Order->getCustomer();

        if ($Customer->getId()) {
            try {
                $User = QUI::getUsers()->get($Customer->getId());

                if ($User->isActive()) {
                    return;
                }
            } catch (QUI\Exception $exception) {
            }
        }

        $html = '<div style="margin-top: 20px; border: 1px solid #ddd; padding: 10px; background: #f8f8f8">';
        $invoiceLink = GuestOrder::getInvoiceCreationLink($Order);
        $createAccountLink = GuestOrder::getAccountCreationLink($Order);

        if (GuestOrder::isAnonymousOrder()) {
            // Anonyme Bestellung: Rechnungserzeugung oder Kundenkonto anlegen
            $html .= QUI::getLocale()->get('quiqqer/order-guestorder', 'mail.link.create.invoice', [
                'link' => $invoiceLink
            ]);

            $html .= '<br />';
            $html .= '<br />';
        }

        $html .= QUI::getLocale()->get('quiqqer/order-guestorder', 'mail.link.create.account', [
            'link' => $createAccountLink
        ]);

        $html .= '</div>';

        if (!empty($html)) {
            $Collector->append($html);
        }
    }

    //endregion
}
