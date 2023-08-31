<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\Exception;

use function json_decode;

/**
 * @todo addresses
 * @todo company stuff
 */
class GuestOrderUser extends QUI\Users\Nobody implements QUI\Interfaces\Users\User
{

    public function getId(): int
    {
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

    //region setter

    public function setCompanyStatus($status = false)
    {
        $this->setAttribute('isCompany', $status);
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

    //endregion
}
