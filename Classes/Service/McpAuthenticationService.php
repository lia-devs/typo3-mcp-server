<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared authentication service for MCP endpoints
 *
 * Handles Bearer token extraction, OAuth validation, and TYPO3 backend user
 * context setup. Used by both McpEndpoint and FileUploadEndpoint.
 */
class McpAuthenticationService
{
    private OAuthService $oauthService;
    private WorkspaceContextService $workspaceContextService;
    private BackendUserContextService $backendUserContextService;

    public function __construct(
        OAuthService $oauthService,
        WorkspaceContextService $workspaceContextService,
        BackendUserContextService $backendUserContextService
    ) {
        $this->oauthService = $oauthService;
        $this->workspaceContextService = $workspaceContextService;
        $this->backendUserContextService = $backendUserContextService;
    }

    /**
     * Authenticate a request and set up the TYPO3 backend context
     *
     * @return array Token info with 'be_user_uid' key
     * @throws McpAuthenticationException On missing/invalid token
     */
    public function authenticateRequest(ServerRequestInterface $request): array
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            throw new McpAuthenticationException('Missing authentication token');
        }

        $tokenInfo = $this->oauthService->validateToken($token, $request);

        if ($tokenInfo === null || $tokenInfo === false) {
            throw new McpAuthenticationException('Invalid or expired token');
        }

        $this->setupBackendUserContext((int)$tokenInfo['be_user_uid']);

        return $tokenInfo;
    }

    /**
     * Extract Bearer token from request
     *
     * Checks Authorization header, Apache fallbacks, and query parameter.
     */
    public function extractToken(ServerRequestInterface $request): ?string
    {
        // Try Authorization header first (preferred method)
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        // Try HTTP_AUTHORIZATION from Apache environment
        $serverParams = $request->getServerParams();
        $httpAuth = $serverParams['HTTP_AUTHORIZATION'] ?? '';
        if (!empty($httpAuth) && preg_match('/Bearer\s+(.+)/', $httpAuth, $matches)) {
            return $matches[1];
        }

        // Try REDIRECT_HTTP_AUTHORIZATION (Apache mod_rewrite/mod_auth_form strips and prefixes)
        $redirectAuth = $serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!empty($redirectAuth) && preg_match('/Bearer\s+(.+)/', $redirectAuth, $matches)) {
            return $matches[1];
        }

        // Fallback to query parameter for backward compatibility
        $queryParams = $request->getQueryParams();
        return $queryParams['token'] ?? null;
    }

    /**
     * Set up TYPO3 backend user context for authenticated user
     *
     * @throws McpAuthenticationException when the user behind the token is
     *         deleted, disabled or outside its validity period. A token
     *         outlives all of these, so it must stop working with them - with
     *         the same answer as an invalid token, which does not tell whether
     *         the account exists.
     */
    public function setupBackendUserContext(int $userId): void
    {
        try {
            $beUser = $this->backendUserContextService->impersonate($userId);
        } catch (\InvalidArgumentException) {
            throw new McpAuthenticationException('Invalid or expired token');
        }

        $workspaceId = $this->workspaceContextService->switchToOptimalWorkspace($beUser);
        $this->backendUserContextService->updateWorkspaceAspect($workspaceId);

        // Disable deferred image processing — DeferredBackendImageProcessor requires a full
        // backend session with CSRF tokens, which MCP endpoints don't have (Bearer token only).
        // Setting deferProcessing=false routes image processing through LocalImageProcessor.
        GeneralUtility::makeInstance(Context::class)
            ->setAspect('fileProcessing', new FileProcessingAspect(false));
    }
}
