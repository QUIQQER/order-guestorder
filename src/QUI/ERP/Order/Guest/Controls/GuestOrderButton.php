<?php

namespace QUI\ERP\Order\Guest\Controls;

use QUI;

use function dirname;

class GuestOrderButton extends QUI\Control
{
    public function __construct($attributes = [])
    {
        $this->setAttributes([
            'data-qui' => 'package/quiqqer/order-guestorder/bin/frontend/controls/GuestOrderButton'
        ]);

        $this->addCSSFile(dirname(__FILE__) . '/GuestOrderButton.css');
        $this->addCSSClass('guest-order-login');

        parent::__construct($attributes);
    }

    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        return $Engine->fetch(dirname(__FILE__) . '/GuestOrderButton.html');
    }
}
