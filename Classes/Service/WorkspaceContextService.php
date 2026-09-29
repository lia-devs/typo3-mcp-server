<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Exception\WorkspaceConfigurationException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;

/**
 * Service for managing workspace context in MCP operations
 */
class WorkspaceContextService
{
    public const USER_WORKSPACE_FIELD = 'tx_mcpserver_workspace';

    /**
     * Switch to the workspace the MCP tools work in.
     *
     * A configured workspace wins: the user's own (be_users.tx_mcpserver_workspace),
     * then the extension setting defaultWorkspaceTitle. Only when neither is
     * configured does the automatic selection run, and only if the extension
     * setting autoCreateWorkspace allows it.
     *
     * @throws WorkspaceConfigurationException When no usable workspace is configured
     */
    public function switchToOptimalWorkspace(BackendUserAuthentication $beUser): int
    {
        $workspaceId = $this->resolveConfiguredWorkspace($beUser)
            ?? $this->selectWorkspaceAutomatically($beUser);

        $this->setWorkspaceContext($beUser, $workspaceId);

        return $workspaceId;
    }

    /**
     * The user's workspace, or the default workspace when the user has none or
     * the user's workspace was deleted. Null when neither is configured.
     *
     * @throws WorkspaceConfigurationException
     */
    public function resolveConfiguredWorkspace(BackendUserAuthentication $beUser): ?int
    {
        $userWorkspaceId = (int)($beUser->user[self::USER_WORKSPACE_FIELD] ?? 0);
        if ($userWorkspaceId > 0 && $this->workspaceExists($userWorkspaceId)) {
            return $this->ensureWriteAccess($beUser, $userWorkspaceId);
        }

        $defaultWorkspaceTitle = trim((string)GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('mcp_server', 'defaultWorkspaceTitle'));
        if ($defaultWorkspaceTitle === '') {
            return null;
        }

        return $this->ensureWriteAccess($beUser, $this->findWorkspaceByTitle($defaultWorkspaceTitle));
    }

