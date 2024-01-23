<?php

/**
 * This file contains package_quiqqer_order-guestorder_ajax_frontend_orderAsGuest
 */

use QUI\ERP\Order\Guest\GuestOrder;

QUI::$Ajax->registerFunction(
    'package_quiqqer_order-guestorder_ajax_frontend_orderAsGuest',
    function ($email) {
        $alwaysGuestAllowed = QUI::getPackage('quiqqer/order-guestorder')
            ->getConfig()
            ->get('guestorder', 'prevent_duplicate_guest_order_registration');

        if ($alwaysGuestAllowed) {
            // set guest session
            QUI::getSession()->set(GuestOrder::EMAIL, $email);
            GuestOrder::setGuestOrderFlag();
            return;
        }

        try {
            $User = QUI::getUsers()->getUserByName($email);

            if ($User->isActive()) {
                return;
            }
        } catch (\Exception) {
        }

        try {
            $User = QUI::getUsers()->getUserByMail($email);

            if ($User->isActive()) {
                return;
            }
        } catch (\Exception) {
        }

        // set guest session
        QUI::getSession()->set(GuestOrder::EMAIL, $email);
        GuestOrder::setGuestOrderFlag();
    },
    ['email']
);
