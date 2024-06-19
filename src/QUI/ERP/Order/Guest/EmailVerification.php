<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\Verification\AbstractVerification;

/**
 * Wenn doppelt bestellt wurde
 * Muss die Mail verifiziert werden
 */
class EmailVerification extends AbstractVerification
{
    public function onSuccess(): void
    {
        GuestOrder::setGuestOrderFlag();
    }

    public function onError(): void
    {
    }

    public function getSuccessMessage(): string
    {
        try {
            $orderLink = QUI\ERP\Order\Utils\Utils::getOrderProcess(QUI::getRewrite()->getProject())->getUrlRewritten();
        } catch (QUI\Exception) {
            $orderLink = '/';
        }

        return QUI::getLocale()->get('quiqqer/order-guestorder', 'message.registration_success') .
            "<script>
            setTimeout(function() { 
                window.location = '$orderLink';
            }, 5000);
            </script>";
    }

    public function getErrorMessage($reason): string
    {
        return QUI::getLocale()->get('quiqqer/frontend-users', 'message.registration_error');
    }
}
