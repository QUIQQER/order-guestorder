<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Guest\Controls\GuestOrderButton;
use QUI\ERP\Order\Settings;
use QUI\Smarty\Collector;

use function date;

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
        QUI::getSession()->set(self::FLAG, 1);
    }

    /**
     * remove the guest order flag
     * so, we are not in a guest order anymore
     */
    public static function removeGuestOrderFlag()
    {
        QUI::getSession()->remove(self::FLAG, 1);
    }

    /**
     * event that hooks into the onUserGetBySession.
     * if we are in a guest order, this event returns a GuestOrderUser object to the user handler
     *
     * @return GuestOrderUser|null
     */
    public static function onUserGetBySession(): ?GuestOrderUser
    {
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

        if (empty($_REQUEST['guest-order-create-account'])) {
            // create normal account
            $email = QUI::getSession()->get(self::EMAIL);

            // user already exists
            if (QUI::getUsers()->usernameExists($email)) {
                $User = QUI::getUsers()->getUserByName($email);
            } else {
                $User = QUI::getUsers()->createChild($email, QUI::getUsers()->getSystemUser());
                $Address = $User->getStandardAddress();
            }

            $Order->setCustomer($User);
            $Order->save();

            return;
        }
        // create account via frontend users
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

    //region extend templates

    public static function extendOrder(Collector $Collector)
    {
        if (!QUI::isFrontend()) {
            return null;
        }

        $GuestInit = new GuestOrderButton();
        $Collector->append($GuestInit->create());
    }

    public static function extendCheckout(Collector $Collector, $User, $Order)
    {
        if (!QUI::isFrontend()) {
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

    //endregion
}
