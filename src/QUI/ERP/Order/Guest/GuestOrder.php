<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\System\Log;

class GuestOrder
{
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
}
