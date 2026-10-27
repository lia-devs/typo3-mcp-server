<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Controller\OAuthResumeController;
use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\RouteRedirect;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The authorization link must only have to be opened once.
 *
 * Without a backend session the authorize endpoint sends the user to the backend
 * login; afterwards TYPO3 has to come back to the authorization flow instead of
 * dropping the user in the backend.
 */
class OAuthLoginReturnRedirectTest extends AbstractFunctionalTest
{
    private mixed $previousRequest;
    private mixed $previousBackendUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->previousBackendUser = $GLOBALS['BE_USER'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        $GLOBALS['BE_USER'] = $this->previousBackendUser;
        parent::tearDown();
    }

    public function testAuthorizeWithoutBackendSessionSendsLoginBackToTheFlow(): void
    {
        // An instance without a resolved user: the authenticated check fails, but
        // initializeBackendUserContext() leaves it alone instead of starting a session.
        $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $GLOBALS['BE_USER']->user = [];

        $request = (new ServerRequest(new Uri('https://example.com/mcp_oauth/authorize'), 'GET'))
            ->withQueryParams([
                'client_id' => 'my-client',
                'redirect_uri' => 'https://client.example.com/cb',
                'code_challenge' => 'chal',
                'code_challenge_method' => 'S256',
                'state' => 'abc123',
            ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = (new OAuthAuthorizeEndpoint())($request);

        self::assertSame(302, $response->getStatusCode());

        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/typo3/?login_status=login', $location);
        self::assertStringContainsString(
            'redirect=' . OAuthResumeController::ROUTE_NAME,
            $location,
            'The login URL has to carry the resume route, otherwise TYPO3 drops the user in the backend'
        );

        // redirectParams is urlencoded once as a whole, so decode before inspecting.
        parse_str((string)parse_url($location, PHP_URL_QUERY), $loginQuery);
        parse_str($loginQuery['redirectParams'] ?? '', $pending);

        self::assertSame('my-client', $pending['client_id'] ?? null);
        self::assertSame('https://client.example.com/cb', $pending['redirect_uri'] ?? null);
        self::assertSame('chal', $pending['code_challenge'] ?? null);
        self::assertSame('abc123', $pending['state'] ?? null);
    }

    public function testResumeHandsThePendingAuthorizationBackToTheEndpoint(): void
    {
        $response = $this->callResume([
            'client_id' => 'my-client',
            'redirect_uri' => 'https://client.example.com/cb',
            'code_challenge' => 'chal',
            'code_challenge_method' => 'S256',
            'state' => 'abc123',
        ]);

        self::assertSame(200, $response->getStatusCode());
        $body = (string)$response->getBody();

        self::assertStringContainsString('/mcp_oauth/authorize?', $body);
        self::assertStringContainsString('client_id=my-client', $body);
        self::assertStringContainsString('state=abc123', $body);
        self::assertStringContainsString('code_challenge=chal', $body);

        // The route renders as the backend's start module, so the redirect has to leave
        // that frame instead of showing consent embedded in the backend.
        self::assertStringContainsString('window.top', $body);
    }

    public function testResumeKeepsBackendRouteParametersOutOfTheAuthorizationUrl(): void
    {
        $response = $this->callResume([
            'client_id' => 'my-client',
            'redirect_uri' => 'https://client.example.com/cb',
            'token' => 'backend-csrf-token',
            'route' => '/mcp-oauth/resume',
        ]);

        $body = (string)$response->getBody();
        self::assertStringContainsString('client_id=my-client', $body);
        self::assertStringNotContainsString('backend-csrf-token', $body);
        self::assertStringNotContainsString('token=', $body);
    }

    public function testResumeWithoutPendingAuthorizationFallsBackToTheBackend(): void
    {
        $request = new ServerRequest(new Uri('https://example.com/typo3/mcp-oauth/resume'), 'GET');
        $GLOBALS['TYPO3_REQUEST'] = $request;

        $response = (new OAuthResumeController())->resumeAction($request);

        self::assertSame(302, $response->getStatusCode());
        self::assertNotSame('', $response->getHeaderLine('Location'));
    }

    /**
     * The route has to survive TYPO3's redirect resolution with its parameters intact.
     *
     * Guards two failure modes of Configuration/Backend/Routes.php that both end with
     * the user in the backend instead of the consent screen: a non-module route
     * without "redirect.enable" is refused outright, and without "redirect.parameters"
     * RouteRedirect silently discards every parameter.
     */
    public function testResumeRouteSurvivesRedirectResolutionWithItsParameters(): void
    {
        // The full set both carriers agree on — PendingAuthorizationCookie::PARAMETERS and
        // OAuthResumeController::AUTHORIZATION_PARAMETERS. A parameter missing from the
        // route's allow-list is dropped without a word, so the whole set belongs here.
        $parameters = [
            'client_id' => 'my-client',
            'client_name' => 'My MCP Client',
            'redirect_uri' => 'https://client.example.com/cb',
            'code_challenge' => 'chal',
            'code_challenge_method' => 'S256',
            'state' => 'abc123',
        ];

        $router = GeneralUtility::makeInstance(Router::class);
        $redirect = RouteRedirect::create(OAuthResumeController::ROUTE_NAME, $parameters);

        // Throws for a non-module route that does not declare redirect.enable
        $redirect->resolve($router);

        self::assertSame(
            $parameters,
            $redirect->getParameters(),
            'Every parameter must be allow-listed in redirect.parameters, otherwise they are dropped'
        );
    }

    /**
     * @param array<string, string> $queryParams
     */
    private function callResume(array $queryParams): ResponseInterface
    {
        $request = (new ServerRequest(new Uri('https://example.com/typo3/mcp-oauth/resume'), 'GET'))
            ->withQueryParams($queryParams);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new OAuthResumeController())->resumeAction($request);
    }
}
