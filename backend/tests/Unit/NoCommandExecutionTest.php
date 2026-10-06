<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CodeDNA never executes anything: not uploaded source, not tools against it
 * (docs/architecture/data-flow.md#source-upload). Application code must not
 * contain process-execution or dynamic-evaluation primitives.
 */
final class NoCommandExecutionTest extends TestCase
{
    private const FORBIDDEN = '/\b(exec|shell_exec|system|passthru|proc_open|popen|pcntl_exec|eval|assert)\s*\(|`[^`]*`|\bnew\s+(\\\\?Symfony\\\\Component\\\\Process\\\\)?Process\s*\(|Process::(run|start|pipe)|\bProcess\s*::/';

    public function test_application_code_contains_no_command_execution(): void
    {
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app'));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = $this->withoutCommentsAndStrings((string) file_get_contents($file->getPathname()));
            if (preg_match(self::FORBIDDEN, $code, $match) === 1) {
                $offenders[] = $file->getFilename().': '.trim($match[0]);
            }
        }

        $this->assertSame([], $offenders);
    }

    /**
     * Comments and string literals may mention these words (e.g. docblocks);
     * only real code counts. Backtick shell syntax is kept (T_ENCAPSED parts
     * between backticks are not strings).
     */
    private function withoutCommentsAndStrings(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
