<?php

/**
 * This file contains package_quiqqer_order-guestorder_ajax_frontend_checkMail
 */

/**
 * 1 = user exists and is active
 * 0 = user exists and is not active
 * -1 = no user exists
 */

QUI::getAjax()->registerFunction(
    'package_quiqqer_order-guestorder_ajax_frontend_checkMail',
    function ($email) {
        if (
            QUI::getPackage('quiqqer/order-guestorder')
                ->getConfig()
                ->get('guestorder', 'prevent_duplicate_guest_order_registration')
        ) {
            return -1;
        }

        try {
            $User = QUI::getUsers()->getUserByName($email);

            if ($User->isActive()) {
                return 1;
            }

            return 0;
        } catch (Exception) {
        }

        try {
            $User = QUI::getUsers()->getUserByMail($email);

            if ($User->isActive()) {
                return 1;
            }

            return 0;
        } catch (Exception) {
        }

        return -1;
    },
    ['email']
);
