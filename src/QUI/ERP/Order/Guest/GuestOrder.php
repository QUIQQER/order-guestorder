<?php

namespace QUI\ERP\Order\Guest;

use Exception;
use QUI;
use QUI\System\Log;

use function http_build_query;

class GuestOrder
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
        $Customer = $Order->getCustomer();

        return $host . '/?' . http_build_query([
                'guestorder' => 1,
                't' => 'invoice',
                'u' => $Customer->getAttribute('email'),
                'o' => $Order->getHash()
            ]);
    }

    public static function getAccountCreationLink(QUI\ERP\Order\AbstractOrder $Order): string
    {
        $DefaultProject = QUI::getProjectManager()->getStandard();
        $host = $DefaultProject->getVHost(true, true);
        $Customer = $Order->getCustomer();

        return $host . '/?' . http_build_query([
                'guestorder' => 1,
                't' => 'account',
                'u' => $Customer->getAttribute('email'),
                'o' => $Order->getHash()
            ]);
    }

    public static function sendEmailVerification($email, $Project = null)
    {
        if ($Project === null) {
            $Project = QUI::getRewrite()->getProject();
        }

        $ActivationVerification = new EmailVerification($email, [
            'project' => $Project->getName(),
            'projectLang' => $Project->getLang()
        ]);

        $activationLink = QUI\Verification\Verifier::startVerification($ActivationVerification, true);
        $Formatter = QUI::getLocale()->getDateFormatter();

        $localeParams = [
            'email' => $email,
            'activationLink' => $activationLink,
            'date' => $Formatter->format(time())
        ];

        try {
            QUI::getMailManager()->send(
                $email,
                QUI::getLocale()->get(
                    'quiqqer/order-guestorder',
                    'mail.auth.subject',
                    $localeParams
                ),
                QUI::getLocale()->get('quiqqer/order-guestorder', 'mail.auth.body', $localeParams)
            );
        } catch (QUI\Exception|Exception $e) {
            QUI\System\Log::addError($e->getMessage());
            return;
        }
    }
}
