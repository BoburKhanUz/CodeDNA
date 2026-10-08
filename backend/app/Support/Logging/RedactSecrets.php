<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Last line of defense for log hygiene (Phase 25,
 * docs/operations/security-baseline.md#logging): code never logs secrets on
 * purpose, and this processor removes any that slip through, from the
 * message and from context and extra data at any depth.
 *
 * Removed: values under secret-like keys (passwords, tokens, cookies,
 * session IDs, API keys, private keys, signatures, DSNs), bearer and basic
 * credentials, URL user-info, private-key blocks, provider token formats
 * (GitHub, OpenAI-compatible, AWS-style access keys) and team invitation
 * tokens in request paths.
 *
 * Used as a Monolog processor (the "monolog" driver's "processors"); the
 * file drivers, which ignore "processors", get it through RedactSecretsTap.
 */
final class RedactSecrets implements ProcessorInterface
{
    public const MARK = '[redacted]';

    private const SECRET_KEY = '/(pass(word|wd|phrase)?|secret|token|api[_-]?key|authori[sz]ation|cookie|session|private[_-]?key|signature|credential|dsn|otp)/i';

    private const MAX_DEPTH = 8;

    /** @var array<string, string> */
    private const PATTERNS = [
        // Private key blocks (GitHub App keys and the like).
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|$)/s' => self::MARK,
        // Authorization header values.
        '/\b(Bearer|Basic|token)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 '.self::MARK,
        // user:password@ in URLs (Redis, database and storage DSNs).
        '#(\b[a-z][a-z0-9+.-]*://)[^/\s:@]*:[^/\s@]*@#i' => '$1'.self::MARK.'@',
        // key=value and "key": "value" pairs with secret-like keys.
        '/\b([A-Za-z0-9_-]*(?:password|passwd|secret|token|api[_-]?key|cookie|session|signature)[A-Za-z0-9_-]*)("?\s*[=:]\s*"?)[^\s"&,;]+/i' => '$1$2'.self::MARK,
        // GitHub tokens (ghp_, gho_, ghu_, ghs_, ghr_, github_pat_).
        '/\b(gh[opusr]_[A-Za-z0-9]{16,}|github_pat_[A-Za-z0-9_]{20,})\b/' => self::MARK,
        // OpenAI-compatible API keys.
        '/\bsk-[A-Za-z0-9_-]{16,}\b/' => self::MARK,
        // AWS-style (and R2 S3-compatible) access key IDs.
        '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/' => self::MARK,
        // Team invitation tokens in request paths (Phase 24).
        '#(/organizations/invitations/)[^/?\s"]+#' => '$1'.self::MARK,
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::text($record->message),
            context: self::values($record->context, 0),
            extra: self::values($record->extra, 0),
        );
    }

    public static function text(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function values(array $values, int $depth): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 && $value !== null && $value !== '' && ! is_bool($value)) {
                $values[$key] = self::MARK;
            } elseif (is_string($value)) {
                $values[$key] = self::text($value);
            } elseif (is_array($value)) {
                $values[$key] = $depth >= self::MAX_DEPTH ? self::MARK : self::values($value, $depth + 1);
            }
        }

        return $values;
    }
}
