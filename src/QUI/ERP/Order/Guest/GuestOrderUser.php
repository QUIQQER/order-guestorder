<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\Exception;

use function json_decode;

/**
 */
class GuestOrderUser extends QUI\Users\Nobody implements QUI\Interfaces\Users\User
{
    public function __construct()
    {
        parent::__construct();

        $firstname = $this->getAttribute('firstname');

        if (empty($firstname)) {
            $this->setAttribute('firstname', 'Gast');
        }

        if (!$this->getAttribute('email')) {
            $email = QUI::getSession()->get(GuestOrder::EMAIL);

            if (!empty($email)) {
                $this->setAttribute('email', $email);
            }
        }
    }

    public function getId(): int
    {
        if ((int)QUI::getSession()->get(GuestOrder::CUSTOMER_ID)) {
            return (int)QUI::getSession()->get(GuestOrder::CUSTOMER_ID);
        }

        return 6;
    }

    public function getUniqueId(): string
    {
        return $this->getGuestOrderId();
    }

    public function getGuestOrderId(): string
    {
        $orderGuestId = QUI::getSession()->get('guest-order-id');

        if (!empty($orderGuestId)) {
            return $orderGuestId;
        }

        $orderGuestId = QUI\Utils\Uuid::get();
        QUI::getSession()->set('guest-order-id', $orderGuestId);

        return $orderGuestId;
    }

    /**
     * (non-PHPdoc)
     *
     * @return string
     * @see \QUI\Interfaces\Users\User::getUsername()
     */
    public function getUsername(): string
    {
        $username = QUI::getLocale()->get('quiqqer/order-guestorder', 'guest.username');

        if ($this->getAttribute('email')) {
            $username .= ':' . $this->getAttribute('email');
        }

        return $username;
    }

    //region setter

    public function setCompanyStatus($status = false)
    {
        $this->setAttribute('isCompany', $status);
    }

    public function logout()
    {
        QUI::getSession()->destroy();
    }

    //endregion

    //region address

    public function getAddress($id): QUI\ERP\Address
    {
        return $this->getStandardAddress();
    }

    public function getStandardAddress(): QUI\ERP\Address
    {
        $data = $this->getGuestOrderData();
        $address = [];

        if ($data && !empty($data['addressInvoice'])) {
            $addressInvoice = json_decode($data['addressInvoice'], true);

            if (isset($addressInvoice['firstname']) && $addressInvoice['firstname']) {
                $address = $addressInvoice;
            }
        }

        if ($data && empty($address) && !empty($data['addressDelivery'])) {
            $addressDelivery = json_decode($data['addressDelivery'], true);

            if (isset($addressDelivery['firstname']) && $addressDelivery['firstname']) {
                $address = $addressDelivery;
            }
        }

        return new QUI\ERP\Address($address, $this);
    }

    //endregion

    //region getter

    protected function getGuestOrderData()
    {
        $Handler = QUI\ERP\Order\Handler::getInstance();
        $guestId = $this->getGuestOrderId();

        try {
            $result = QUI::getDataBase()->fetch([
                'from' => $Handler->tableOrderProcess(),
                'where' => [
                    'guestOrder' => $guestId
                ],
                'limit' => 1
            ]);
        } catch (Exception $exception) {
            return false;
        }

        if (!empty($result[0])) {
            return $result[0];
        }

        return false;
    }

    public function isCompany(): bool
    {
        if ($this->getAttribute('isCompany')) {
            return true;
        }

        $Address = $this->getStandardAddress();

        if ($Address->getAttribute('company')) {
            return true;
        }

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

    //endregion
}
