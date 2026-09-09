<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Base URL resolution from site configuration for contexts without an HTTP request.
 *
 * Request-based resolution lives in {@see \Hn\McpServer\Http\RequestUrlTrait}
 * (upstream, subdirectory-aware via NormalizedParams). This service covers the
 * remaining cases: CLI commands (mcp:oauth) and tool fallbacks when no request
 * is available.
 */
class BaseUrlService
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * CLI fallback — no HTTP request available.
     *
     * Uses the configured site (siteRootPageId) to determine the base URL.
     * Eliminates the "first site wins" ambiguity in multi-site setups.
     */
    public function getBaseUrlFromSiteConfiguration(): string
    {
        $site = $this->getConfiguredSite();
        if ($site !== null) {
            return rtrim((string)$site->getBase(), '/');
        }

        // Fallback for siteRootPageId = 0: first site with absolute URL
        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = rtrim((string)$site->getBase(), '/');
            if (str_starts_with($base, 'http')) {
                return $base;
            }
        }

        throw new \RuntimeException(
            'No site with absolute base URL found. Configure siteRootPageId in MCP Server extension settings.'
        );
    }

    public function getConfiguredSite(): ?Site
    {
        $rootPageId = (int)$this->extensionConfiguration->get('mcp_server', 'siteRootPageId');
        if ($rootPageId === 0) {
            return null;
        }
        return $this->siteFinder->getSiteByRootPageId($rootPageId);
    }
}
