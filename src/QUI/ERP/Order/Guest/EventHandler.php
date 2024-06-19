<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Guest\Controls\GuestOrderButton;
use QUI\ERP\Order\OrderProcess;
use QUI\ERP\Order\Settings;
use QUI\ERP\Order\Utils\OrderProcessSteps;
use QUI\Exception;
use QUI\FrontendUsers\Exception\UserAlreadyExistsException;
use QUI\Rewrite;
use QUI\Smarty\Collector;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use function count;
use function date;
use function floatval;

class EventHandler
{
    /**
     * Handles the onRequest event triggered by the Rewrite class
     *
     * @param Rewrite $Rewrite The Rewrite object that triggered the event
     * @param string $url The URL associated with the event
     * @return void
     *
     * @throws Exception
     * @throws QUI\FrontendUsers\Exception
     * @throws UserAlreadyExistsException
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public static function onRequest(Rewrite $Rewrite, string $url): void
    {
        if (
            !isset($_REQUEST['guestorder'])
            || !isset($_REQUEST['t'])
            || !isset($_REQUEST['o'])
            || !isset($_REQUEST['u'])
        ) {
            return;
        }

        // account creation
        if ($_REQUEST['t'] === 'account') {
            self::onRequestUserCreation();
            return;
        }

        // invoice creation
        if ($_REQUEST['t'] === 'invoice') {
            self::onRequestInvoiceCreation();
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

        if (!QUI::getSession()->get(GuestOrder::FLAG)) {
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
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * when the order is sent, it will be checked if this is a guest order.
     * if so, we create a guest user account
     *
     * @param OrderProcess $OrderProcess
     * @return void
     */
    public static function onQuiqqerOrderProcessSend(QUI\ERP\Order\OrderProcess $OrderProcess): void
    {
        if (!GuestOrder::isActive()) {
            return;
        }

        try {
            $Order = $OrderProcess->getOrder();
        } catch (\Exception $Exception) {
            QUI\System\Log::addError($Exception->getMessage(), [
                'event' => 'onQuiqqerOrderProcessSend'
            ]);

            return;
        }

        $Customer = $Order->getCustomer();
        $GuestUser = new GuestOrderUser();

        // no guest user? we have nothing to do
        // if yes, we have to create the user
        if ($Customer->getUUID() !== $GuestUser->getUUID()) {
            return;
        }

        try {
            $CustomerAddress = $Customer->getAddress();
            $SystemUser = QUI::getUsers()->getSystemUser();
            $email = QUI::getSession()->get(GuestOrder::EMAIL);

            if (empty($_REQUEST['guest-order-create-account'])) {
                // create normal account
                if (QUI::getUsers()->usernameExists($email)) {
                    // user already exists
                    $User = QUI::getUsers()->getUserByName($email);
                    $Order->setCustomer($User);
                } elseif (GuestOrder::isAnonymousOrder()) {
                    $GuestUser->setAttribute('email', $email);
                    $Order->setCustomer($GuestUser);
                } else {
                    $User = GuestOrder::createGuestAccount($email, $CustomerAddress);

                    $Order->setCustomer($User);
                    $Order->setInvoiceAddress($User->getStandardAddress());
                }

                $Order->save($SystemUser);

                return;
            }

            // the user wanted an account after all, and he has checked the checkbox
            // we have to create an account via frontend users because of the mail auth stuff
            $User = GuestOrder::triggerFrontendUsersRegistration($email);


            $Address = $User->getStandardAddress();
            $Address->setAttributes($CustomerAddress->getAttributes());
            $Address->save($SystemUser);

            $User->setAttribute('firstname', $CustomerAddress->getAttribute('firstname'));
            $User->setAttribute('lastname', $CustomerAddress->getAttribute('lastname'));
            $User->setAttribute('email', $email);
            $User->save($SystemUser);

            $Order->setCustomer($User);
            $Order->setInvoiceAddress($Address);
            $Order->save($SystemUser);
        } catch (\Exception $exception) {
            QUI\System\Log::writeException($exception);
            QUI\System\Log::addError($exception->getMessage());
        }
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
     * Remove unwanted steps from the order process, if the order is a guest order
     *
     * @param OrderProcess $OrderProcess - The current order process instance
     * @param AbstractOrder|null $Order - The current order object (or null if not available)
     * @param OrderProcessSteps $Steps - The order process steps object
     *
     * @return void
     * @throws Exception
     *
     * @todo anonyme bestellung nur für bestimmte kategorien / bzw produkte
     */
    public static function onQuiqqerOrderProcessStepsEnd(
        OrderProcess $OrderProcess,
        ?AbstractOrder $Order,
        OrderProcessSteps $Steps
    ): void {
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

    /**
     * extends the order process login with a guest order button
     *
     * @param Collector $Collector The collector object to append the guest order button to
     * @return void
     * @throws \Exception
     */
    public static function extendOrder(Collector $Collector): void
    {
        if (!GuestOrder::isActive()) {
            return;
        }

        if (!QUI::isFrontend()) {
            return;
        }

        $GuestInit = new GuestOrderButton();
        $Collector->append($GuestInit->create());
    }

    /**
     * Extend the checkout process for guest order account creation
     *
     * @param Collector $Collector - The collector object used to collect the output
     * @param mixed $User - The user object
     * @param mixed $Order - The order object
     * @return void
     */
    public static function extendCheckout(Collector $Collector, mixed $User, mixed $Order): void
    {
        if (QUI::getUsers()->isAuth(QUI::getUserBySession())) {
            return;
        }

        if (!GuestOrder::isActive()) {
            return;
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

    /**
     * Extends the mail content with links for guest orders (account creation / invoice creation)
     *
     * @param Collector $Collector - The mail content collector
     * @param AbstractOrder $Order - The order object
     * @param array $Articles - The order articles
     *
     * @return void
     * @throws Exception
     */
    public static function extendMail(Collector $Collector, AbstractOrder $Order, array $Articles)
    {
        if (!GuestOrder::isActive()) {
            return null;
        }

        // activated users do not need activation links
        $Customer = $Order->getCustomer();

        if ($Customer->getUUID()) {
            try {
                $User = QUI::getUsers()->get($Customer->getUUID());

                if ($User->isActive() && !($User instanceof GuestOrderUser)) {
                    return;
                }
            } catch (QUI\Exception) {
            }
        }

        $html = '<div style="margin-top: 20px; border: 1px solid #ddd; padding: 10px; background: #f8f8f8">';
        $invoiceLink = GuestOrder::getInvoiceCreationLink($Order);
        $createAccountLink = GuestOrder::getAccountCreationLink($Order);

        $guestInvoicing = QUI::getPackage('quiqqer/order-guestorder')->getConfig()->getValue(
            'guestorder',
            'invoicing_for_guests'
        );

        if (
            $guestInvoicing
            && GuestOrder::isAnonymousOrder()
            && QUI::getPackageManager()->isInstalled('quiqqer/invoice')
        ) {
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

        $Collector->append($html);
    }

    //endregion

    /**
     * Method: onRequestUserCreation
     *
     * Description:
     * This method is used to handle the creation of a user account for a guest order. It takes the order hash from the
     * request ($_REQUEST['o']) and retrieves the associated order. Then it checks if a user with the given username ($_REQUEST['u'])
     * or email exists. If not, it creates a new user using the provided email as the username and sends a new password mail.
     * If the user is already active, the method redirects to the main site. If the user is successfully created or the user
     * already exists, a success message is displayed.
     *
     * @return void
     * @throws Exception
     * @throws QUI\FrontendUsers\Exception
     * @throws UserAlreadyExistsException
     * @throws \PHPMailer\PHPMailer\Exception
     */
    protected static function onRequestUserCreation(): void
    {
        try {
            $Order = QUI\ERP\Order\Handler::getInstance()->getOrderByHash($_REQUEST['o']);
        } catch (\Exception) {
            self::redirectToMainSite();
        }

        $user = $_REQUEST['u'];
        $User = null;

        try {
            $User = QUI::getUsers()->getUserByName($user);
        } catch (QUI\Exception) {
        }

        if (!$User) {
            try {
                $User = QUI::getUsers()->getUserByMail($user);
            } catch (QUI\Exception) {
            }
        }

        $Customer = $Order->getCustomer();
        $email = $Customer->getAttribute('email');

        if ($email !== $user) {
            self::redirectToMainSite();
        }

        if (!$User) {
            // anonymous order - trigger frontend user registration
            $_POST['registration'] = true;
            $_POST['termsOfUseAccepted'] = true;
            $_POST['email'] = $email;

            $EmailRegistrar = new QUI\FrontendUsers\Registrars\Email\Registrar();
            $EmailRegistrar->setAttribute('email', $email);

            $Registration = new QUI\FrontendUsers\Controls\Registration();
            $Registration->setAttribute('Registrar', $EmailRegistrar);
            $Registration->register();

            $User = $Registration->getRegisteredUser();
            $Order->setCustomer($User);
            $Order->save(QUI::getUsers()->getSystemUser());

            GuestOrder::sendNewPasswordMail($User);

            self::setSiteContent(
                '<div class="messages message-success">' .
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.registration.password.info') .
                '</div>'
            );

            return;
        }

        if ($User->isActive()) {
            self::redirectToMainSite();
        }

        GuestOrder::sendNewPasswordMail($User);

        self::setSiteContent(
            '<div class="messages message-success">' .
            QUI::getLocale()->get('quiqqer/order-guestorder', 'message.registration.password.info') .
            '</div>'
        );
    }

    /**
     * Handle the request for invoice creation.
     *
     * If the 'quiqqer/invoice' package is not installed, redirects to the main site.
     * If the order cannot be retrieved or an exception occurs, redirects to the main site.
     * Checks the address data for any missing information, if none, creates an invoice for the order.
     * If the user is active, prompts them to log in and enter their address data.
     * If an exception occurs during the process, displays an error message.
     *
     * @return void
     * @throws Exception
     * @throws \Exception
     */
    protected static function onRequestInvoiceCreation(): void
    {
        if (!QUI::getPackageManager()->isInstalled('quiqqer/invoice')) {
            self::redirectToMainSite();
        }

        $order = $_REQUEST['o'];
        $user = $_REQUEST['u'];

        try {
            $Order = QUI\ERP\Order\Handler::getInstance()->getOrderByHash($order);
        } catch (\Exception) {
            self::redirectToMainSite();
        }

        $Customer = $Order->getCustomer();
        $email = $Customer->getAttribute('email');

        if ($email !== $user) {
            self::redirectToMainSite();
        }

        // check address
        $Address = $Order->getInvoiceAddress();
        $missing = QUI\ERP\Accounting\Invoice\Utils\Invoice::getMissingAddressData($Address->getAttributes());
        $User = null;

        try {
            $User = QUI::getUsers()->get($Customer->getUUID());
        } catch (QUI\Exception) {
        }

        if (!count($missing)) {
            if ($User) {
                $Order->setCustomer($User);
                $Order->setInvoiceAddress($Address);
                $Order->save(QUI::getUserBySession());
            }

            // alles passt, dann kann eine invoice angelegt werden
            $Order->createInvoice(QUI::getUserBySession());
            return;
        }

        // missing address data
        // guest order
        $AddressData = new QUI\ERP\Order\Guest\Controls\MissingAddressData([
            'Order' => $Order
        ]);

        self::setSiteContent($AddressData->create());
    }

    /**
     * Redirects the user to the main website
     *
     * @return never
     * @throws Exception
     */
    protected static function redirectToMainSite(): never
    {
        $Redirect = new RedirectResponse(QUI::getRewrite()->getProject()->getVHost(true, true));
        $Redirect->setStatusCode(Response::HTTP_SEE_OTHER);
        $Redirect->send();
        exit;
    }

    /**
     * Sets the content of the current site.
     *
     * @param string $content The content to be set.
     *
     * @return void
     * @throws Exception
     */
    protected static function setSiteContent(string $content): void
    {
        $Site = QUI::getRewrite()->getSite();
        $Site->setAttribute('short', '');
        $Site->setAttribute('type', 'standard');
        $Site->setAttribute('meta.canonical', $Site->getUrlRewrittenWithHost());
        $Site->setAttribute('quiqqer.bricks.areas', '');
        $Site->setAttribute('content', $content);
    }

    /**
     * Displays a site error message on the page
     *
     * @return void
     * @throws Exception
     */
    protected static function showSiteError(): void
    {
        self::setSiteContent(
            '<div class="messages message-error">' .
            QUI::getLocale()->get('quiqqer/order-guestorder', 'site.message.error') .
            '</div>'
        );
    }
}
