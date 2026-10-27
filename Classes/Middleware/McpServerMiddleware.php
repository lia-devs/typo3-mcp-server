<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use Hn\McpServer\Http\CorsHeadersTrait;
use Hn\McpServer\Http\FileUploadEndpoint;
use Hn\McpServer\Http\RequestUrlTrait;
use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Http\OAuthMetadataEndpoint;
use Hn\McpServer\Http\OAuthRegisterEndpoint;
use Hn\McpServer\Http\OAuthResourceMetadataEndpoint;
use Hn\McpServer\Http\OAuthAuthServerMetadataEndpoint;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unified PSR-15 Middleware to handle all MCP Server routes
 * Provides clean URLs for MCP protocol, OAuth flow, and discovery endpoints
 */
class McpServerMiddleware implements MiddlewareInterface
{
    use RequestUrlTrait;

    private Context $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Strip the site path prefix so routing works in subdirectory installations
        $sitePath = $this->getRequestSitePath($request);
        if ($sitePath !== '') {
            if ($path === $sitePath) {
                $path = '/';
            } elseif (str_starts_with($path, $sitePath . '/')) {
                $path = substr($path, strlen($sitePath));
            }
        }

        // Both endpoints below run MCP tools, and those reach into core APIs that
        // still read $GLOBALS['TYPO3_REQUEST']: DataHandler's RTE transformation,
        // for instance, validates href values through DefaultSanitizerBuilder,
        // whose closure throws a RuntimeException (1775675289) without an active
        // request - saving a bodytext that contains a t3:// link fails because of
        // it. This middleware answers the routes itself, so the core
        // RequestHandler that usually publishes the request never runs for them.
        // It is registered after normalized-params-attribute, so the request
        // carries the attributes those APIs expect.
        if ($path === '/mcp' || $path === '/mcp_upload') {
            $GLOBALS['TYPO3_REQUEST'] = $request;
        }

        // Route to appropriate endpoint
        return match($path) {
            // Main MCP endpoint
            '/mcp' => GeneralUtility::makeInstance(McpEndpoint::class)($request),

            // Pre-signed file upload target (UploadFile tool)
            '/mcp_upload' => GeneralUtility::makeInstance(FileUploadEndpoint::class)($request),

            // OAuth endpoints
            '/mcp_oauth/authorize' => GeneralUtility::makeInstance(OAuthAuthorizeEndpoint::class)($request),
            '/mcp_oauth/token' => GeneralUtility::makeInstance(OAuthTokenEndpoint::class)($request),
            '/mcp_oauth/metadata' => GeneralUtility::makeInstance(OAuthMetadataEndpoint::class)($request),
            '/mcp_oauth/register' => GeneralUtility::makeInstance(OAuthRegisterEndpoint::class)($request),
            '/mcp_oauth/resource' => GeneralUtility::makeInstance(OAuthResourceMetadataEndpoint::class)($request),
            
            // OAuth discovery endpoints (.well-known)
            '/.well-known/oauth-authorization-server' => GeneralUtility::makeInstance(OAuthAuthServerMetadataEndpoint::class)($request),
            '/.well-known/oauth-protected-resource' => GeneralUtility::makeInstance(OAuthResourceMetadataEndpoint::class)($request),
            '/.well-known/oauth-protected-resource/mcp' => GeneralUtility::makeInstance(OAuthResourceMetadataEndpoint::class)($request),

            
            // All other routes
            default => $handler->handle($request),
        };
    }
    
}