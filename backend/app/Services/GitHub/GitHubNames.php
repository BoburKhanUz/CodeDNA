<?php

declare(strict_types=1);

namespace App\Services\GitHub;

/**
 * Syntax checks for GitHub identifiers before they are used in a request
 * path. They are never interpolated into a shell command (there is none);
 * a value that fails here is never sent to GitHub at all.
 */
final class GitHubNames
{
    /** A GitHub user or organization login. */
    public static function isLogin(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/D', $value) === 1;
    }

    /** A repository name (without the owner). */
    public static function isRepositoryName(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $value) === 1 && ! in_array($value, ['.', '..'], true);
    }

    /**
     * A branch name: a conservative subset of git's ref rules (no "..",
     * "@{", leading "-", "/" or ".", empty components, ".lock" endings,
     * control characters, spaces or shell metacharacters).
     */
    public static function isBranch(mixed $value): bool
    {
        return is_string($value)
            && preg_match('#^[A-Za-z0-9._/-]{1,255}$#D', $value) === 1
            && preg_match('#(^[-/.]|/$|//|\.\.|\.lock$|/\.|@\{)#', $value) !== 1;
    }

    public static function isCommitSha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{40}$/D', $value) === 1;
    }

    /** "owner/name" as a URL path, each part already validated. */
    public static function repositoryPath(string $owner, string $name): string
    {
        return rawurlencode($owner).'/'.rawurlencode($name);
    }

    /** A validated branch as a URL path: every component encoded, slashes kept. */
    public static function branchPath(string $branch): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $branch)));
    }
}
