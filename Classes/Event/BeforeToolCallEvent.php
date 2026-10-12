<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

/**
 * PSR-14 event dispatched before any MCP tool is executed.
 *
 * Dispatched in AbstractTool::execute() for every tool call, before the tool
 * initializes its context (e.g. the workspace) and before it touches any data.
 * This allows other extensions to:
 * - Veto a tool call with a reason (e.g. a tool that must not be used in a
 *   project, or arguments that address something the extension protects)
 * - Log or audit tool calls
 *
 * A vetoed call is not executed; the client gets an error result with the reason.
 * The backend user of the call is available through the TYPO3 Context API.
 */
final class BeforeToolCallEvent
{
    private bool $vetoed = false;
    private ?string $vetoReason = null;

    /**
     * @param string $toolName The tool name as exposed to the client (e.g. "BrowseFolder", "WriteTable")
     * @param array $arguments The arguments of the call, as sent by the client
     */
    public function __construct(
        private readonly string $toolName,
        private readonly array $arguments,
    ) {}

    public function getToolName(): string
    {
        return $this->toolName;
    }

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function veto(string $reason): void
    {
        $this->vetoed = true;
        $this->vetoReason = $reason;
    }

    public function isVetoed(): bool
    {
        return $this->vetoed;
    }

    public function getVetoReason(): ?string
    {
        return $this->vetoReason;
    }
}
