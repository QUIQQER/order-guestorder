<?php

namespace QUI\ERP\Order\Guest;

use QUI\Verification\AbstractVerification;

/**
 * Wenn doppelt bestellt wurde
 * Muss die Mail verifiziert werden
 */
class MailVerification extends AbstractVerification
{

    public function onSuccess()
    {
        // TODO: Implement onSuccess() method.
    }

    public function onError()
    {
        // TODO: Implement onError() method.
    }

    public function getSuccessMessage()
    {
        // TODO: Implement getSuccessMessage() method.
    }

    public function getErrorMessage($reason)
    {
        // TODO: Implement getErrorMessage() method.
    }
}
