<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The consent screen has to let the user decide, which means naming what they are
 * deciding about and honouring a refusal.
 */
class OAuthConsentScreenTest extends AbstractFunctionalTest
{
    private const HOST = 'stage.example.com';

    private OAuthService $service;
    private array $client;
    private mixed $previousRequest;
    private mixed $previousSiteName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = GeneralUtility::makeInstance(OAuthService::class);
        $this->client = $this->service->registerClient([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://client.example/oauth/backend/callback'],
        ]);
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->previousSiteName = $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = 'LOUIS INTERNET';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = $this->previousSiteName;
        parent::tearDown();
    }

    /**
     * A fleet of similarly-named environments sits behind one MCP connector, so
     * "Authorize MCP Access" on its own withholds the fact the decision turns on. The host
     * is the discriminator and the one thing the user can check against their address bar.
     */
    public function testConsentScreenNamesTheInstallation(): void
    {
        $body = (string)$this->showConsentForm()->getBody();

        self::assertStringContainsString('LOUIS INTERNET', $body);
        self::assertStringContainsString(self::HOST, $body);
    }

    public function testInstallationFallsBackToAGenericNameWhenSitenameIsEmpty(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] = '   ';

        $body = (string)$this->showConsentForm()->getBody();

        self::assertStringContainsString('TYPO3', $body);
        self::assertStringContainsString(self::HOST, $body, 'The host must be named either way');
    }

    /**
     * The button used to be `type="button"` with `onclick="window.close()"`, which browsers
     * refuse for a tab they did not open — so it did nothing at all.
     */
    public function testCancelSubmitsToTheServerInsteadOfClosingTheTab(): void
    {
        $body = (string)$this->showConsentForm()->getBody();

        self::assertStringContainsString('name="deny"', $body);
        self::assertStringNotContainsString('window.close()', $body);
    }

    /**
     * The field was never read — handleApproval takes the uid from $GLOBALS['BE_USER'] —
     * but a form field named user_id reads as authoritative, which is a trap worth removing
     * rather than documenting.
     */
    public function testFormNoLongerShipsAUserIdField(): void
    {
        self::assertStringNotContainsString('name="user_id"', (string)$this->showConsentForm()->getBody());
    }

    public function testDenialReportsAccessDeniedToTheClient(): void
    {
        $response = $this->postDecision('deny', $this->client['redirect_uris'][0]);

        self::assertSame(302, $response->getStatusCode());

        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith($this->client['redirect_uris'][0], $location);
        self::assertStringContainsString('error=access_denied', $location);
        self::assertStringContainsString('state=abc123', $location);
        self::assertStringNotContainsString('code=', $location, 'A refusal must not issue a code');
    }

    /**
     * Redirecting to whatever redirect_uri the query carries would make this endpoint an
     * open redirector, reachable with nothing but a crafted link.
     */
    public function testDenialRefusesAnUnregisteredRedirectUri(): void
    {
        $response = $this->postDecision('deny', 'https://attacker.example/collect');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    public function testDenialWithoutARedirectUriTellsTheUser(): void
    {
        $response = $this->postDecision('deny', '');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Authorization declined', (string)$response->getBody());
    }

    public function testDenialRequiresAValidCsrfToken(): void
    {
        $response = $this->postDecision('deny', $this->client['redirect_uris'][0], 'not-the-token');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    /**
     * Renders the consent form the way a browser would reach it.
     */
    private function showConsentForm(?string $redirectUri = null): ResponseInterface
    {
        $request = (new ServerRequest(new Uri('https://' . self::HOST . '/mcp_oauth/authorize'), 'GET'))
            ->withQueryParams([
                'client_id' => $this->client['client_id'],
                'redirect_uri' => $redirectUri ?? $this->client['redirect_uris'][0],
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
                'state' => 'abc123',
            ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new OAuthAuthorizeEndpoint())($request);
    }

    /**
     * Posts approve/deny with the CSRF token the consent form actually minted, so the
     * round-trip through the backend user's session is exercised rather than bypassed.
     */
    private function postDecision(
        string $decision,
        string $redirectUri,
        ?string $csrfToken = null
    ): ResponseInterface {
        // The token always comes from a legitimately rendered form, i.e. one with the
        // registered redirect_uri. Only the POST carries the URI under test — which is what
        // an attack looks like: the user reaches a real consent screen, and the redirect
        // target is swapped on submit.
        $form = (string)$this->showConsentForm()->getBody();
        preg_match('/name="csrf_token" value="([^"]+)"/', $form, $matches);
        $token = $csrfToken ?? ($matches[1] ?? '');
        self::assertNotSame('', $token, 'The consent form must have minted a CSRF token');

        $query = [
            'client_id' => $this->client['client_id'],
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
            'state' => 'abc123',
        ];
        if ($redirectUri !== '') {
            $query['redirect_uri'] = $redirectUri;
        }

        $request = (new ServerRequest(new Uri('https://' . self::HOST . '/mcp_oauth/authorize'), 'POST'))
            ->withQueryParams($query)
            ->withParsedBody([
                $decision => '1',
                'state' => 'abc123',
                'csrf_token' => $token,
            ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new OAuthAuthorizeEndpoint())($request);
    }
}
