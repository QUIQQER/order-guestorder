<?php

/**
 * This file contains package_quiqqer_order-guestorder_ajax_frontend_emailAuth
 */

use QUI\ERP\Order\Guest\GuestOrder;

QUI::getAjax()->registerFunction(
    'package_quiqqer_order-guestorder_ajax_frontend_emailAuth',
    function ($email) {
        GuestOrder::sendEmailVerification($email);
    },
    ['email']
);
