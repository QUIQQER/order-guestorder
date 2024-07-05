<?php

namespace QUI\ERP\Order\Guest;

use Exception;
use QUI;
use QUI\FrontendUsers\Exception\UserAlreadyExistsException;
use QUI\Mail\Mailer;
use QUI\Projects\Project;
use QUI\System\Log;
use QUI\Users\Address;
use QUI\Users\User;

use function http_build_query;

class GuestOrder
{
    const FLAG = 'guest-order-is-guest';
    const EMAIL = 'guest-order-email';

    const CUSTOMER_ID = 'guest-customer_id';

    /**
     * sets the flag, so we know if we are in a guest order
     *
     * @return void
     */
    public static function setGuestOrderFlag()
    {
        if (GuestOrder::isActive()) {
            QUI::getSession()->set(self::FLAG, 1);
        }
    }

    /**
     * remove the guest order flag
     * so, we are not in a guest order anymore
     */
    public static function removeGuestOrderFlag()
    {
        if (GuestOrder::isActive()) {
            QUI::getSession()->remove(self::FLAG);
        }
    }

    /**
     * Checks if the guest order feature is active
     *
     * @return bool Returns true if the guest order feature is active, false otherwise
     */
    public static function isActive(): bool
    {
        try {
            $Package = QUI::getPackage('quiqqer/order-guestorder');
            $type = $Package->getConfig()->getValue('guestorder', 'type');

            if (empty($type) || $type === 'no') {
                return false;
            }

            return true;
        } catch (QUI\Exception $exception) {
            Log::addError($exception->getMessage());
        }

        return false;
    }

    /**
     * Checks if the current order is an anonymous order
     *
     * @return bool Returns true if the current order is anonymous, false otherwise
     */
    public static function isAnonymousOrder(): bool
    {
        try {
            $Package = QUI::getPackage('quiqqer/order-guestorder');
            $type = $Package->getConfig()->getValue('guestorder', 'type');

            if (empty($type) || $type === 'no') {
                return false;
            }

            if ($type === 'anonymous') {
                return true;
            }
        } catch (QUI\Exception $exception) {
            Log::addError($exception->getMessage());
        }

        return false;
    }

    /**
     * Retrieves the link for invoice creation for a specific order
     *
     * @param QUI\ERP\Order\AbstractOrder $Order The order for which to get the invoice creation link
     *
     * @return string The invoice creation link
     * @throws QUI\Exception
     */
    public static function getInvoiceCreationLink(QUI\ERP\Order\AbstractOrder $Order): string
    {
        $DefaultProject = QUI::getProjectManager()->getStandard();
        $host = $DefaultProject->getVHost(true, true);
        $Customer = $Order->getCustomer();

        return $host . '/?' . http_build_query([
                'guestorder' => 1,
                't' => 'invoice',
                'u' => $Customer->getAttribute('email'),
                'o' => $Order->getHash()
            ]);
    }

    /**
     * Generates the account creation link for a given order
     *
     * @param QUI\ERP\Order\AbstractOrder $Order The order for which the account creation link is generated
     * @return string The account creation link
     * @throws QUI\Exception
     */
    public static function getAccountCreationLink(QUI\ERP\Order\AbstractOrder $Order): string
    {
        $DefaultProject = QUI::getProjectManager()->getStandard();
        $host = $DefaultProject->getVHost(true, true);
        $Customer = $Order->getCustomer();

        return $host . '/?' . http_build_query([
                'guestorder' => 1,
                't' => 'account',
                'u' => $Customer->getAttribute('email'),
                'o' => $Order->getHash()
            ]);
    }

    /**
     * Sends an email verification to the specified email address
     *
     * @param string $email - The email address to send the verification to
     * @param Project|null $Project - (Optional) The project to use for the email verification. If not specified, the current project will be used
     * @return void
     * @throws QUI\Exception
     * @throws QUI\Verification\Exception
     */
    public static function sendEmailVerification(string $email, QUI\Projects\Project $Project = null)
    {
        if ($Project === null) {
            $Project = QUI::getRewrite()->getProject();
        }

        $ActivationVerification = new EmailVerification($email, [
            'project' => $Project->getName(),
            'projectLang' => $Project->getLang()
        ]);

        $activationLink = QUI\Verification\Verifier::startVerification($ActivationVerification, true);
        $Formatter = QUI::getLocale()->getDateFormatter();

        $localeParams = [
            'email' => $email,
            'activationLink' => $activationLink,
            'date' => $Formatter->format(time())
        ];

        try {
            QUI::getMailManager()->send(
                $email,
                QUI::getLocale()->get(
                    'quiqqer/order-guestorder',
                    'mail.auth.subject',
                    $localeParams
                ),
                QUI::getLocale()->get('quiqqer/order-guestorder', 'mail.auth.body', $localeParams)
            );
        } catch (Exception $e) {
            QUI\System\Log::addError($e->getMessage());
            return;
        }
    }

