<?php

declare(strict_types=1);

namespace Tests\Unit\GitHub;

use App\Services\GitHub\GitHubError;
use App\Services\GitHub\GitHubException;
use App\Services\GitHub\GitHubNames;
use App\Services\GitHub\GitHubRepository;
use App\Support\Sources\ZipComment;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ZipBuilder;
use Tests\TestCase;

/**
 * GitHub identifiers are checked before they reach a URL path; repository
 * metadata is checked field by field.
 */
final class GitHubNamesTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function branches(): array
    {
        return [
            'main' => ['main', true],
            'nested' => ['feature/new-parser', true],
            'dots and dashes' => ['release-1.2.3', true],
            'underscore' => ['hot_fix', true],
            'leading dash' => ['-rf', false],
            'leading dot' => ['.hidden', false],
            'leading slash' => ['/main', false],
            'trailing slash' => ['main/', false],
            'double slash' => ['a//b', false],
            'double dot' => ['a..b', false],
            'component dot' => ['a/.b', false],
            'lock suffix' => ['main.lock', false],
            'reflog' => ['a@{1}', false],
            'space' => ['a b', false],
            'shell' => ['$(id)', false],
            'backtick' => ['`id`', false],
            'semicolon' => ['a;b', false],
            'pipe' => ['a|b', false],
            'newline' => ["a\nb", false],
            'nul' => ["a\0b", false],
            'percent' => ['a%2Fb', false],
            'unicode' => ['ветка', false],
            'empty' => ['', false],
            'too long' => [str_repeat('a', 256), false],
            'longest' => [str_repeat('a', 255), true],
        ];
    }

    #[DataProvider('branches')]
    public function test_branch_names(string $branch, bool $valid): void
    {
        $this->assertSame($valid, GitHubNames::isBranch($branch));
    }

    public function test_paths_are_encoded_component_by_component(): void
    {
        $this->assertSame('feature/new-parser', GitHubNames::branchPath('feature/new-parser'));
        $this->assertSame('octo-org/billing.service', GitHubNames::repositoryPath('octo-org', 'billing.service'));
        $this->assertTrue(GitHubNames::isCommitSha(str_repeat('a', 40)));
        $this->assertFalse(GitHubNames::isCommitSha(str_repeat('A', 40)));
        $this->assertFalse(GitHubNames::isCommitSha('HEAD'));
        $this->assertFalse(GitHubNames::isLogin('-bad'));
        $this->assertFalse(GitHubNames::isRepositoryName('..'));
        $this->assertFalse(GitHubNames::isRepositoryName('a/b'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function malformedRepositories(): array
    {
        $good = ['id' => 7, 'name' => 'repo', 'full_name' => 'octo/repo', 'owner' => ['login' => 'octo'], 'private' => false, 'archived' => false, 'default_branch' => 'main'];

        return [
            'string id' => [['id' => '7'] + $good],
            'zero id' => [['id' => 0] + $good],
            'full name mismatch' => [['full_name' => 'evil/repo'] + $good],
            'bad owner' => [['owner' => ['login' => '../x']] + $good],
            'bad name' => [['name' => 'a b', 'full_name' => 'octo/a b'] + $good],
            'bad default branch' => [['default_branch' => '$(id)'] + $good],
            'private not bool' => [['private' => 'false'] + $good],
            'missing archived' => [array_diff_key($good, ['archived' => true])],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('malformedRepositories')]
    public function test_malformed_repository_metadata_is_refused(array $data): void
    {
        try {
            GitHubRepository::fromApi($data);
            $this->fail('accepted');
        } catch (GitHubException $e) {
            $this->assertSame(GitHubError::InvalidResponse, $e->error);
        }
    }

    public function test_only_the_kept_fields_survive(): void
    {
        $repository = GitHubRepository::fromApi(['id' => 7, 'name' => 'repo', 'full_name' => 'octo/repo', 'owner' => ['login' => 'octo', 'id' => 1], 'private' => true,
            'archived' => true, 'default_branch' => 'main', 'clone_url' => 'https://x', 'permissions' => ['admin' => true], 'disabled' => true]);

        $this->assertSame(['id' => 7, 'owner' => 'octo', 'name' => 'repo', 'full_name' => 'octo/repo', 'private' => true, 'archived' => true, 'default_branch' => 'main'], $repository->toArray());
        $this->assertTrue($repository->disabled);
    }

    public function test_the_zip_comment_is_read_from_the_end_record(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'zc-');
        try {
            file_put_contents($path, (new ZipBuilder)->file('a.py', 'x')->comment(str_repeat('a', 40))->build());
            $this->assertSame(str_repeat('a', 40), ZipComment::read($path));
            file_put_contents($path, (new ZipBuilder)->file('a.py', "PK\x05\x06 decoy")->build());
            $this->assertNull(ZipComment::read($path));
            file_put_contents($path, 'tiny');
            $this->assertNull(ZipComment::read($path));
        } finally {
            @unlink($path);
        }
    }
}
