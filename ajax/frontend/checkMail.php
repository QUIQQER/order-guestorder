<?php

/**
 * This file contains package_quiqqer_order-guestorder_ajax_frontend_checkMail
 */

QUI::$Ajax->registerFunction(
    'package_quiqqer_order-guestorder_ajax_frontend_checkMail',
    function ($email) {
        try {
            $User = QUI::getUsers()->getUserByName($email);

            if ($User->isActive()) {
                return true;
            }
        } catch (\Exception $exception) {
        }

        try {
            $User = QUI::getUsers()->getUserByMail($email);

            if ($User->isActive()) {
                return true;
            }
        } catch (\Exception $exception) {
        }

        return false;
    },
    ['email']
);
