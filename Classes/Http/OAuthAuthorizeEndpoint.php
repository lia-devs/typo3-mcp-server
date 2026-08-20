<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OAuth authorization endpoint
 */
class OAuthAuthorizeEndpoint
{
    use RequestUrlTrait;

    private ?LoggerInterface $logger = null;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $queryParams = $request->getQueryParams();
            $postParams = $request->getParsedBody() ?: [];
            
            // Initialize backend user context for eID
            $this->initializeBackendUserContext($request);
            
            // Check if user is authenticated
            if (!$this->isBackendUserAuthenticated()) {
                return $this->redirectToLogin($request);
            }

            $beUser = $GLOBALS['BE_USER'];
            $beUserId = (int)$beUser->user['uid'];

            // Handle authorization approval
            if ($request->getMethod() === 'POST' && isset($postParams['approve'])) {
                return $this->handleApproval($request, $beUserId);
            }

            // Handle an explicit refusal. Declining is a normal outcome of a consent
            // screen, not an error, and the client has to be told — otherwise it waits
            // for a callback that never comes.
            if ($request->getMethod() === 'POST' && isset($postParams['deny'])) {
                return $this->handleDenial($request);
            }

            // Show consent form
            return $this->showConsentForm($request);

        } catch (\Throwable $e) {
            return $this->createErrorResponse(
                $request,
                'server_error',
                'The authorization request could not be processed.',
                $e->getMessage()
            );
        }
    }

    private function initializeBackendUserContext(ServerRequestInterface $request): void
    {
        // Initialize backend user context for eID endpoints
        if (!isset($GLOBALS['BE_USER']) || !($GLOBALS['BE_USER'] instanceof BackendUserAuthentication)) {
            $GLOBALS['BE_USER'] = GeneralUtility::makeInstance(BackendUserAuthentication::class);
            $GLOBALS['BE_USER']->start($request);
        }
    }
    
    private function isBackendUserAuthenticated(): bool
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        return $beUser instanceof BackendUserAuthentication && 
               is_array($beUser->user) && 
               isset($beUser->user['uid']) && 
               $beUser->user['uid'] > 0;
    }

    /**
     * Resolve client name with proper fallback to hostname from Referer header
     */
    private function resolveClientName(ServerRequestInterface $request): string
    {
        $queryParams = $request->getQueryParams();
        
        // Check query params
        if (!empty($queryParams['client_name'])) {
            return $queryParams['client_name'];
        }
        
        // Fall back to hostname from Referer header
        $referer = $request->getHeaderLine('Referer');
        if (!empty($referer)) {
            $hostname = $this->extractHostnameFromUrl($referer);
            if (!empty($hostname)) {
                return $hostname;
            }
        }
        
        // Ultimate fallback
        return 'MCP Client';
    }

    /**
     * Extract hostname from URL, handling edge cases
     */
    private function extractHostnameFromUrl(string $url): string
    {
        // Handle malformed URLs
        if (empty($url)) {
            return '';
        }
        
        // Add protocol if missing to make parse_url work properly
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = 'http://' . $url;
        }
        
        $parsed = parse_url($url);
        
        // Return hostname or empty string if parsing failed
        return $parsed['host'] ?? $url;
    }


    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        
        // Store OAuth parameters in cookie
        $oauthData = [
            'client_id' => $queryParams['client_id'] ?? '',
            'client_name' => $this->resolveClientName($request),
            'redirect_uri' => $queryParams['redirect_uri'] ?? '',
            'code_challenge' => $queryParams['code_challenge'] ?? '',
            'code_challenge_method' => $queryParams['code_challenge_method'] ?? '',
            'state' => $queryParams['state'] ?? ''
        ];
        
        $oauthDataEncoded = base64_encode(json_encode($oauthData));
        $loginUrl = $this->getRequestSitePath($request) . '/typo3/index.php?loginProvider=1450629977&login_status=login';
        
        // Build cookie string with environment-aware security flags
        $isHttps = $request->getUri()->getScheme() === 'https';
        $cookieFlags = 'Max-Age=600; Path=/; HttpOnly; SameSite=Lax';
        if ($isHttps) {
            $cookieFlags .= '; Secure';
        }
        
        $stream = new Stream('php://temp', 'rw');
        $stream->write('');
        $stream->rewind();

        return new Response(
            $stream,
            302,
            [
                'Location' => $loginUrl,
                'Set-Cookie' => 'tx_mcpserver_oauth=' . $oauthDataEncoded . '; ' . $cookieFlags
            ]
        );
    }

    /**
     * The user declined on the consent screen.
     *
     * RFC 6749 §4.1.2.1: a refusal is reported to the client as
     * ``error=access_denied`` on the registered redirect_uri, carrying ``state`` back.
     * Before this existed the Cancel button only tried ``window.close()``, which browsers
     * refuse for a tab they did not open via window.open — so the button did nothing and
     * the client sat waiting for a callback that was never coming.
     *
     * The redirect_uri is validated against the registered client *before* redirecting to
     * it. Skipping that would turn this endpoint into an open redirector, reachable with
     * nothing but a crafted link.
     */
    private function handleDenial(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $postParams = $request->getParsedBody() ?: [];

        $providedCsrf = (string)($postParams['csrf_token'] ?? '');
        if (!$this->verifyCsrfToken($providedCsrf)) {
            return $this->createErrorResponse($request, 'invalid_request', 'Invalid or missing CSRF token');
        }

        $clientId = $queryParams['client_id'] ?? $postParams['client_id'] ?? '';
        $redirectUri = $queryParams['redirect_uri'] ?? '';
        $state = $postParams['state'] ?? $queryParams['state'] ?? '';

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $client = $oauthService->getClient((string)$clientId);
        if ($client === null) {
            return $this->createErrorResponse($request, 'invalid_client', 'Unknown client_id');
        }
        if ($redirectUri !== '' && !$oauthService->isRedirectUriAllowed($client, $redirectUri)) {
            return $this->createErrorResponse($request, 'invalid_request', 'redirect_uri is not registered for this client');
        }

        // No code was issued, but the token is spent either way: reopening the
        // authorization link mints a fresh one.
        $this->clearCsrfToken();

        if (!empty($redirectUri)) {
            $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
            $redirectUrl = $redirectUri . $separator . 'error=access_denied'
                . '&error_description=' . urlencode('The user declined the authorization request');
            if (!empty($state)) {
                $redirectUrl .= '&state=' . urlencode($state);
            }

            $stream = new Stream('php://temp', 'rw');
            $stream->write('');
            $stream->rewind();

            return new Response($stream, 302, ['Location' => $redirectUrl]);
        }

        // Without a redirect_uri there is nobody to notify, so tell the user instead.
        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->generateDeclinedTemplate());
        $stream->rewind();

        return new Response($stream, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function handleApproval(ServerRequestInterface $request, int $beUserId): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $postParams = $request->getParsedBody() ?: [];

        // CSRF: the POST must carry a token that matches what showConsentForm stored
        // in the backend user's session. This defends against a logged-in BE user
        // being tricked into auto-submitting approve=1 from an attacker page.
        $providedCsrf = (string)($postParams['csrf_token'] ?? '');
        if (!$this->verifyCsrfToken($providedCsrf)) {
            return $this->createErrorResponse($request, 'invalid_request', 'Invalid or missing CSRF token');
        }

        $clientId = $queryParams['client_id'] ?? $postParams['client_id'] ?? '';
        $clientName = $postParams['client_name'] ?? $this->resolveClientName($request);
        $redirectUri = $queryParams['redirect_uri'] ?? '';
        $pkceChallenge = $queryParams['code_challenge'] ?? '';
        $challengeMethod = $queryParams['code_challenge_method'] ?? 'S256';
        $state = $postParams['state'] ?? $queryParams['state'] ?? '';

        $oauthService = GeneralUtility::makeInstance(OAuthService::class);

        // Re-validate the client and redirect_uri so an attacker cannot skip the consent
        // form's checks by POSTing directly to this endpoint.
        $client = $oauthService->getClient((string)$clientId);
        if ($client === null) {
            return $this->createErrorResponse($request, 'invalid_client', 'Unknown client_id');
        }
        if ($redirectUri !== '' && !$oauthService->isRedirectUriAllowed($client, $redirectUri)) {
            return $this->createErrorResponse($request, 'invalid_request', 'redirect_uri is not registered for this client');
        }

        // Only S256 is supported for PKCE
        if (!empty($pkceChallenge) && $challengeMethod !== 'S256') {
            return $this->createErrorResponse($request, 'invalid_request', 'Only S256 code_challenge_method is supported');
        }

        // MCP authorization spec: public clients (no client_secret) MUST use PKCE.
        // Without a challenge, mere possession of the code would be enough to mint
        // a token, since verifyClientSecret() short-circuits for public clients.
        if (($client['token_endpoint_auth_method'] ?? 'none') === 'none' && $pkceChallenge === '') {
            return $this->createErrorResponse($request, 'invalid_request', 'Public clients must use PKCE (code_challenge is required)');
        }

        // CSRF token has been used — rotate it so a submitted form can't be replayed.
        $this->clearCsrfToken();

        $code = $oauthService->createAuthorizationCode(
            $beUserId,
            $clientName,
            $redirectUri,
            $pkceChallenge,
            $challengeMethod,
            $client['client_id']
        );

        // If redirect_uri is provided, redirect there with the code
        if (!empty($redirectUri)) {
            $separator = strpos($redirectUri, '?') !== false ? '&' : '?';
            $redirectUrl = $redirectUri . $separator . 'code=' . urlencode($code);
            
            // Add state parameter if provided
            if (!empty($state)) {
                $redirectUrl .= '&state=' . urlencode($state);
            }
            
            $stream = new Stream('php://temp', 'rw');
            $stream->write('');
            $stream->rewind();

            return new Response(
                $stream,
                302,
                ['Location' => $redirectUrl]
            );
        }

        // Otherwise, show the code to the user
        $html = $this->generateCodeDisplayTemplate($code, $clientName);
        
        $stream = new Stream('php://temp', 'rw');
        $stream->write($html);
        $stream->rewind();

        return new Response(
            $stream,
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    private function showConsentForm(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        $clientId = $queryParams['client_id'] ?? '';
        $redirectUri = $queryParams['redirect_uri'] ?? '';
        $codeChallenge = $queryParams['code_challenge'] ?? '';
        $challengeMethod = $queryParams['code_challenge_method'] ?? 'S256';
        $state = $queryParams['state'] ?? '';

        // Validate the client against the registered clients table
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        $client = $oauthService->getClient((string)$clientId);
        if ($client === null) {
            return $this->createErrorResponse($request, 'invalid_client', 'Unknown client_id');
        }
        if ($redirectUri !== '' && !$oauthService->isRedirectUriAllowed($client, $redirectUri)) {
            return $this->createErrorResponse($request, 'invalid_request', 'redirect_uri is not registered for this client');
        }

        // MCP authorization spec: public clients (no secret) MUST use PKCE.
        // Reject early so the user isn't asked to consent to something we'd later refuse.
        if (($client['token_endpoint_auth_method'] ?? 'none') === 'none' && $codeChallenge === '') {
            return $this->createErrorResponse($request, 'invalid_request', 'Public clients must use PKCE (code_challenge is required)');
        }

        // Prefer the name the client supplied during dynamic registration; fall
        // back to the Referer-hostname heuristic only for the well-known seeded
        // client (which has a generic placeholder name).
        $registeredName = trim((string)($client['client_name'] ?? ''));
        $isWellKnown = $client['client_id'] === OAuthService::WELL_KNOWN_CLIENT_ID;
        $clientName = (!$isWellKnown && $registeredName !== '')
            ? $registeredName
            : $this->resolveClientName($request);

        $beUser = $GLOBALS['BE_USER'];
        $username = $beUser->user['username'] ?? 'Unknown';

        // Which installation is being authorized. A consent screen exists so the user can
        // decide, and on a fleet of similarly-named environments behind one MCP connector
        // "Authorize MCP Access" alone withholds the fact the decision turns on.
        //
        // sitename identifies the installation family, the host identifies the environment
        // — and the host is what the user can cross-check against their address bar.
        // Deliberately no application-context badge: it is not reliably configured on every
        // installation, and a "Production" label on a staging box would be worse than none.
        $siteName = trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? ''));

        $html = $this->generateConsentTemplate([
            'site_name' => htmlspecialchars($siteName !== '' ? $siteName : 'TYPO3'),
            'host' => htmlspecialchars($request->getUri()->getHost()),
            'username' => htmlspecialchars($username),
            'client_name' => htmlspecialchars($clientName),
            'client_id' => htmlspecialchars($clientId),
            'redirect_uri' => htmlspecialchars($redirectUri),
            'code_challenge' => htmlspecialchars($codeChallenge),
            'code_challenge_method' => htmlspecialchars($challengeMethod),
            'state' => htmlspecialchars($state),
            'csrf_token' => htmlspecialchars($this->getOrCreateCsrfToken()),
        ]);

        $stream = new Stream('php://temp', 'rw');
        $stream->write($html);
        $stream->rewind();

        // Build cookie cleanup string with environment-aware security flags
        $isHttps = $request->getUri()->getScheme() === 'https';
        $cookieCleanupFlags = 'Max-Age=0; Path=/; HttpOnly; SameSite=Lax';
        if ($isHttps) {
            $cookieCleanupFlags .= '; Secure';
        }

        return new Response(
            $stream,
            200,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Set-Cookie' => 'tx_mcpserver_oauth=; ' . $cookieCleanupFlags
            ]
        );
    }


    private const CSRF_SESSION_KEY = 'mcp_oauth_csrf';

    /**
     * Return the CSRF token stored in the BE user's session, minting one on
     * first call. Stable across consent-form reloads so opening the form in
     * multiple tabs still works.
     */
    private function getOrCreateCsrfToken(): string
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication) {
            return '';
        }
        $token = $beUser->getSessionData(self::CSRF_SESSION_KEY);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $beUser->setAndSaveSessionData(self::CSRF_SESSION_KEY, $token);
        }
        return $token;
    }

    private function verifyCsrfToken(string $provided): bool
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication) {
            return false;
        }
        $stored = $beUser->getSessionData(self::CSRF_SESSION_KEY);
        if (!is_string($stored) || $stored === '' || $provided === '') {
            return false;
        }
        return hash_equals($stored, $provided);
    }

    private function clearCsrfToken(): void
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if ($beUser instanceof BackendUserAuthentication) {
            $beUser->setAndSaveSessionData(self::CSRF_SESSION_KEY, '');
        }
    }

    /**
     * An error on the authorize endpoint, rendered for whoever is actually asking.
     *
     * This endpoint is reached by a top-level browser navigation, so the default is a
     * page: a person who is handed `{"error":"invalid_request", …}` learns nothing they
     * can act on. JSON is still served when the caller explicitly asks for it via
     * ``Accept``, which keeps `curl -H 'Accept: application/json'` working — that is how
     * these environments get diagnosed during a rollout.
     *
     * Both representations render from the same two values, so they cannot drift apart
     * and describe the failure differently.
     *
     * Note this covers only the errors that *cannot* be reported to the client: an
     * unknown client_id or an unregistered redirect_uri must not be redirected to
     * (RFC 6749 §4.1.2.1), so there is no machine to inform. Where the redirect_uri is
     * validated, the client is told through the redirect instead — see handleDenial().
     *
     * @param string|null $logDetail Kept out of the response entirely; exception messages
     *                               and other internals belong in the log, and putting
     *                               them on a page that now looks trustworthy would be
     *                               worse than the bare JSON ever was.
     */
    private function createErrorResponse(
        ServerRequestInterface $request,
        string $error,
        string $description = '',
        ?string $logDetail = null
    ): ResponseInterface {
        $this->getLogger()->warning(
            'OAuth authorize refused: ' . $error,
            array_filter(['description' => $description, 'detail' => $logDetail])
        );

        // A deliberately blunt check rather than full q-value negotiation: there are two
        // representations, browsers never ask for application/json, and anything that
        // does ask wants the machine one.
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write((string)json_encode([
                'error' => $error,
                'error_description' => $description,
            ]));
            $stream->rewind();

            return new Response($stream, 400, ['Content-Type' => 'application/json']);
        }

        $stream = new Stream('php://temp', 'rw');
        $stream->write($this->generateNoticeTemplate(
            'Authorization failed',
            'Authorization failed',
            $description !== '' ? $description : 'The authorization request was refused.',
            $error
        ));
        $stream->rewind();

        return new Response($stream, 400, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    private function getLogger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(static::class);
        }

        return $this->logger;
    }


    /**
     * One palette and one component set for all three OAuth screens.
     *
     * They used to carry a copy of the CSS each, which is how the consent form and the
     * code page drifted into slightly different cards. The light values are deliberately
     * the ones that were already there — this unifies and adds a dark scheme, it is not a
     * redesign of a screen editors already recognise.
     *
     * Every colour is a token defined on a bare :root and redefined under
     * prefers-color-scheme: dark. A value defined only inside the media query would leave
     * light mode unstyled, and vice versa.
     */
    private const PAGE_STYLES = '
        :root {
            color-scheme: light dark;
            --bg: #f5f5f5;
            --card: #ffffff;
            --border: #dddddd;
            --text: #333333;
            --muted: #666666;
            --subtle-bg: #f8f9fa;
            --accent: #007cba;
            --accent-hover: #005a87;
            --on-accent: #ffffff;
            --neutral: #666666;
            --neutral-hover: #333333;
            --on-neutral: #ffffff;
            --success: #28a745;
            --shadow: rgba(0, 0, 0, 0.1);
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #16181c;
                --card: #1e2126;
                --border: #2e333a;
                --text: #e8eaed;
                --muted: #a3abb6;
                --subtle-bg: #262a30;
                --accent: #3ba3dd;
                --accent-hover: #5cb8ea;
                --on-accent: #0b1a24;
                --neutral: #454c54;
                --neutral-hover: #566068;
                --on-neutral: #e8eaed;
                --success: #5cc98e;
                --shadow: rgba(0, 0, 0, 0.5);
            }
        }
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            padding: 40px 20px;
            background-color: var(--bg);
            color: var(--text);
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background: var(--card);
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--shadow);
        }
        .container.centered { text-align: center; }
        .header { text-align: center; margin-bottom: 30px; }
        h1 { color: var(--text); margin: 0 0 10px 0; font-size: 24px; }
        p { margin: 0; color: var(--muted); }
        strong { color: var(--text); }
        .target {
            margin-bottom: 20px;
            padding: 16px 20px;
            border: 1px solid var(--border);
            border-left: 4px solid var(--accent);
            border-radius: 4px;
        }
        .target-name { font-weight: 600; color: var(--text); }
        .target-host {
            margin-top: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            color: var(--muted);
            word-break: break-all;
        }
        .info {
            background: var(--subtle-bg);
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 30px;
        }
        .permissions { margin-bottom: 30px; }
        .permissions h3 { color: var(--text); margin: 0 0 15px 0; }
        .permissions ul { margin: 0; padding-left: 20px; }
        .permissions li { margin-bottom: 8px; color: var(--muted); }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: 500; color: var(--text); }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 14px;
            background: var(--card);
            color: var(--text);
        }
        .buttons { display: flex; gap: 10px; justify-content: center; }
        button {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 500;
        }
        .approve { background: var(--accent); color: var(--on-accent); }
        .approve:hover { background: var(--accent-hover); }
        .deny { background: var(--neutral); color: var(--on-neutral); }
        .deny:hover { background: var(--neutral-hover); }
        .success { color: var(--success); font-size: 24px; margin-bottom: 20px; }
        .code {
            background: var(--subtle-bg);
            padding: 20px;
            border-radius: 4px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 16px;
            word-break: break-all;
            margin: 20px 0;
            border: 2px solid var(--accent);
            color: var(--text);
        }
        .copy-button {
            background: var(--accent);
            color: var(--on-accent);
            padding: 10px 20px;
            margin-top: 10px;
        }
        .copy-button:hover { background: var(--accent-hover); }
        .instructions { color: var(--muted); margin-top: 20px; }
        .errorcode {
            margin-top: 16px;
            display: inline-block;
            padding: 6px 10px;
            border-radius: 4px;
            background: var(--subtle-bg);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 13px;
            color: var(--text);
            word-break: break-all;
        }
