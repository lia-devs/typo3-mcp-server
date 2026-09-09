<?php

declare(strict_types=1);

namespace Hn\McpServer\Command;

use Hn\McpServer\Service\SiteInformationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Prints every domain this TYPO3 instance serves as JSON.
 *
 * A site's hosts are spread over its base and its baseVariants, and reading
 * them out of the site configuration by hand is tedious and easy to get wrong
 * — country variants in particular are easy to miss. Anything that has to
 * decide whether a given URL belongs to this installation needs the complete
 * list, so this prints it:
 *
 *   vendor/bin/typo3 mcp:domains
 *   -> {"domains": ["www.example.com", "stage.example.com", ...]}
 */
class DomainsCommand extends Command
{
    protected function configure(): void
    {
        $this->setHelp(
            'Outputs {"domains": [...]} with every site base host and all baseVariant hosts, '
            . 'deduplicated. Intended for anything that has to map a URL back to this '
            . 'installation.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $domains = GeneralUtility::makeInstance(SiteInformationService::class)->getAllDomains();
        sort($domains);

        $output->writeln((string)json_encode(
            ['domains' => array_values($domains)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        return Command::SUCCESS;
    }
}
