<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
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
    private LanguageServiceFactory $languageServiceFactory;

    public function __construct(
        OAuthService $oauthService,
        WorkspaceContextService $workspaceContextService,
        LanguageServiceFactory $languageServiceFactory
    ) {
        $this->oauthService = $oauthService;
        $this->workspaceContextService = $workspaceContextService;
        $this->languageServiceFactory = $languageServiceFactory;
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
     */
    public function setupBackendUserContext(int $userId): void
    {
        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');

        $queryBuilder = $connection->createQueryBuilder();
        $userData = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId)))
            ->executeQuery()
            ->fetchAssociative();

        if ($userData) {
            $beUser->user = $userData;
            $GLOBALS['BE_USER'] = $beUser;

            $beUser->fetchGroupData();

            $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($beUser);

            $workspaceId = $this->workspaceContextService->switchToOptimalWorkspace($beUser);

            $context = GeneralUtility::makeInstance(Context::class);
            $context->setAspect('backend.user', new UserAspect($beUser));
            $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

            // Disable deferred image processing — DeferredBackendImageProcessor requires a full
            // backend session with CSRF tokens, which MCP endpoints don't have (Bearer token only).
            // Setting deferProcessing=false routes image processing through LocalImageProcessor.
            $context->setAspect('fileProcessing', new FileProcessingAspect(false));
        }

        $tcaFactory = GeneralUtility::getContainer()->get(\TYPO3\CMS\Core\Configuration\Tca\TcaFactory::class);
        $GLOBALS['TCA'] = $tcaFactory->get();
    }
}
