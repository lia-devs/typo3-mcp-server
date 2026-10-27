<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Http\PendingAuthorizationCookie;
use Hn\McpServer\Middleware\OAuthLoginReturnMiddleware;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The authorization link must only have to be opened once.
 *
 * Without a backend session the authorize endpoint hands the user to the backend login and
 * parks the pending authorization in a cookie; OAuthLoginReturnMiddleware has to bring them
 * back to the consent screen once the login completed, whichever login provider ran.
 */
class OAuthLoginReturnTest extends AbstractFunctionalTest
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

    public function testAuthorizeWithoutBackendSessionParksTheAuthorizationInACookie(): void
    {
        $response = $this->callAuthorizeWithoutSession();

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/typo3/?login_status=login', $response->getHeaderLine('Location'));

        $setCookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringStartsWith(PendingAuthorizationCookie::NAME . '=', $setCookie);

        self::assertSame(
            [
                'client_id' => 'my-client',
                'client_name' => 'My MCP Client',
                'redirect_uri' => 'https://client.example.com/cb',
                'code_challenge' => 'chal',
                'code_challenge_method' => 'S256',
                'state' => 'abc123',
            ],
            $this->decodeCookieValue($setCookie),
            'The cookie has to carry the whole pending authorization, not a subset'
        );

        self::assertStringContainsString('Max-Age=600', $setCookie);
        self::assertStringContainsString('Path=/', $setCookie);
        self::assertStringContainsString('HttpOnly', $setCookie);
        self::assertStringContainsString('Secure', $setCookie);

        // SameSite=Lax is the point of the cookie, not a default. A login provider that
        // authenticates outside TYPO3 returns as a cross-site top-level navigation: Lax
        // still sends the cookie on that, Strict does not. Tightening this to Strict makes
        // the cookie useless for exactly the logins it exists to cover.
        self::assertStringContainsString('SameSite=Lax', $setCookie);
        self::assertStringNotContainsString('SameSite=Strict', $setCookie);
    }

    /**
     * Pinning a login provider that the installation does not have is worse than pinning
     * none: LoginProviderResolver discards an unregistered identifier and falls back to the
     * be_lastLoginProvider cookie, then to the primary provider. Omitting it also lets an
     * installation whose users can only use a non-default provider land on it directly.
     */
    public function testLoginUrlDoesNotPinALoginProvider(): void
    {
        $location = $this->callAuthorizeWithoutSession()->getHeaderLine('Location');

        self::assertStringContainsString('login_status=login', $location);
        self::assertStringNotContainsString('loginProvider', $location);
    }

    public function testMiddlewareResumesTheAuthorizationAfterAnExternalProviderLogin(): void
    {
        // How such a callback arrives: the provider returns to a URL of its own making, which
        // carries login_status in the query and nothing else of ours.
        $response = $this->callMiddleware(
            $this->backendRequest(
                ['login_status' => 'login', 'code' => 'provider-code'],
                $this->pendingCookie(['client_id' => 'my-client', 'state' => 'abc123'])
            ),
            loggedIn: true
        );

        self::assertSame(302, $response->getStatusCode());

        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('/mcp_oauth/authorize?', $location);
        self::assertStringContainsString('client_id=my-client', $location);
        self::assertStringContainsString('state=abc123', $location);

        self::assertStringContainsString(
            'Max-Age=0',
            $response->getHeaderLine('Set-Cookie'),
            'The cookie has to be dropped on the way out, otherwise it keeps firing'
        );
    }

    public function testMiddlewareResumesAfterAPasswordLoginSubmittedInTheFormBody(): void
    {
        // The username/password login posts login_status as a form field, not a query
        // parameter, so both places have to be inspected.
        $request = $this->backendRequest(
            ['route' => '/login'],
            $this->pendingCookie(['client_id' => 'my-client', 'state' => 'abc123']),
            'POST'
        )->withParsedBody(['login_status' => 'login', 'username' => 'tester']);

        $response = $this->callMiddleware($request, loggedIn: true);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('/mcp_oauth/authorize?', $response->getHeaderLine('Location'));
    }

    /**
     * A parked authorization alone must not redirect anything. The cookie outlives an
     * abandoned flow for its full lifetime, and interrupting a backend session the user was
     * already working in is worse than not helping at all. This is also what the previous
     * implementation could not express: it keyed on the request path /typo3/main and so fired
     * on any visit to the backend's main entry point while a cookie existed.
     */
    public function testMiddlewareLeavesAnOngoingBackendSessionAlone(): void
    {
        $response = $this->callMiddleware(
            $this->backendRequest(
                ['route' => '/main', 'token' => 'backend-csrf-token'],
                $this->pendingCookie(['client_id' => 'my-client', 'state' => 'abc123'])
            ),
            loggedIn: true
        );

        self::assertSame(204, $response->getStatusCode(), 'The request has to pass through untouched');
    }

    public function testMiddlewareIgnoresTheCookieWhileNobodyIsLoggedIn(): void
    {
        $response = $this->callMiddleware(
            $this->backendRequest(
                ['login_status' => 'login'],
                $this->pendingCookie(['client_id' => 'my-client', 'state' => 'abc123'])
            ),
            loggedIn: false
        );

        self::assertSame(204, $response->getStatusCode(), 'A failed login must not resume anything');
    }

    /**
     * Cookie content is client-supplied. A malformed cookie has to read as "nothing pending"
     * rather than raising: this is evaluated on backend requests, so a stale or tampered
     * cookie must never be able to lock a user out of the backend.
     */
    #[DataProvider('unusableCookies')]
    public function testMiddlewareIgnoresAnUnusableCookie(string $cookieValue): void
    {
        $response = $this->callMiddleware(
            $this->backendRequest(['login_status' => 'login'], $cookieValue),
            loggedIn: true
        );

        self::assertSame(204, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableCookies(): array
    {
        return [
            'not base64' => ['@@@not-base64@@@'],
            'not json' => [base64_encode('not json at all')],
            'json but not an object' => [base64_encode('"a string"')],
            'no client_id' => [base64_encode((string)json_encode(['state' => 'abc123']))],
            'client_id is not a string' => [base64_encode((string)json_encode(['client_id' => ['nested']]))],
            'empty' => [''],
        ];
    }

    /**
     * A hand-crafted cookie must not be able to smuggle backend route parameters into the
     * authorization URL — the allow-list applies when reading, not only when writing.
     */
    public function testMiddlewareKeepsBackendRouteParametersOutOfTheAuthorizationUrl(): void
    {
        $response = $this->callMiddleware(
            $this->backendRequest(
                ['login_status' => 'login'],
                $this->pendingCookie([
                    'client_id' => 'my-client',
                    'token' => 'backend-csrf-token',
                    'route' => '/main',
                ])
            ),
            loggedIn: true
        );

        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString('client_id=my-client', $location);
        self::assertStringNotContainsString('backend-csrf-token', $location);
        self::assertStringNotContainsString('token=', $location);
        self::assertStringNotContainsString('route=', $location);
    }

    /**
     * The authorize endpoint reached without a resolved backend user.
     */
    private function callAuthorizeWithoutSession(): ResponseInterface
    {
        // An instance without a resolved user: the authenticated check fails, but
        // initializeBackendUserContext() leaves it alone instead of starting a session.
        $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $GLOBALS['BE_USER']->user = [];

        $request = (new ServerRequest(new Uri('https://example.com/mcp_oauth/authorize'), 'GET'))
            ->withQueryParams([
                'client_id' => 'my-client',
                'client_name' => 'My MCP Client',
                'redirect_uri' => 'https://client.example.com/cb',
                'code_challenge' => 'chal',
                'code_challenge_method' => 'S256',
                'state' => 'abc123',
            ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new OAuthAuthorizeEndpoint())($request);
    }

    /**
     * A backend request as it arrives at the middleware, i.e. after authentication.
     *
     * @param array<string, string> $queryParams
     */
    private function backendRequest(
        array $queryParams,
        string $cookieValue,
        string $method = 'GET'
    ): ServerRequest {
        $request = (new ServerRequest(new Uri('https://example.com/typo3/index.php'), $method))
            ->withQueryParams($queryParams)
            ->withCookieParams([PendingAuthorizationCookie::NAME => $cookieValue]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    /**
     * Runs the middleware over a real handler rather than a mock: reaching the handler (204)
     * and being redirected (302) are the two outcomes under test, so the handler only has to
     * be distinguishable.
     */
    private function callMiddleware(ServerRequestInterface $request, bool $loggedIn): ResponseInterface
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = $loggedIn ? ['uid' => 1, 'username' => 'tester'] : [];

        $context = new Context();
        $context->setAspect('backend.user', new UserAspect($backendUser));

        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('php://temp', 204);
            }
        };

        return (new OAuthLoginReturnMiddleware($context))->process($request, $handler);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function pendingCookie(array $parameters): string
    {
        return base64_encode((string)json_encode($parameters));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeCookieValue(string $setCookieHeader): array
    {
        $value = explode(';', $setCookieHeader)[0];
        $encoded = substr($value, strlen(PendingAuthorizationCookie::NAME) + 1);

        return json_decode((string)base64_decode($encoded, true), true);
    }
}
