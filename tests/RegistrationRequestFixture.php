<?php

namespace QUITests\Order\Guest;

use QUI;

final class RegistrationRequestFixture
{
    public static function run(string $email, callable $callback): mixed
    {
        $Server = QUI::getRequest()->server;
        $originalServer = $Server->all();
        $ip = '2001:db8::' . bin2hex(random_bytes(2)) . ':' . bin2hex(random_bytes(2));

        try {
            $Server->set('REMOTE_ADDR', $ip);

            return $callback();
        } finally {
            $Server->replace($originalServer);

            if (class_exists(QUI\FrontendUsers\RegistrationThrottle::class)) {
                foreach (['ip:' . bin2hex(inet_pton($ip)), 'email:' . $email, 'username:' . $email] as $subject) {
                    QUI::getDataBaseConnection()->delete(
                        QUI\FrontendUsers\RegistrationThrottle::table(),
                        ['subject_key' => hash('sha256', $subject)]
                    );
                }
            }
        }
    }
}