    /**
     * Triggers frontend user registration process using email as the identifier.
     *
     * @param string $email The email address of the user to register.
     * @return User|null The registered user object or null if registration fails.
     * @throws QUI\Exception
     * @throws QUI\FrontendUsers\Exception
     * @throws UserAlreadyExistsException
     */
    public static function triggerFrontendUsersRegistration(string $email): ?User
    {
        // the user wanted an account after all, and he has checked the checkbox
        // we have to create an account via frontend users because of the mail auth stuff
        $_POST['registration'] = true;
        $_POST['termsOfUseAccepted'] = true;
        $_POST['email'] = $email;

        $EmailRegistrar = new QUI\FrontendUsers\Registrars\Email\Registrar();
        $EmailRegistrar->setAttribute('email', $email);

        $Registration = new QUI\FrontendUsers\Controls\Registration();
        $Registration->setAttribute('Registrar', $EmailRegistrar);
        $Registration->register();

        $User = $Registration->getRegisteredUser();

        try {
            if (QUI::getPackageManager()->isInstalled('quiqqer/customer')) {
                $User->addToGroup(QUI\ERP\Customer\Customers::getInstance()->getCustomerGroupId());
            }
        } catch (QUI\Exception $exception) {
        }

        $User->save(QUI::getUsers()->getSystemUser());

        return $User;
    }

    /**
     * Creates a guest user account with the given email and address
     *
     * @param string $email The email address of the guest user
     * @param Address $Address The address of the guest user
     *
     * @return User|null Returns the created guest user account or null if an error occurred
     * @throws QUI\Exception
     * @throws QUI\Permissions\Exception
     * @throws QUI\Users\Exception
     */
    public static function createGuestAccount(string $email, QUI\Users\Address $Address): ?User
    {
        $SystemUser = QUI::getUsers()->getSystemUser();

        // create user account -> guest user
        $User = QUI::getUsers()->createChild($email, $SystemUser);
        $DefaultAddr = $User->getStandardAddress();
        $DefaultAddr->setAttributes($Address->getAttributes());
        $DefaultAddr->save($SystemUser);

        $User->setAttribute('firstname', $DefaultAddr->getAttribute('firstname'));
        $User->setAttribute('lastname', $DefaultAddr->getAttribute('lastname'));
        $User->setAttribute('email', $email);

        try {
            if (QUI::getPackageManager()->isInstalled('quiqqer/customer')) {
                $User->addToGroup(QUI\ERP\Customer\Customers::getInstance()->getCustomerGroupId());
            }
        } catch (QUI\Exception) {
        }

        $User->save($SystemUser);

        return $User;
    }

    /**
     * Sends a new password email to the specified user
     *
     * @param \QUI\Interfaces\Users\User $User The user object to send the email to
     *
     * @return void
     * @throws \QUI\Exception
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public static function sendNewPasswordMail(QUI\Interfaces\Users\User $User)
    {
        // password mail and activation mail
        $newPassword = QUI\Security\Password::generateRandom();

        $User->setPassword($newPassword, QUI::getUsers()->getSystemUser());
        $User->setAttribute('quiqqer.set.new.password', true);
        $User->save(QUI::getUsers()->getSystemUser());

        if (!$User->isActive()) {
            $User->activate(false, QUI::getUsers()->getSystemUser());
        }

        // send mail
        $email = $User->getAttribute('email');

        $Mailer = new Mailer();
        $Mailer->addRecipient($email);

        $Mailer->setSubject(
            QUI::getLocale()->get('quiqqer/quiqqer', 'mails.user.new_password.subject')
        );

        $body = QUI::getLocale()->get('quiqqer/quiqqer', 'mails.user.new_password.body', [
            'name' => $User->getName(),
            'password' => $newPassword,
            'forceNewMsg' => QUI::getLocale()->get('quiqqer/quiqqer', 'mails.user.new_password.body.force_new')
        ]);

        $Mailer->setBody($body);
        $Mailer->send();
    }
}
