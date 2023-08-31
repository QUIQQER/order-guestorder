<?php


/**
 * @todo addresses
 * @todo company stuff
 */
class GuestOrderUser extends QUI\Users\Nobody implements QUI\Interfaces\Users\User
{

    /**
     * @todo
     */
    public function isCompany(): bool
    {
        return false;
    }

    public function isDeleted(): bool
    {
        return false;
    }

    public function isActive(): bool
    {
        return true;
    }
}
