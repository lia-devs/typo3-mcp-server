<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * Exception for a missing or unusable MCP workspace configuration
 *
 * Thrown when no workspace is configured for the user, the configured default
 * workspace does not exist, or the user may not write to the configured
 * workspace. The message tells the user what an administrator has to change,
 * so it is shown to the MCP client as is.
 */
class WorkspaceConfigurationException extends McpException
{
    public function __construct(string $message)
    {
        parent::__construct($message, $message, 409);
    }
}
