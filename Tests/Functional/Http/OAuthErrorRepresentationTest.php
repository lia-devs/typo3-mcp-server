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
 * The authorize endpoint is reached by a top-level browser navigation, so its errors are
 * for a person to read — while staying machine-readable for anything that asks.
 */
class OAuthErrorRepresentationTest extends AbstractFunctionalTest
{
    private const BROWSER_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
    private const REFUSED = 'redirect_uri is not registered for this client';

    private array $client;
    private mixed $previousRequest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = GeneralUtility::makeInstance(OAuthService::class)->registerClient([
            'client_name' => 'Claude',
            'redirect_uris' => ['https://client.example/oauth/backend/callback'],
        ]);
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        parent::tearDown();
    }

    public function testABrowserGetsAPageNotJson(): void
    {
        $response = $this->authorizeWithBadRedirectUri(self::BROWSER_ACCEPT);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string)$response->getBody();
        self::assertStringContainsString('Authorization failed', $body);
        self::assertStringContainsString(self::REFUSED, $body);
        // The code stays on the page: a screenshot reading "invalid_request" is a support
        // request somebody can act on.
        self::assertStringContainsString('invalid_request', $body);
    }

    /**
     * `curl -H 'Accept: application/json'` is how these environments get diagnosed during
     * a rollout, so the machine representation has to survive the change.
     */
    public function testJsonIsStillServedWhenAskedFor(): void
    {
        $response = $this->authorizeWithBadRedirectUri('application/json');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = json_decode((string)$response->getBody(), true);
        self::assertSame('invalid_request', $body['error']);
        self::assertSame(self::REFUSED, $body['error_description']);
    }

    /**
     * Both representations render from the same two values. If they could drift, one of
     * them would eventually describe a different failure than the other.
     */
    public function testBothRepresentationsDescribeTheSameFailure(): void
    {
        $html = (string)$this->authorizeWithBadRedirectUri(self::BROWSER_ACCEPT)->getBody();
        $json = json_decode((string)$this->authorizeWithBadRedirectUri('application/json')->getBody(), true);

        self::assertStringContainsString($json['error'], $html);
        self::assertStringContainsString($json['error_description'], $html);
    }

    /**
     * The catch-all used to pass $e->getMessage() straight into the response. A page that
     * now looks trustworthy presenting an internal message would be worse than the bare
     * JSON ever was, so the detail is log-only — in both representations.
     */
    public function testInternalDetailReachesNeitherRepresentation(): void
    {
        $endpoint = new OAuthAuthorizeEndpoint();
        $method = new \ReflectionMethod($endpoint, 'createErrorResponse');
        $method->setAccessible(true);

        foreach ([self::BROWSER_ACCEPT, 'application/json'] as $accept) {
            $request = (new ServerRequest(new Uri('https://example.com/mcp_oauth/authorize'), 'GET'))
                ->withHeader('Accept', $accept);

            $body = (string)$method->invoke(
                $endpoint,
                $request,
                'server_error',
                'The authorization request could not be processed.',
                'SQLSTATE[42S02]: Base table or view not found'
            )->getBody();

            self::assertStringNotContainsString('SQLSTATE', $body, "leaked with Accept: $accept");
            self::assertStringNotContainsString('Base table', $body, "leaked with Accept: $accept");
            self::assertStringContainsString('server_error', $body);
        }
    }

    /**
     * An unregistered redirect_uri is one of the errors that must NOT be redirected to the
     * client (RFC 6749 §4.1.2.1), so it is exactly the case that has to be rendered here.
     */
    private function authorizeWithBadRedirectUri(string $accept): ResponseInterface
    {
        $request = (new ServerRequest(new Uri('https://example.com/mcp_oauth/authorize'), 'GET'))
            ->withHeader('Accept', $accept)
            ->withQueryParams([
                'client_id' => $this->client['client_id'],
                'redirect_uri' => 'https://attacker.example/collect',
                'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
                'code_challenge_method' => 'S256',
                'state' => 'abc123',
            ]);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return (new OAuthAuthorizeEndpoint())($request);
    }
}
