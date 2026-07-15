<?php

namespace QUITests\Order\Guest;

use QUI;
use QUI\Projects\Project;
use QUI\Projects\Site\Edit;
use QUI\Verification\Utils;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class VerificationSiteFixture
{
    private static ?Project $Project = null;
    private static ?int $siteId = null;

    public static function setUp(): void
    {
        $Project = QUI::getRewrite()->getProject() ?? QUI::getProjectManager()->getStandard();

        if (!$Project) {
            throw new RuntimeException('A project is required for the verification test fixture.');
        }

        $siteIds = $Project->getSitesIds([
            'where' => [
                'type' => Utils::SITE_TYPE_VERIFIER
            ]
        ]);

        if (!empty($siteIds)) {
            return;
        }

        self::$Project = $Project;
        register_shutdown_function([self::class, 'tearDown']);
        self::withSystemUser(static function () use ($Project): void {
            $Root = $Project->firstChild()->getEdit();

            if (!$Root) {
                throw new RuntimeException('The project root site is not editable.');
            }

            $siteId = $Root->createChild(
                [
                    'name' => 'phpunit-order-guestorder-verifier-' . bin2hex(random_bytes(6)),
                    'title' => 'PHPUnit Order Guestorder Verifier'
                ],
                [],
                QUI::getUsers()->getSystemUser()
            );
            self::$siteId = $siteId;
            $VerifierSite = new Edit($Project, $siteId);
            $VerifierSite->setAttribute('type', Utils::SITE_TYPE_VERIFIER);
            $VerifierSite->save(QUI::getUsers()->getSystemUser());
            $VerifierSite->activate(QUI::getUsers()->getSystemUser());
        });
    }

    public static function tearDown(): void
    {
        if (!self::$Project || self::$siteId === null) {
            return;
        }

        try {
            self::withSystemUser(static function (): void {
                if (!self::$Project || self::$siteId === null) {
                    return;
                }

                $VerifierSite = new Edit(self::$Project, self::$siteId);
                $VerifierSite->delete();
                (new Edit(self::$Project, self::$siteId))->destroy();
            });
        } catch (Throwable) {
        } finally {
            self::$Project = null;
            self::$siteId = null;
        }
    }

    private static function withSystemUser(callable $callback): void
    {
        $Users = QUI::getUsers();
        $SessionUser = new ReflectionProperty($Users, 'Session');
        $originalUser = $SessionUser->getValue($Users);

        try {
            $SessionUser->setValue($Users, $Users->getSystemUser());
            $callback();
        } finally {
            $SessionUser->setValue($Users, $originalUser);
        }
    }
}
