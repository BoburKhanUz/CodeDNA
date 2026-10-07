<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use App\Actions\GitHub\CompleteGitHubAuthorization;
use App\Actions\GitHub\ConnectGitHubRepository;
use App\Services\GitHub\GitHubApi;
use App\Services\GitHub\GitHubHttp;
use App\Services\GitHub\GitHubSettings;
use App\Services\GitHub\GitHubUserTokens;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;
use Tests\TestCase;

/**
 * Phase 21: every parameter that carries a GitHub token, OAuth code or
 * state, or the App's private key is #[\SensitiveParameter], so a stack
 * trace (logs, failed_jobs) never records its value; zend.exception_ignore_args
 * is the second layer.
 */
final class SensitiveParametersTest extends TestCase
{
    private const SECRET_NAMES = ['token', 'userToken', 'installationToken', 'refreshToken', 'accessToken', 'code', 'state', 'privateKey'];

    public function test_every_secret_parameter_is_marked_sensitive(): void
    {
        $checked = 0;
        foreach ([GitHubApi::class, GitHubHttp::class, GitHubSettings::class, GitHubUserTokens::class, CompleteGitHubAuthorization::class, ConnectGitHubRepository::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                /** @var ReflectionMethod $method */
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if (in_array($parameter->getName(), self::SECRET_NAMES, true)) {
                        $checked++;
                        $this->assertNotEmpty($parameter->getAttributes(SensitiveParameter::class), "{$class}::{$method->getName()}(\${$parameter->getName()})");
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(15, $checked);
    }

    public function test_a_trace_never_contains_the_token(): void
    {
        $secret = 'ghu_'.str_repeat('S', 36);
        $thrower = static function (#[SensitiveParameter] string $token): never {
            throw new \RuntimeException('boom');
        };
        try {
            $thrower($secret);
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('ghu_SSS', $e->getTraceAsString());
            $this->assertStringNotContainsString('ghu_SSS', print_r($e->getTrace(), true));
        }
    }
}
