<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Support\Logging\RedactSecrets;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Phase 25: secrets never reach the logs, even when code passes them by mistake.
 */
final class RedactSecretsTest extends TestCase
{
    /**
     * @param  array<mixed>  $context
     */
    private function redact(string $message, array $context = []): LogRecord
    {
        return (new RedactSecrets)(new LogRecord(new DateTimeImmutable, 'test', Level::Error, $message, $context, ['request' => ['cookie' => 'laravel_session=abc']]));
    }

    public function test_secret_like_context_keys_are_redacted_at_any_depth(): void
    {
        $record = $this->redact('login failed', [
            'email' => 'dev@example.test',
            'password' => 'hunter2-hunter2',
            'headers' => ['Authorization' => 'Bearer abc.def.ghi', 'Cookie' => 'codedna_session=xyz', 'accept' => 'application/json'],
            'github' => ['access_token' => 'gho_x', 'refresh_token' => 'ghr_y', 'installation_id' => 42],
            'storage' => ['SOURCE_STORAGE_SECRET_ACCESS_KEY' => 'r2-secret', 'api_key' => 'k', 'private_key' => '-----BEGIN'],
            'invitation_token' => 'tok', 'session_id' => 'sid', 'webhook_signature' => 'sig', 'has_password' => true,
        ]);

        $context = $record->context;
        $this->assertSame('dev@example.test', $context['email']);
        $this->assertSame('application/json', $context['headers']['accept']);
        $this->assertSame(42, $context['github']['installation_id']);
        $this->assertTrue($context['has_password'], 'booleans carry no secret');
        foreach ([$context['password'], $context['headers']['Authorization'], $context['headers']['Cookie'], $context['github']['access_token'],
            $context['github']['refresh_token'], $context['storage']['SOURCE_STORAGE_SECRET_ACCESS_KEY'], $context['storage']['api_key'],
            $context['storage']['private_key'], $context['invitation_token'], $context['session_id'], $context['webhook_signature'], $record->extra['request']['cookie']] as $value) {
            $this->assertSame(RedactSecrets::MARK, $value);
        }
    }

    public function test_secrets_inside_messages_and_strings_are_redacted(): void
    {
        // Provider-format values are assembled from obvious filler at run
        // time: no credential-shaped literal exists in the repository.
        $filler = static fn (string $prefix, int $length): string => $prefix.str_repeat('X', $length);
        $github = $filler('ghs_', 36);
        $pat = $filler('github_pat_', 40);
        $provider = $filler('sk-'.'proj-', 24);
        $accessKey = $filler('AK'.'IA', 16);
        $privateKey = '-----BEGIN RSA '.'PRIVATE KEY-----'."\nFILLERFILLERFILLER\n".'-----END RSA '.'PRIVATE KEY-----';
        $cases = [
            'Authorization: Bearer filler.filler.filler' => 'filler.filler.filler',
            'connect redis://default:filler-password@redis:6379/0 failed' => 'filler-password',
            'postgresql://codedna:filler-db-password@postgres:5432/codedna' => 'filler-db-password',
            "GitHub said {$github} expired" => $github,
            "pat {$pat}" => $pat,
            "provider rejected {$provider}" => $provider,
            "storage key {$accessKey} denied" => $accessKey,
            'GET /api/v1/organizations/invitations/filler-invitation-token/accept' => 'filler-invitation-token',
            'query password=filler&next=/' => 'password=filler',
            '{"client_secret": "filler-client-secret", "id": 3}' => 'filler-client-secret',
            $privateKey => 'FILLERFILLERFILLER',
            'Cookie codedna_session=filler-session-id; XSRF-TOKEN=filler' => 'filler-session-id',
        ];
        foreach ($cases as $message => $secret) {
            $record = $this->redact($message, ['detail' => $message]);
            $this->assertStringNotContainsString($secret, $record->message, $message);
            $this->assertStringNotContainsString($secret, $record->context['detail'], $message);
            $this->assertStringContainsString(RedactSecrets::MARK, $record->message, $message);
        }
    }

    public function test_ordinary_messages_are_untouched(): void
    {
        foreach (['analysis.completed id=01hz project=7 duration_ms=1200', 'Token budget exceeded for model', 'GET /api/v1/organizations/5/members 200'] as $message) {
            $this->assertSame($message, $this->redact($message)->message);
        }
    }
}
