<?php

namespace QUITests\Order\Guest;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Order\Guest\EventHandler;
use QUI\Projects\Site;
use QUI\Rewrite;

class EventHandlerSiteContentTest extends TestCase
{
    private Rewrite $originalRewrite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalRewrite = QUI::$Rewrite ?? QUI::getRewrite();
    }

    protected function tearDown(): void
    {
        QUI::$Rewrite = $this->originalRewrite;
        parent::tearDown();
    }

    public function testSetsSiteContentAndCanonicalUrl(): void
    {
        $attributes = [];
        $Site = $this->createMock(Site::class);
        $Site->method('setAttribute')->willReturnCallback(
            static function (string $name, mixed $value) use (&$attributes): void {
                $attributes[$name] = $value;
            }
        );
        $Site->method('getUrlRewrittenWithHost')->willReturn('https://example.test/phpunit');
        $this->useSite($Site);
        $Handler = $this->createHandlerProxy();

        $Handler::setContent('<p>PHPUnit content</p>');

        self::assertSame('', $attributes['short']);
        self::assertSame('standard', $attributes['type']);
        self::assertSame('', $attributes['quiqqer.bricks.areas']);
        self::assertSame('<p>PHPUnit content</p>', $attributes['content']);
        self::assertSame('https://example.test/phpunit', $attributes['meta.canonical']);
    }

    public function testShowsLocalizedSiteError(): void
    {
        $content = null;
        $Site = $this->createMock(Site::class);
        $Site->method('setAttribute')->willReturnCallback(
            static function (string $name, mixed $value) use (&$content): void {
                if ($name === 'content') {
                    $content = $value;
                }
            }
        );
        $Site->method('getUrlRewrittenWithHost')->willReturn('https://example.test/phpunit-error');
        $this->useSite($Site);
        $Handler = $this->createHandlerProxy();

        $Handler::showError();

        self::assertIsString($content);
        self::assertStringContainsString('message-error', $content);
    }

    private function useSite(Site $Site): void
    {
        $Rewrite = $this->createMock(Rewrite::class);
        $Rewrite->method('getSite')->willReturn($Site);
        QUI::$Rewrite = $Rewrite;
    }

    private function createHandlerProxy(): EventHandler
    {
        return new class () extends EventHandler {
            public static function setContent(string $content): void
            {
                parent::setSiteContent($content);
            }

            public static function showError(): void
            {
                parent::showSiteError();
            }
        };
    }
}
