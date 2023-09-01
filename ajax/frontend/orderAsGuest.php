<?php

/**
 * This file contains package_quiqqer_order-guestorder_ajax_frontend_orderAsGuest
 */

use QUI\ERP\Order\Guest\EventHandler;

QUI::$Ajax->registerFunction(
    'package_quiqqer_order-guestorder_ajax_frontend_orderAsGuest',
    function ($email) {
        QUI::getSession()->set(EventHandler::EMAIL, $email);
        EventHandler::setGuestOrderFlag();
    },
    ['email']
);