    /**
     * The default workspace is referenced by title rather than uid, because
     * sys_workspace records are created per environment and their uids differ,
     * while the extension settings are deployed unchanged to every environment.
     *
     * @throws WorkspaceConfigurationException When no or more than one workspace has this title
     */
    protected function findWorkspaceByTitle(string $title): int
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_workspace');
        $workspaceUids = $queryBuilder
            ->select('uid')
            ->from('sys_workspace')
            ->where($queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)))
            ->executeQuery()
            ->fetchFirstColumn();

        if (count($workspaceUids) !== 1) {
            throw new WorkspaceConfigurationException(sprintf(
                '%s workspace is titled "%s", so the default MCP workspace is ambiguous or missing. Ask an'
                . ' administrator to update "defaultWorkspaceTitle" in the mcp_server extension settings.',
                $workspaceUids === [] ? 'No' : 'More than one',
                $title
            ));
        }

        return (int)$workspaceUids[0];
    }

    /**
     * Automatic selection for installations without a configured workspace:
     * the workspace currently selected in the backend, the first writable one,
     * or a newly created personal workspace.
     *
     * @throws WorkspaceConfigurationException When autoCreateWorkspace is disabled
     */
    protected function selectWorkspaceAutomatically(BackendUserAuthentication $beUser): int
    {
        $this->ensureAutomaticSelectionAllowed($beUser);

        $currentWorkspace = $beUser->workspace ?? 0;
        if ($currentWorkspace > 0) {
            return $currentWorkspace;
        }

        $workspaceId = $this->getFirstWritableWorkspace($beUser);
        if ($workspaceId === 0 && $this->canUserCreateWorkspaces($beUser)) {
            $workspaceId = $this->createMcpWorkspace($beUser);
        }

        return $workspaceId;
    }

    /**
     * @throws WorkspaceConfigurationException When autoCreateWorkspace is disabled
     */
    public function ensureAutomaticSelectionAllowed(BackendUserAuthentication $beUser): void
    {
        $autoCreateWorkspace = (bool)GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('mcp_server', 'autoCreateWorkspace');
        if (!$autoCreateWorkspace) {
            throw new WorkspaceConfigurationException(sprintf(
                'No MCP workspace is configured for backend user "%s". Ask an administrator to select'
                . ' a workspace in the user record or to set "defaultWorkspaceTitle" in the mcp_server'
                . ' extension settings.',
                $beUser->user['username']
            ));
        }
    }

    protected function workspaceExists(int $workspaceId): bool
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('sys_workspace');

        return (int)$queryBuilder
            ->count('uid')
            ->from('sys_workspace')
            ->where($queryBuilder->expr()->eq(
                'uid',
                $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT)
            ))
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @throws WorkspaceConfigurationException When the user may not write to the workspace
     */
    protected function ensureWriteAccess(BackendUserAuthentication $beUser, int $workspaceId): int
    {
        $workspaceRecord = $beUser->checkWorkspace($workspaceId);
        if ($workspaceRecord === false || !$this->hasWriteAccess($workspaceRecord)) {
            throw new WorkspaceConfigurationException(sprintf(
                'Backend user "%s" has no write access to the MCP workspace (uid %d). Ask an administrator'
                . ' to add the user as owner or member of that workspace.',
                $beUser->user['username'],
                $workspaceId
            ));
        }

        return $workspaceId;
    }

    /**
     * Get the first workspace the user can write to
     */
    protected function getFirstWritableWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $workspaceService = GeneralUtility::makeInstance(WorkspaceService::class);
            $availableWorkspaces = $workspaceService->getAvailableWorkspaces();
            
            // Check each workspace (excluding live workspace 0)
            foreach ($availableWorkspaces as $workspaceId => $title) {
                if ($workspaceId > 0) {
                    $workspaceRecord = $beUser->checkWorkspace($workspaceId);
                    if ($workspaceRecord && $this->hasWriteAccess($workspaceRecord)) {
                        return $workspaceId;
                    }
                }
            }
        } catch (\Throwable $e) {
            // If WorkspaceService fails, fall back to database query
            return $this->getWorkspaceFromDatabase($beUser);
        }
        
        return 0; // Fallback to live workspace
    }
    
    /**
     * Check if user has write access to a workspace
     */
    protected function hasWriteAccess(array $workspaceRecord): bool
    {
        // Admin users always have write access
        if (!empty($workspaceRecord['_ACCESS']) && $workspaceRecord['_ACCESS'] === 'admin') {
            return true;
        }
        
        // Owner has write access
        if (!empty($workspaceRecord['_ACCESS']) && $workspaceRecord['_ACCESS'] === 'owner') {
            return true;
        }
        
        // Members have write access
        if (!empty($workspaceRecord['_ACCESS']) && $workspaceRecord['_ACCESS'] === 'member') {
            return true;
        }
        
        return false;
    }
    
    /**
     * Fallback method to get workspace from database
     */
    protected function getWorkspaceFromDatabase(BackendUserAuthentication $beUser): int
    {
        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_workspace');
            
            // Get workspaces where user is owner or member
            $workspaces = $queryBuilder
                ->select('uid', 'title')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->eq(
                            'adminusers',
                            $queryBuilder->createNamedParameter($beUser->user['uid'] ?? 0)
                        ),
                        $queryBuilder->expr()->like(
                            'adminusers',
                            $queryBuilder->createNamedParameter('%,' . ($beUser->user['uid'] ?? 0) . ',%')
                        ),
                        $queryBuilder->expr()->eq(
                            'members',
                            $queryBuilder->createNamedParameter($beUser->user['uid'] ?? 0)
                        ),
                        $queryBuilder->expr()->like(
                            'members',
                            $queryBuilder->createNamedParameter('%,' . ($beUser->user['uid'] ?? 0) . ',%')
                        )
                    )
                )
                ->andWhere(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
                )
                ->orderBy('uid', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            
            if ($workspaces) {
                return (int)$workspaces['uid'];
            }
        } catch (\Throwable $e) {
            // Ignore database errors
        }
        
        return 0;
    }
    
    /**
     * Check if user can create workspaces
     */
    protected function canUserCreateWorkspaces(BackendUserAuthentication $beUser): bool
    {
        // Admin users can always create workspaces
        if ($beUser->isAdmin()) {
            return true;
        }
        
        // Check if user has workspace module access
        return $beUser->check('modules', 'web_WorkspacesWorkspaces');
    }
    
    /**
     * Create a new workspace for MCP operations
     */
    protected function createMcpWorkspace(BackendUserAuthentication $beUser): int
    {
        try {
            $realName = $beUser->user['realName'] ?? '';
            $username = $beUser->user['username'] ?? 'unknown_user';
            $workspaceTitle = 'MCP Workspace for ' . ($realName ?: $username);
            $workspaceDescription = 'Automatically created workspace for Model Context Protocol operations';
            
            // Create workspace record data
            // Only use fields that are guaranteed to exist in TYPO3 core
            $workspaceData = [
                'pid' => 0, // Workspaces are created at root level
                'title' => $workspaceTitle,
                'description' => $workspaceDescription,
                'adminusers' => $beUser->user['uid'] ?? 0,
                'members' => '',
                'db_mountpoints' => '', // Inherit from user
                'file_mountpoints' => '', // Inherit from user
                'publish_access' => 0, // No publishing restrictions (matches TCA default)
                'stagechg_notification' => 0, // No email notifications by default
                'live_edit' => 0, // No live edit
                'publish_time' => 0, // No scheduled publishing
            ];

            // TYPO3 14 removed DataHandler::$admin. Creating sys_workspace
            // records is an admin-only operation, so briefly elevate the current
            // BE user for the duration of the workspace creation.
            $previousAdminFlag = $beUser->user['admin'] ?? 0;
            $beUser->user['admin'] = 1;
            try {
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);

                $newId = 'NEW' . uniqid();
                $dataMap = [
                    'sys_workspace' => [
                        $newId => $workspaceData
                    ]
                ];

                $dataHandler->start($dataMap, []);
                $dataHandler->process_datamap();

                $newUid = $dataHandler->substNEWwithIDs[$newId] ?? null;
            } finally {
                $beUser->user['admin'] = $previousAdminFlag;
            }

            if ($newUid && !$dataHandler->errorLog) {
                return (int)$newUid;
            }
        } catch (\Throwable $e) {
            // Workspace creation failed, log the error but don't fail
            error_log('MCP Workspace creation failed: ' . $e->getMessage());
        }
        
        return 0; // Fallback to live workspace
    }
    
    /**
     * Set the workspace context for the current request
     */
    public function setWorkspaceContext(BackendUserAuthentication $beUser, int $workspaceId): void
    {
        // Set workspace on the backend user (temporary, doesn't persist to database)
        $beUser->setTemporaryWorkspace($workspaceId);
        
        // Update the Context API
        $context = GeneralUtility::makeInstance(Context::class);
        $workspaceAspect = GeneralUtility::makeInstance(WorkspaceAspect::class, $workspaceId);
        $context->setAspect('workspace', $workspaceAspect);
    }
    
    /**
     * Get current workspace ID
     */
    public function getCurrentWorkspace(): int
    {
        return $GLOBALS['BE_USER']->workspace ?? 0;
    }
    
    /**
     * Get information about the current workspace
     */
    public function getWorkspaceInfo(): array
    {
        $workspaceId = $this->getCurrentWorkspace();
        
        if ($workspaceId === 0) {
            return [
                'id' => 0,
                'title' => 'Live',
                'description' => 'Live workspace - changes are immediately public',
                'is_live' => true
            ];
        }
        
        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_workspace');
            
            $workspace = $queryBuilder
                ->select('uid', 'title', 'description')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($workspaceId))
                )
                ->executeQuery()
                ->fetchAssociative();
            
            if ($workspace) {
                return [
                    'id' => (int)$workspace['uid'],
                    'title' => $workspace['title'],
                    'description' => $workspace['description'],
                    'is_live' => false
                ];
            }
        } catch (\Throwable $e) {
            // Ignore errors
        }
        
        return [
            'id' => $workspaceId,
            'title' => 'Unknown Workspace',
            'description' => 'Workspace information not available',
            'is_live' => false
        ];
    }
}