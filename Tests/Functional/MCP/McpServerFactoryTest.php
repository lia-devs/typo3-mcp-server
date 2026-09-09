<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP;

use Composer\InstalledVersions;
use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The identity the server reports in the initialize handshake.
 *
 * The version is the only place a client can learn which build it is talking
 * to, and a branch install cannot say that with its declared version alone —
 * every commit of a branch declares the same one. The installed commit
 * therefore rides along in the semver build position, and this pins that shape:
 * anything parsing it (a client comparing it against the branch head) breaks
 * silently if the suffix disappears.
 */
class McpServerFactoryTest extends AbstractFunctionalTest
{
    private const PACKAGE = 'hn/typo3-mcp-server';

    public function testServerVersionCarriesTheInstalledCommit(): void
    {
        $reference = InstalledVersions::getReference(self::PACKAGE);
        self::assertNotNull(
            $reference,
            'Composer must know this package in a composer-installed test run'
        );

        $version = GeneralUtility::makeInstance(McpServerFactory::class)->getServerVersion();

        self::assertStringContainsString('+' . $reference, $version);
    }

    public function testServerVersionStatesTheTypo3Version(): void
    {
        $version = GeneralUtility::makeInstance(McpServerFactory::class)->getServerVersion();

        self::assertMatchesRegularExpression('/ \(TYPO3 \d+\.\d+\.\d+\)$/', $version);
    }

    public function testServerVersionIsAcceptedByTheHandshakeValidation(): void
    {
        // The SDK rejects an empty server version outright — and PHP's empty()
        // also rejects "0", so a version string is not automatically safe.
        $factory = GeneralUtility::makeInstance(McpServerFactory::class);

        $options = $factory->createInitializationOptions($factory->createServer());

        self::assertSame($factory->getServerVersion(), $options->serverVersion);
        self::assertNotEmpty($options->serverVersion);
    }
}
