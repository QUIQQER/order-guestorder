<?php

namespace QUI\ERP\Order\Guest\Controls;

use QUI;

use function dirname;

class OrderGuestInit extends QUI\Control
{
    public function getBody(): string
    {
        $Engine = QUI::getTemplateManager()->getEngine();

        return $Engine->fetch(dirname(__FILE__) . '/OrderGuestInit.html');
    }
}
