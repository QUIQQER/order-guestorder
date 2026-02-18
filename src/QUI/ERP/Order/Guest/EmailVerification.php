<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\Verification\Entity\LinkVerification;
use QUI\Verification\Enum\VerificationErrorReason;

/**
 * Wenn doppelt bestellt wurde,
 * muss die Mail verifiziert werden
 */
class EmailVerification extends QUI\FrontendUsers\EmailVerification
{
    public function onSuccess(LinkVerification $verification): void
    {
        GuestOrder::setGuestOrderFlag();
    }

    public function onError(LinkVerification $verification, VerificationErrorReason $reason): void
    {
    }

    public function getSuccessMessage(LinkVerification $verification): string
    {
        try {
            $Project = QUI::getRewrite()->getProject();

            if (!$Project) {
                throw new QUI\Exception('No project available');
            }

            $orderLink = QUI\ERP\Order\Utils\Utils::getOrderProcess($Project)->getUrlRewritten();
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

    public function getErrorMessage(LinkVerification $verification, $reason): string
    {
        return QUI::getLocale()->get('quiqqer/frontend-users', 'message.registration_error');
    }
}
