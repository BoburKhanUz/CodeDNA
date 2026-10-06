<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ProgrammingLanguage;
use App\Support\Sources\LanguageGuesser;
use PHPUnit\Framework\TestCase;

final class LanguageGuesserTest extends TestCase
{
    public function test_picks_the_most_common_recognized_extension(): void
    {
        $this->assertSame('php', (new LanguageGuesser)->guess(['src/A.php', 'src/B.php', 'web/app.js', 'README.md']));
        $this->assertSame('typescript', (new LanguageGuesser)->guess(['a.ts', 'b.tsx', 'c.js']));
    }

    public function test_ignores_dependency_and_build_directories(): void
    {
        $this->assertSame('python', (new LanguageGuesser)->guess([
            'app/main.py',
            'node_modules/lib/a.js', 'node_modules/lib/b.js',
            'vendor/x/y.php', 'dist/bundle.js',
        ]));
    }

    public function test_returns_null_instead_of_guessing(): void
    {
        $this->assertNull((new LanguageGuesser)->guess([]));
        $this->assertNull((new LanguageGuesser)->guess(['README.md', 'Makefile', 'logo.png']));
        $this->assertNull((new LanguageGuesser)->guess(['a.go', 'b.rs']), 'a tie is not a guess');
    }

    public function test_only_returns_known_language_identifiers(): void
    {
        $known = array_map(static fn (ProgrammingLanguage $l): string => $l->value, ProgrammingLanguage::cases());

        foreach (['x.c', 'x.cpp', 'x.cs', 'x.dart', 'x.ex', 'x.go', 'x.java', 'x.js', 'x.kt', 'x.php', 'x.py', 'x.rb', 'x.rs', 'x.scala', 'x.swift', 'x.ts', 'X.PHP'] as $file) {
            $this->assertContains((new LanguageGuesser)->guess([$file]), $known, $file);
        }
    }
}
