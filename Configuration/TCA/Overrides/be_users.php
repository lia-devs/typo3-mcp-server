<?php

defined('TYPO3') or die();

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('be_users', [
    'tx_mcpserver_workspace' => [
        'exclude' => true,
        'label' => 'LLL:EXT:mcp_server/Resources/Private/Language/locallang_db.xlf:be_users.tx_mcpserver_workspace',
        'description' => 'LLL:EXT:mcp_server/Resources/Private/Language/locallang_db.xlf:be_users.tx_mcpserver_workspace.description',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                [
                    'label' => 'LLL:EXT:mcp_server/Resources/Private/Language/locallang_db.xlf:be_users.tx_mcpserver_workspace.default',
                    'value' => 0,
                ],
            ],
            'foreign_table' => 'sys_workspace',
            'foreign_table_where' => 'ORDER BY sys_workspace.title',
            'default' => 0,
        ],
    ],
]);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'be_users',
    'tx_mcpserver_workspace',
    '',
    'after:workspace_perms'
);
