<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Http\RequestUrlTrait;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for centralizing site and domain information
 */
class SiteInformationService
{
    use RequestUrlTrait;

    protected SiteFinder $siteFinder;
    protected ?ServerRequestInterface $currentRequest = null;

    public function __construct()
    {
        $this->siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Set the current HTTP request for context
     */
    public function setCurrentRequest(?ServerRequestInterface $request): void
    {
        $this->currentRequest = $request;
    }

    /**
     * Turn a possibly-relative URL into an absolute one.
     *
     * Order of preference: scheme/host of the active HTTP request, then the
     * first configured site whose base carries a host, then TYPO3's
     * TYPO3_REQUEST_HOST environment value. Returns the input unchanged when
     * none of those resolve a host (e.g. CLI/stdio without a configured site)
     * — better an honest relative URL than an invented one.
     *
     * @param string|null $url Anything from a relative path ("fileadmin/x.jpg")
     *                         to a leading-slash path ("/x.jpg") to an already
     *                         absolute URL.
     */
    public function makeAbsoluteUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        // Already absolute (http://, https://, data:, etc.)
        if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url) || str_starts_with($url, '//')) {
            return $url;
        }

        $base = $this->resolveRequestOrSiteBase();
        if ($base === null) {
            return $url;
        }

        return $base . '/' . ltrim($url, '/');
    }

    /**
     * Returns "<scheme>://<host>" from the active request, or the first site
     * whose base has a host, or TYPO3_REQUEST_HOST as a last resort.
     */
    protected function resolveRequestOrSiteBase(): ?string
    {
        if ($this->currentRequest !== null) {
            // Same resolution as the HTTP endpoints: NormalizedParams-based,
            // which keeps non-standard ports, reverse proxy configuration and
            // subdirectory installations intact.
            $base = $this->getRequestBaseUrl($this->currentRequest);
            if (parse_url($base, PHP_URL_HOST) !== null) {
                return $base;
            }
        }

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteBase = $site->getBase();
                if ($siteBase->getHost() !== '') {
                    $scheme = $siteBase->getScheme() ?: 'https';
                    $port = $siteBase->getPort();
                    return $scheme . '://' . $siteBase->getHost() . ($port ? ':' . $port : '');
                }
            }
        } catch (\Throwable) {
            // Ignore and fall through to the env-based fallback
        }

        $envHost = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        if (is_string($envHost) && $envHost !== '') {
            // TYPO3_REQUEST_HOST is "<scheme>://<host>" — accept only when the host
            // segment is actually populated, otherwise we'd build URLs like
            // "http:///fileadmin/x.jpg" in CLI/test contexts without a server name.
            $parts = parse_url($envHost);
            if (!empty($parts['host'])) {
                return $envHost;
            }
        }
        return null;
    }

    /**
     * Get all configured domains from TYPO3 sites
     *
     * @return array Array of unique domain names
     */
    public function getAllDomains(): array
    {
        $sites = $this->siteFinder->getAllSites();
        $domains = [];

        foreach ($sites as $site) {
            $base = $site->getBase();
            $host = $base->getHost();

            // Add main domain if it's not empty and not just a path. Note:
            // when a baseVariant condition matches the current application
            // context, the Site entity already resolved getBase() to that
            // variant — the configured main base then only exists in the
            // raw configuration (collected below).
            if ($this->isPlausibleHostname($host)) {
                $domains[] = $host;
            }

            // The Site entity has no getter for base variants; they only
            // exist in the raw site configuration. Collect the configured
            // base and every baseVariant host so ALL domains the instance
            // serves are listed, independent of the current context.
            // Scheme-less bases yield no host from parse_url and are omitted;
            // TYPO3's site handling requires a scheme (or a leading slash),
            // so such entries are broken configuration, not real domains.
            $configuration = $site->getConfiguration();
            $configuredBases = array_merge(
                [$configuration['base'] ?? ''],
                array_column($configuration['baseVariants'] ?? [], 'base')
            );
            foreach ($configuredBases as $configuredBase) {
                $configuredHost = (string)parse_url((string)$configuredBase, PHP_URL_HOST);
                if ($this->isPlausibleHostname($configuredHost) && !in_array($configuredHost, $domains, true)) {
                    $domains[] = $configuredHost;
                }
            }
        }

        // If no domains found but we have a current request, try to use the host header
        if (empty($domains) && $this->currentRequest !== null) {
            $host = $this->currentRequest->getHeaderLine('Host');
            if (!empty($host)) {
                $domains[] = $host;
            }
        }

        return array_unique($domains);
    }

    /**
     * Whether a string is a syntactically plausible hostname. Site bases may
     * carry unresolved %env(...)% placeholders (TYPO3 leaves the literal text
     * in place when the variable is unset) — those must not surface as
     * domains, because a consumer validating hosts with this rule would
     * reject the whole output over one malformed entry.
     */
    protected function isPlausibleHostname(string $host): bool
    {
        return $host !== '' && preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$/', $host) === 1;
    }

    /**
     * Get formatted text listing all available domains for tool descriptions
     * 
     * @return string Formatted text describing available domains
     */
    public function getAvailableDomainsText(): string
    {
        $domains = $this->getAllDomains();
        
        if (empty($domains)) {
            return 'No specific domains configured. Use page IDs or relative paths.';
        }
        
        if (count($domains) === 1) {
            return 'Available domain: ' . $domains[0];
        }
        
        return 'Available domains: ' . implode(', ', $domains);
    }
}
