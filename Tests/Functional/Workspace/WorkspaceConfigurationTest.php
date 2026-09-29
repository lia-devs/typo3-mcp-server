<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace;

use Hn\McpServer\Exception\WorkspaceConfigurationException;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Workspace resolution from be_users.tx_mcpserver_workspace and the extension
 * settings defaultWorkspaceTitle / autoCreateWorkspace.
 */
class WorkspaceConfigurationTest extends AbstractFunctionalTest
{
    private const EDITOR_UID = 99;

    protected WorkspaceContextService $workspaceService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);

        $this->connectionPool->getConnectionForTable('sys_workspace')->bulkInsert(
            'sys_workspace',
            [
                [10, 0, 'Default Workspace', 'be_users_1', 'be_users_' . self::EDITOR_UID, 0],
                [11, 0, 'Editor Workspace', 'be_users_1', 'be_users_' . self::EDITOR_UID, 0],
                [12, 0, 'Deleted Workspace', 'be_users_1', 'be_users_' . self::EDITOR_UID, 1],
                [13, 0, 'Foreign Workspace', 'be_users_1', '', 0],
            ],
            ['uid', 'pid', 'title', 'adminusers', 'members', 'deleted']
        );
        $this->connectionPool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID,
            'pid' => 0,
            'username' => 'example_editor',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'userMods' => 'web_WorkspacesWorkspaces',
        ]);
    }

    #[Test]
    public function userWorkspaceTakesPrecedenceOverDefault(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: 'Default Workspace', autoCreateWorkspace: false);
        $editor = $this->loginEditorWithWorkspace(11);

        $workspaceId = $this->workspaceService->switchToOptimalWorkspace($editor);

        self::assertSame(11, $workspaceId);
        self::assertSame(11, $editor->workspace);
        self::assertSame(11, GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect('workspace', 'id'));
    }

    #[Test]
    public function defaultWorkspaceIsUsedWithoutUserWorkspace(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: 'Default Workspace', autoCreateWorkspace: false);
        $editor = $this->loginEditorWithWorkspace(0);

        self::assertSame(10, $this->workspaceService->switchToOptimalWorkspace($editor));
    }

    #[Test]
    public function deletedUserWorkspaceFallsBackToDefault(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: 'Default Workspace', autoCreateWorkspace: false);
        $editor = $this->loginEditorWithWorkspace(12);

        self::assertSame(10, $this->workspaceService->switchToOptimalWorkspace($editor));
    }

    #[Test]
    public function missingDefaultWorkspaceIsRejected(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: 'Deleted Workspace', autoCreateWorkspace: true);
        $editor = $this->loginEditorWithWorkspace(0);

        $this->expectException(WorkspaceConfigurationException::class);
        $this->expectExceptionMessage('No workspace is titled "Deleted Workspace", so the default MCP workspace is ambiguous or missing.');

        $this->workspaceService->switchToOptimalWorkspace($editor);
    }

    #[Test]
    public function ambiguousDefaultWorkspaceTitleIsRejected(): void
    {
        $this->connectionPool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 14,
            'pid' => 0,
            'title' => 'Default Workspace',
            'adminusers' => 'be_users_1',
            'members' => 'be_users_' . self::EDITOR_UID,
        ]);
        $this->configureExtension(defaultWorkspaceTitle: 'Default Workspace', autoCreateWorkspace: true);
        $editor = $this->loginEditorWithWorkspace(0);

        $this->expectException(WorkspaceConfigurationException::class);
        $this->expectExceptionMessage('More than one workspace is titled "Default Workspace", so the default MCP workspace is ambiguous or missing.');

        $this->workspaceService->switchToOptimalWorkspace($editor);
    }

    #[Test]
    public function userWorkspaceWithoutWriteAccessIsRejected(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: 'Default Workspace', autoCreateWorkspace: true);
        $editor = $this->loginEditorWithWorkspace(13);

        $this->expectException(WorkspaceConfigurationException::class);
        $this->expectExceptionMessage('Backend user "example_editor" has no write access to the MCP workspace (uid 13).');

        $this->workspaceService->switchToOptimalWorkspace($editor);
    }

    #[Test]
    public function defaultWorkspaceWithoutWriteAccessIsRejected(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: 'Foreign Workspace', autoCreateWorkspace: true);
        $editor = $this->loginEditorWithWorkspace(0);

        $this->expectException(WorkspaceConfigurationException::class);
        $this->expectExceptionMessage('Backend user "example_editor" has no write access to the MCP workspace (uid 13).');

        $this->workspaceService->switchToOptimalWorkspace($editor);
    }

    #[Test]
    public function missingConfigurationIsRejectedWithoutCreatingWorkspace(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: '', autoCreateWorkspace: false);
        $editor = $this->loginEditorWithWorkspace(0);

        try {
            $this->workspaceService->switchToOptimalWorkspace($editor);
            self::fail('Expected WorkspaceConfigurationException');
        } catch (WorkspaceConfigurationException $e) {
            self::assertSame(
                'No MCP workspace is configured for backend user "example_editor". Ask an administrator to select'
                . ' a workspace in the user record or to set "defaultWorkspaceTitle" in the mcp_server extension settings.',
                $e->getMessage()
            );
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace');
        $queryBuilder->getRestrictions()->removeAll();
        $workspaceUids = $queryBuilder->select('uid')->from('sys_workspace')->orderBy('uid')->executeQuery()->fetchFirstColumn();
        self::assertSame([10, 11, 12, 13], array_map('intval', $workspaceUids));
    }

    #[Test]
    public function automaticSelectionRunsWithoutConfiguration(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: '', autoCreateWorkspace: true);
        $editor = $this->loginEditorWithWorkspace(0);

        self::assertSame(10, $this->workspaceService->switchToOptimalWorkspace($editor));
    }

    #[Test]
    public function toolReportsMissingConfigurationAsError(): void
    {
        $this->configureExtension(defaultWorkspaceTitle: '', autoCreateWorkspace: false);
        $this->loginEditorWithWorkspace(0);

        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content']);

        self::assertTrue($result->isError);
        self::assertSame(
            'No MCP workspace is configured for backend user "example_editor". Ask an administrator to select'
            . ' a workspace in the user record or to set "defaultWorkspaceTitle" in the mcp_server extension settings.',
            $result->content[0]->text
        );
    }

    private function configureExtension(string $defaultWorkspaceTitle, bool $autoCreateWorkspace): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['defaultWorkspaceTitle'] = $defaultWorkspaceTitle;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['autoCreateWorkspace'] = $autoCreateWorkspace ? '1' : '0';
    }

    private function loginEditorWithWorkspace(int $workspaceId): BackendUserAuthentication
    {
        $this->connectionPool->getConnectionForTable('be_users')->update(
            'be_users',
            [WorkspaceContextService::USER_WORKSPACE_FIELD => $workspaceId],
            ['uid' => self::EDITOR_UID]
        );

        return $this->setupDefaultBackendUser(self::EDITOR_UID);
    }
}
