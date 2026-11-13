<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\FilePreviewEndpoint;
use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The /mcp/preview endpoint hands out file content for nothing but a bearer
 * token, so it has to hold the same line as /mcp: the token only counts while
 * the backend user behind it may act.
 */
class FilePreviewEndpointTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file_storage.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_filemounts.csv');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function createEditor(array $fields = []): int
    {
        GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime')->flush();

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users');
        $connection->insert('be_users', $fields + [
            'pid' => 0,
            'username' => 'editor_' . uniqid(),
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
            'deleted' => 0,
            'disable' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        return (int)$connection->lastInsertId();
    }

    protected function tokenFor(int $userUid): string
    {
        return GeneralUtility::makeInstance(OAuthService::class)->createToken($userUid, 'Preview test')['access_token'];
    }

    /**
     * @param array<string, scalar> $query
     */
    protected function requestPreview(string $token, array $query = []): ResponseInterface
    {
        $request = (new ServerRequest(new Uri('https://example.com/mcp/preview'), 'GET'))
            ->withQueryParams($query)
            ->withHeader('Authorization', 'Bearer ' . $token);

        return (new FilePreviewEndpoint())($request);
    }

    public function testAnActiveUserPassesAuthentication(): void
    {
        $response = $this->requestPreview($this->tokenFor(1));

        // 400 for the missing uid: the request got past authentication
        self::assertSame(400, $response->getStatusCode(), (string)$response->getBody());
    }

    public function testTheTokenOfADisabledUserIsRejected(): void
    {
        $token = $this->tokenFor($this->createEditor(['disable' => 1]));

        self::assertSame(401, $this->requestPreview($token, ['uid' => 1])->getStatusCode());
    }

    public function testTheTokenOfADeletedUserIsRejected(): void
    {
        $token = $this->tokenFor($this->createEditor(['deleted' => 1]));

        self::assertSame(401, $this->requestPreview($token, ['uid' => 1])->getStatusCode());
    }

    public function testTheTokenOfAnExpiredUserIsRejected(): void
    {
        $token = $this->tokenFor($this->createEditor(['endtime' => time() - 60]));

        self::assertSame(401, $this->requestPreview($token, ['uid' => 1])->getStatusCode());
    }

    public function testTheTokenOfARemovedUserIsRejected(): void
    {
        $userUid = $this->createEditor();
        $token = $this->tokenFor($userUid);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('be_users')
            ->delete('be_users', ['uid' => $userUid]);

        self::assertSame(401, $this->requestPreview($token, ['uid' => 1])->getStatusCode());
    }
}
