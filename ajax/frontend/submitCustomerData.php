<?php

/**
 * This file contains package_quiqqer_order-guestorder_ajax_frontend_setCustomerData
 */

use QUI\ERP\Accounting\Invoice\InvoiceTemporary;
use QUI\ERP\Accounting\Invoice\Utils\Invoice as InvoiceUtils;
use QUI\ERP\Order\Guest\GuestOrder;
use QUI\ERP\Order\Guest\GuestOrderUser;
use QUI\ERP\Order\Handler;
use QUI\System\Log;

QUI::getAjax()->registerFunction(
    'package_quiqqer_order-guestorder_ajax_frontend_submitCustomerData',
    function ($orderHash, $data) {
        $Order = Handler::getInstance()->getOrderByHash($orderHash);
        $data = json_decode($data, true);
        $Customer = $Order->getCustomer();
        $Guest = new GuestOrderUser();

        if (GuestOrder::isNobodyCustomer($Customer)) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
            );
        }

        if (empty($data['order-guest-email'])) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
            );
        }

        // this is only for anonymous orders
        if ($Customer->getUUID() !== $Guest->getUUID()) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
            );
        }

        // check customer mail
        if ($Customer->getAttribute('email') !== $data['order-guest-email']) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
            );
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

        if (class_exists('QUI\ERP\Accounting\Invoice\Utils\Invoice')) {
            $missing = InvoiceUtils::getMissingAddressData($Address->getAttributes());

            if (!empty($missing)) {
                throw new QUI\Exception(
                    InvoiceUtils::getMissingAttributeMessage($missing[0])
                );
            }
        }


        // all is fine, we can create the users
        $email = $Customer->getAttribute('email');
        $message = '<p>' .
            QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.thanks') .
            '</p>';

        try {
            $User = QUI::getUsers()->get($Customer->getUUID());

            if ($User instanceof GuestOrderUser && !empty($data['guest-order-create-account'])) {
                $User = GuestOrder::triggerFrontendUsersRegistration($email);
                $message .= '<p>' .
                    QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.accountCreation') .
                    '</p>';

                if (!$User) {
                    throw new QUI\Exception(
                        QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
                    );
                }

                // frontend users don't set a password
                GuestOrder::sendNewPasswordMail($User);
            } else {
                $User = GuestOrder::createGuestAccount($email, $Address);
            }
        } catch (QUI\Exception $exception) {
            Log::addNotice($exception->getMessage());

            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
            );
        }

        if (!$User) {
            throw new QUI\Exception(
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
            );
        }

        $Order->setCustomer($User);
        $Order->setInvoiceAddress($Address);
        $Order->save(QUI::getUsers()->getSystemUser());


        $guestInvoicing = QUI::getPackage('quiqqer/order-guestorder')
            ->getConfig()?->getValue('guestorder', 'invoicing_for_guests');

        if (
            $guestInvoicing
            && class_exists('QUI\ERP\Accounting\Invoice\InvoiceTemporary')
            && class_exists('QUI\ERP\Accounting\Invoice\Invoice')
        ) {
            if ($Order->hasInvoice()) {
                $Invoice = $Order->getInvoice();
            } else {
                if (!($Order instanceof QUI\ERP\Order\Order)) {
                    throw new QUI\Exception(
                        QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.error')
                    );
                }

                $Invoice = $Order->createInvoice(QUI::getUsers()->getSystemUser());
            }

            if ($Invoice instanceof InvoiceTemporary) {
                $Invoice = $Invoice->post(QUI::getUsers()->getSystemUser());
            }

            $Invoice->sendTo($email);

            $message .= '<p>' .
                QUI::getLocale()->get('quiqqer/order-guestorder', 'message.guest.sendInvoice.invoiceSuccessful') .
                '</p>';
        }

        return $message;
    },
    ['orderHash', 'data']
);
