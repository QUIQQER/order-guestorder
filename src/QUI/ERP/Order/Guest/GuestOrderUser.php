<?php

namespace QUI\ERP\Order\Guest;

use QUI;
use QUI\Exception;

use function json_decode;

/**
 */
class GuestOrderUser extends QUI\Users\Nobody implements QUI\Interfaces\Users\User
{
    /**
     * @return QUI\Session|QUI\System\Console\Session|null
     */
    protected function getSessionInstance(): QUI\Session | QUI\System\Console\Session | null
    {
        return QUI::getSession();
    }

    public function __construct()
    {
        parent::__construct();

        $firstname = $this->getAttribute('firstname');

        if (empty($firstname)) {
            $this->setAttribute('firstname', 'Gast');
        }

        $this->readEmail();
    }

    protected function readEmail(): void
    {
        if (empty($this->getAttribute('email'))) {
            $email = $this->getSessionInstance()?->get(GuestOrder::EMAIL);

            if (!empty($email)) {
                $this->setAttribute('email', $email);
            }
        }
    }

    public function getId(): int
    {
        $customerId = (int)($this->getSessionInstance()?->get(GuestOrder::CUSTOMER_ID) ?? 0);

        if ($customerId) {
            return $customerId;
        }

        return 6;
    }

    public function getUUID(): string | int
    {
        $Session = $this->getSessionInstance();

        if (!$Session) {
            return 6;
        }

        if (!$Session->get(GuestOrder::CUSTOMER_UUID)) {
            $Session->set(
                GuestOrder::CUSTOMER_UUID,
                QUI\Utils\Uuid::get()
            );
        }

        return $Session->get(GuestOrder::CUSTOMER_UUID);
    }

    public function getUniqueId(): string
    {
        return $this->getGuestOrderId();
    }

    public function getGuestOrderId(): string
    {
        $Session = $this->getSessionInstance();

        if (!$Session) {
            return QUI\Utils\Uuid::get();
        }

        $orderGuestId = $Session->get('guest-order-id');

        if (!empty($orderGuestId)) {
            return $orderGuestId;
        }

        $orderGuestId = QUI\Utils\Uuid::get();
        $Session->set('guest-order-id', $orderGuestId);

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

    public function setCompanyStatus(bool $status = false): void
    {
        $this->setAttribute('isCompany', $status);
    }

    public function logout(): void
    {
        $this->getSessionInstance()?->destroy();
    }

    //endregion

    //region address

    public function getAddress(int | string $id): QUI\ERP\Address
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

        $addressInstance = new QUI\ERP\Address($address, $this);

        try {
            $this->readEmail();
            $addressInstance->addMail($this->getAttribute('email'));
        } catch (QUI\Exception $e) {
            QUI\System\Log::addError($e->getMessage());
        }

        return $addressInstance;
    }

    //endregion

    //region getter

    /**
     * @return array<string, mixed>|false
     */
    protected function getGuestOrderData(): false | array
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
        } catch (Exception) {
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
