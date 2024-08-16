<?php

namespace QUI\ERP\Order\Guest\Controls;

use QUI;
use QUI\ERP\Order\AbstractOrder;

use function class_exists;

class MissingAddressData extends QUI\Control
{
    public function __construct(array $attributes = [])
    {
        $this->setAttributes([
            'Order' => false
        ]);

        $this->addCSSFile(dirname(__FILE__) . '/MissingAddressData.css');
        $this->addCSSClass('guest-order-address');
        $this->setJavaScriptControl('package/quiqqer/order-guestorder/bin/frontend/controls/MissingAddressData');

        parent::__construct($attributes);
    }

    public function getBody(): string
    {
        $Order = $this->getAttribute('Order');

        if (!($Order instanceof AbstractOrder)) {
            return '';
        }

        $Engine = QUI::getTemplateManager()->getEngine();
        $Address = $Order->getInvoiceAddress();
        $missing = [];

        if (class_exists('QUI\ERP\Accounting\Invoice\Utils\Invoice')) {
            $missing = QUI\ERP\Accounting\Invoice\Utils\Invoice::getMissingAddressData($Address->getAttributes());
        }

        $CustomerData = new QUI\ERP\Order\Controls\OrderProcess\CustomerData([
            'Order' => $Order,
            'businessTypeIsChangeable' => false
        ]);

        $Customer = $Order->getCustomer();
        $Guest = new QUI\ERP\Order\Guest\GuestOrderUser();

        $Engine->assign([
            'orderHash' => $Order->getUUID(),
            'email' => $Order->getCustomer()->getAttribute('email'),
            'missing' => $missing,
            'CustomerData' => $CustomerData,
            'showAccountCreation' => $Customer->getUUID() === $Guest->getUUID()
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/MissingAddressData.html');
    }
}
