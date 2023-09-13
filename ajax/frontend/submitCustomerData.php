<?php

/**
 * This file contains package_quiqqer_order-guestorder_ajax_frontend_setCustomerData
 */

use QUI\ERP\Accounting\Invoice\Utils\Invoice as InvoiceUtils;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\Handler;

QUI::$Ajax->registerFunction(
    'package_quiqqer_order-guestorder_ajax_frontend_submitCustomerData',
    function ($orderHash, $data) {
        $Order = Handler::getInstance()->getOrderByHash($orderHash);
        $data = json_decode($data, true);
        $Customer = $Order->getCustomer();
        $Guest = new GuestOrderUser();

        if (empty($data['order-guest-email'])) {
            throw new QUI\Exception('Missing E-Mail');
        }

        // this is only for anonymous orders
        if ($Customer->getId() !== $Guest->getId()) {
            return;
        }

        // check customer mail
        if ($Customer->getAttribute('email') !== $data['order-guest-email']) {
            throw new QUI\Exception('You are not allowed to edit this order');
        }

        // set address data
        $Address = $Order->getInvoiceAddress();
        $fields = ['company', 'salutation', 'firstname', 'lastname', 'zip', 'city', 'country'];

        foreach ($fields as $field) {
            if (!empty($data[$field])) {
                $Address->setAttribute($field, $data[$field]);
            }
        }

        // street
        $street = '';

        if (!empty($data['street'])) {
            $street = $data['street'];
        }

        if (!empty($data['street_number'])) {
            $street .= ' ' . $data['street_number'];
        }

        $street = trim($street);

        if (!empty($street)) {
            $Address->setAttribute('street_no', $street);
        }

        // phone, mobile, tel, fax stuff
        $Address->clearPhone();

        if (!empty($data['tel'])) {
            $Address->addPhone($data['tel']);
        }

        if (!empty($data['mobile'])) {
            $Address->addPhone([
                'no' => $data['mobile'],
                'type' => 'mobile'
            ]);
        }

        if (!empty($data['fax'])) {
            $Address->addPhone([
                'no' => $data['fax'],
                'type' => 'fax'
            ]);
        }

        $missing = InvoiceUtils::getMissingAddressData($Address->getAttributes());

        if (!empty($missing)) {
            throw new QUI\Exception(
                InvoiceUtils::getMissingAttributeMessage($missing[0])
            );
        }

        // all is fine, we can create the users
        $email = $Customer->getAttribute('email');

        try {
            $User = QUI::getUsers()->get($Customer->getId());

            if (!$User->isActive()) {
                $User = GuestOrder::triggerFrontendUsersRegistration($email);
            }
        } catch (QUI\Exception $exception) {
            $User = GuestOrder::createGuestAccount($email, $Address);
        }

        $Order->setCustomer($User);
        $Order->setInvoiceAddress($Address);
        $Order->save(QUI::getUsers()->getSystemUser());


        $guestInvoicing = QUI::getPackage('quiqqer/order-guestorder')->getConfig()->getValue(
            'guestorder',
            'invoicing_for_guests'
        );

        if ($guestInvoicing) {
            if ($Order->hasInvoice()) {
                $Invoice = $Order->getInvoice();
            } else {
                $Invoice = $Order->createInvoice(QUI::getUsers()->getSystemUser());
            }

            $Invoice->sendTo($email);
        }
    },
    ['orderHash', 'data']
);
