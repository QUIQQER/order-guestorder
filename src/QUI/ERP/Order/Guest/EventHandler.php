<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Guest\Controls\OrderGuestInit;
use QUI\ERP\Order\Settings;
use QUI\Smarty\Collector;

use function date;

class EventHandler
{
    /**
     * @return GuestOrderUser|null
     *
     * @todo prüfen ob nutzer eingeloggt ist (dann keine gast bestellung)
     */
    public static function onUserGetBySession(): ?GuestOrderUser
    {
        if (!QUI::isFrontend()) {
            return null;
        }

        //return null;
        return new GuestOrderUser();
    }

    public static function onUserGet(int $id)
    {
        if (!QUI::isFrontend()) {
            return null;
        }

        $Guest = new GuestOrderUser();

        if ($Guest->getId() === $id) {
            return $Guest;
        }

        return null;
    }

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

        $GuestInit = new OrderGuestInit();
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
