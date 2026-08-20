<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The three OAuth screens share one document shell.
 *
 * They used to carry a copy of the CSS each, which is how the consent form and the code
 * page ended up with slightly different cards. These tests pin the shell down, because a
 * copy is the kind of thing that reappears quietly the next time a page is added.
 */
class OAuthPageShellTest extends AbstractFunctionalTest
{
    /**
     * @return array<string, array{string}>
     */
    public static function pages(): array
    {
        return [
            'consent' => ['consent'],
            'code display' => ['code'],
            'notice' => ['notice'],
        ];
    }

    public function testAllThreeScreensShareAByteIdenticalStyleBlock(): void
    {
        $styles = array_map(
            fn (string $page): string => $this->styleBlockOf($this->render($page)),
            ['consent', 'code', 'notice']
        );

        self::assertNotSame('', $styles[0], 'No style block found at all');
        self::assertSame($styles[0], $styles[1], 'The code page has its own copy again');
        self::assertSame($styles[0], $styles[2], 'The notice page has its own copy again');
    }

    /**
     * A value defined only inside the media query leaves light mode unstyled, and one
     * defined only outside it leaves dark mode with light colours. Both halves have to
     * exist for every page.
     */
    #[DataProvider('pages')]
    public function testEachScreenDefinesBothColourSchemes(string $page): void
    {
        $style = $this->styleBlockOf($this->render($page));

        [$light, $dark] = explode('@media (prefers-color-scheme: dark)', $style, 2) + [1 => ''];
        self::assertNotSame('', $dark, 'No dark scheme defined');

        self::assertStringContainsString(':root {', $light);
        self::assertStringContainsString('--bg:', $light);
        self::assertStringContainsString('--bg:', $dark);
        self::assertStringContainsString('--text:', $light);
        self::assertStringContainsString('--text:', $dark);
    }

    /**
     * The endpoint answers before TYPO3 resolves a site, so there is no reliable base URL
     * for assets — anything external would simply fail to load.
     */
    #[DataProvider('pages')]
    public function testScreensReferenceNoExternalAssets(string $page): void
    {
        $html = $this->render($page);

        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('href="http', $html);
        self::assertStringNotContainsString('src="http', $html);
    }

    /**
     * Colours have to come from the shared tokens, otherwise a page starts drifting again
     * one literal at a time — and a hardcoded colour is invisible in dark mode.
     */
    #[DataProvider('pages')]
    public function testScreensStyleThemselvesFromTokensOnly(string $page): void
    {
        $style = $this->styleBlockOf($this->render($page));
        // Strip the two :root blocks — that is the one place literals belong.
        $rules = preg_replace('/:root\s*\{[^}]*\}/', '', $style) ?? '';

        self::assertDoesNotMatchRegularExpression(
            '/#[0-9a-fA-F]{3,6}\b/',
            $rules,
            'A colour literal escaped the token block'
        );
    }

    private function render(string $page): string
    {
        $endpoint = new OAuthAuthorizeEndpoint();
        $invoke = function (string $name, array $args) use ($endpoint): string {
            $method = new \ReflectionMethod($endpoint, $name);
            $method->setAccessible(true);
            return $method->invokeArgs($endpoint, $args);
        };

        return match ($page) {
            'consent' => $invoke('generateConsentTemplate', [[
                'site_name' => 'LOUIS INTERNET',
                'host' => 'staging.example.test',
                'username' => 'tester',
                'client_name' => 'Claude',
                'client_id' => 'mcp_1',
                'redirect_uri' => 'https://client.example/cb',
                'code_challenge' => 'chal',
                'code_challenge_method' => 'S256',
                'state' => 'abc123',
                'csrf_token' => 'csrf',
            ]]),
            'code' => $invoke('generateCodeDisplayTemplate', ['mcpc_abc', 'Claude']),
            'notice' => $invoke('generateNoticeTemplate', [
                'Authorization failed',
                'Authorization failed',
                'redirect_uri is not registered for this client',
                'invalid_request',
            ]),
        };
    }

    private function styleBlockOf(string $html): string
    {
        preg_match('#<style>(.*?)</style>#s', $html, $matches);

        return $matches[1] ?? '';
    }
}