';

    /**
     * Document shell shared by the OAuth screens.
     *
     * @param string $body    Already-escaped markup for the card
     * @param string $script  Optional trailing script; only the code page needs one
     */
    private function renderPage(
        string $title,
        string $body,
        bool $centered = false,
        string $script = ''
    ): string {
        return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
    <style>' . self::PAGE_STYLES . '    </style>
</head>
<body>
    <div class="container' . ($centered ? ' centered' : '') . '">
' . $body . '    </div>' . $script . '
</body>
</html>';
    }

    private function generateConsentTemplate(array $data): string
    {
        return $this->renderPage('Authorize MCP Access', '        <div class="header">
            <h1>Authorize MCP Access</h1>
        </div>

        <div class="target">
            <div class="target-name">' . $data['site_name'] . '</div>
            <div class="target-host">' . $data['host'] . '</div>
        </div>

        <div class="info">
            <p><strong>User:</strong> ' . $data['username'] . '</p>
            <p><strong>Client:</strong> ' . $data['client_name'] . '</p>
        </div>

        <div class="permissions">
            <h3>This application will be able to:</h3>
            <ul>
                <li>View TYPO3 page structure and content</li>
                <li>Search through TYPO3 records</li>
                <li>Create and modify content (in workspaces)</li>
                <li>Access content with your user permissions</li>
            </ul>
        </div>

        <form method="post">
            <div class="form-group">
                <label for="client_name">Client Name (optional):</label>
                <input type="text" id="client_name" name="client_name" value="' . $data['client_name'] . '" placeholder="My MCP Client">
            </div>

            <input type="hidden" name="client_id" value="' . $data['client_id'] . '">
            <input type="hidden" name="redirect_uri" value="' . $data['redirect_uri'] . '">
            <input type="hidden" name="code_challenge" value="' . $data['code_challenge'] . '">
            <input type="hidden" name="code_challenge_method" value="' . $data['code_challenge_method'] . '">
            <input type="hidden" name="state" value="' . $data['state'] . '">
            <input type="hidden" name="csrf_token" value="' . $data['csrf_token'] . '">

            <div class="buttons">
                <button type="submit" name="approve" value="1" class="approve">Authorize Access</button>
                <button type="submit" name="deny" value="1" class="deny">Cancel</button>
            </div>
        </form>
');
    }

    /**
     * Shown when the user declined and there is no redirect_uri to report it to.
     */
    private function generateDeclinedTemplate(): string
    {
        return $this->generateNoticeTemplate(
            'Authorization Declined',
            'Authorization declined',
            'No access was granted. You can close this tab.'
        );
    }

    /**
     * A short single-message page: a declined authorization, or a refused request.
     *
     * Shares one style block between those cases instead of each carrying a copy. The
     * consent form still has its own — folding that in means restyling a screen editors
     * already know, which belongs in its own change rather than hidden in this one.
     *
     * $code is the OAuth error code, shown verbatim when present. A screenshot reading
     * "invalid_request" is a support request somebody can act on; "something went wrong"
     * is not.
     */
    private function generateNoticeTemplate(
        string $title,
        string $heading,
        string $message,
        string $code = ''
    ): string {
        // .errorcode, not .code: the code page owns .code for its prominent bordered
        // block, and one class meaning two different things across shared styles is how
        // the copies drifted apart in the first place.
        $codeBlock = $code !== ''
            ? '
        <p class="errorcode">' . htmlspecialchars($code) . '</p>'
            : '';

        return $this->renderPage(
            $title,
            '        <h1>' . htmlspecialchars($heading) . '</h1>
        <p>' . htmlspecialchars($message) . '</p>' . $codeBlock . '
',
            centered: true
        );
    }

    private function generateCodeDisplayTemplate(string $code, string $clientName): string
    {
        return $this->renderPage(
            'Authorization Code',
            '        <div class="success">✓ Authorization Successful</div>
        
        <p>Authorization code for <strong>' . htmlspecialchars($clientName) . '</strong>:</p>
        
        <div class="code" id="authCode">' . htmlspecialchars($code) . '</div>
        
        <button class="copy-button" onclick="copyCode()">Copy Code</button>
        
        <div class="instructions">
            <p>Copy this code and paste it into your MCP client application.</p>
            <p><strong>Note:</strong> This code expires in 10 minutes.</p>
    
',
            centered: true,
            script: '
    <script>
        function copyCode() {
            const codeElement = document.getElementById("authCode");
            const text = codeElement.textContent;
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => {
                    alert("Code copied to clipboard!");
                });
            } else {
                // Fallback for older browsers
                const textarea = document.createElement("textarea");
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand("copy");
                document.body.removeChild(textarea);
                alert("Code copied to clipboard!");
            }
        }
    </script>'
        );
    }
}