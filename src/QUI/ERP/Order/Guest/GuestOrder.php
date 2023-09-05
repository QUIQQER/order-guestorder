<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\System\Log;

class GuestOrder
{
    /**
     * @return bool
     */
    public static function isActive(): bool
    {
        try {
            $Package = QUI::getPackage('quiqqer/order-guestorder');
            $type = $Package->getConfig()->getValue('guestorder', 'type');

            if (empty($type) || $type === 'no') {
                return false;
            }

            return true;
        } catch (QUI\Exception $exception) {
            Log::addError($exception->getMessage());
        }

        return false;
    }

    /**
     * @return bool
     */
    public static function isAnonymousOrder(): bool
    {
        try {
            $Package = QUI::getPackage('quiqqer/order-guestorder');
            $type = $Package->getConfig()->getValue('guestorder', 'type');

            if (empty($type) || $type === 'no') {
                return false;
            }

            if ($type === 'anonymous') {
                return true;
            }
        } catch (QUI\Exception $exception) {
            Log::addError($exception->getMessage());
        }

        return false;
    }

    public static function getInvoiceCreationLink(QUI\ERP\Order\AbstractOrder $Order): string
    {
        $DefaultProject = QUI::getProjectManager()->getStandard();
        $host = $DefaultProject->getVHost(true, true);

        return $host . '/?guestorder=1&t=invoice&o=' . $Order->getHash();
    }

    public static function getAccountCreationLink(QUI\ERP\Order\AbstractOrder $Order): string
    {
        $DefaultProject = QUI::getProjectManager()->getStandard();
        $host = $DefaultProject->getVHost(true, true);
        $Customer = $Order->getCustomer();

        return $host . '/?guestorder=1&t=account&u=' . $Customer->getAttribute('email');
    }
}
