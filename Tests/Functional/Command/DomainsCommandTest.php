<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Command;

use Hn\McpServer\Command\DomainsCommand;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class DomainsCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        // Site 1: base + two baseVariants (the partner.hormann.gr scenario —
        // an additional production domain that must reach the same backend)
        $this->writeSiteConfiguration('main-site', [
            'rootPageId' => 1,
            'base' => 'https://www.example.com/',
            'websiteTitle' => 'Main Site',
            'baseVariants' => [
                ['base' => 'https://stage.example.com/', 'condition' => 'applicationContext == "Production/Staging"'],
                ['base' => 'https://partner.example.org/', 'condition' => 'applicationContext == "Production"'],
                // Unresolved %env()% placeholder (TYPO3 keeps the literal text
                // when the variable is unset) — must NOT surface as a domain
                ['base' => 'https://%env(UNSET_MCP_TEST_HOST)%/', 'condition' => 'applicationContext == "Development"'],
            ],
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ]);

        // Site 2: second site sharing one host with site 1's variant (dedup)
        $this->writeSiteConfiguration('second-site', [
            'rootPageId' => 2,
            'base' => 'https://www.second.example/',
            'websiteTitle' => 'Second Site',
            'baseVariants' => [
                ['base' => 'https://stage.example.com/', 'condition' => 'applicationContext == "Production/Staging"'],
            ],
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ]);
    }

    /**
     * Test that mcp:domains outputs every base and baseVariant host, deduplicated
     */
    public function testOutputsAllSiteDomainsAsJson(): void
    {
        $commandTester = new CommandTester(new DomainsCommand('mcp:domains'));
        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode, $commandTester->getDisplay());

        $decoded = json_decode($commandTester->getDisplay(), true);
        $this->assertIsArray($decoded, 'Output must be valid JSON: ' . $commandTester->getDisplay());

        $this->assertSame(
            [
                'domains' => [
                    'partner.example.org',
                    'stage.example.com',
                    'www.example.com',
                    'www.second.example',
                ],
            ],
            $decoded
        );
    }

    /**
     * Write a site configuration YAML into the test instance
     */
    private function writeSiteConfiguration(string $identifier, array $configuration): void
    {
        $siteDir = $this->instancePath . '/typo3conf/sites/' . $identifier;
        GeneralUtility::mkdir_deep($siteDir);
        GeneralUtility::writeFile($siteDir . '/config.yaml', Yaml::dump($configuration, 99, 2), true);
    }
}
