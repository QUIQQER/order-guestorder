<?php

namespace QUI\ERP\Order\Guest\Controls;

use QUI;
use QUI\ERP\Order\AbstractOrder;

class MissingAddressData extends QUI\Control
{
    /**
     * @param array $attributes
     */
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

    /**
     * @return string
     * @throws QUI\Exception
     */
    public function getBody(): string
    {
        $Order = $this->getAttribute('Order');

        if (!($Order instanceof AbstractOrder)) {
            return '';
        }

        $Engine = QUI::getTemplateManager()->getEngine();
        $Address = $Order->getInvoiceAddress();
        $missing = QUI\ERP\Accounting\Invoice\Utils\Invoice::getMissingAddressData($Address->getAttributes());

        $CustomerData = new QUI\ERP\Order\Controls\OrderProcess\CustomerData([
            'Order' => $Order,
            'businessTypeIsChangeable' => false
        ]);

        $Customer = $Order->getCustomer();
        $Guest = new QUI\ERP\Order\Guest\GuestOrderUser();

        $Engine->assign([
            'orderHash' => $Order->getHash(),
            'email' => $Order->getCustomer()->getAttribute('email'),
            'missing' => $missing,
            'CustomerData' => $CustomerData,
            'showAccountCreation' => $Customer->getId() === $Guest->getId()
        ]);

        return $Engine->fetch(dirname(__FILE__) . '/MissingAddressData.html');
    }
}
