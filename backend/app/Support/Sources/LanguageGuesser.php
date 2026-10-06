<?php

declare(strict_types=1);

namespace App\Support\Sources;

use App\Enums\ProgrammingLanguage;

/**
 * A file-name heuristic for a snapshot's `primary_language`: the language
 * with the most files by extension, ignoring dependency and VCS directories.
 *
 * This is not analysis. Nothing is parsed or read. When no recognized source
 * file exists, or two languages tie, the result is null instead of a guess.
 */
final class LanguageGuesser
{
    private const EXTENSIONS = [
        'c' => ProgrammingLanguage::C, 'h' => ProgrammingLanguage::C,
        'cpp' => ProgrammingLanguage::Cpp, 'cc' => ProgrammingLanguage::Cpp, 'cxx' => ProgrammingLanguage::Cpp,
        'hpp' => ProgrammingLanguage::Cpp, 'hh' => ProgrammingLanguage::Cpp,
        'cs' => ProgrammingLanguage::CSharp,
        'dart' => ProgrammingLanguage::Dart,
        'ex' => ProgrammingLanguage::Elixir, 'exs' => ProgrammingLanguage::Elixir,
        'go' => ProgrammingLanguage::Go,
        'java' => ProgrammingLanguage::Java,
        'js' => ProgrammingLanguage::JavaScript, 'jsx' => ProgrammingLanguage::JavaScript,
        'mjs' => ProgrammingLanguage::JavaScript, 'cjs' => ProgrammingLanguage::JavaScript,
        'kt' => ProgrammingLanguage::Kotlin, 'kts' => ProgrammingLanguage::Kotlin,
        'php' => ProgrammingLanguage::Php,
        'py' => ProgrammingLanguage::Python,
        'rb' => ProgrammingLanguage::Ruby,
        'rs' => ProgrammingLanguage::Rust,
        'scala' => ProgrammingLanguage::Scala,
        'swift' => ProgrammingLanguage::Swift,
        'ts' => ProgrammingLanguage::TypeScript, 'tsx' => ProgrammingLanguage::TypeScript,
        'mts' => ProgrammingLanguage::TypeScript, 'cts' => ProgrammingLanguage::TypeScript,
    ];

    /** Third-party code and VCS data say nothing about the project's own language. */
    private const IGNORED_DIRECTORIES = ['node_modules', 'vendor', '.git', '__MACOSX', 'dist', 'build'];

    /**
     * @param  list<string>  $paths
     */
    public function guess(array $paths): ?string
    {
        $counts = [];
        foreach ($paths as $path) {
            $segments = explode('/', $path);
            if (array_intersect(array_slice($segments, 0, -1), self::IGNORED_DIRECTORIES) !== []) {
                continue;
            }
            $extension = strtolower(pathinfo((string) end($segments), PATHINFO_EXTENSION));
            $language = self::EXTENSIONS[$extension] ?? null;
            if ($language !== null) {
                $counts[$language->value] = ($counts[$language->value] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }
        arsort($counts);
        $top = array_slice($counts, 0, 2, true);
        $values = array_values($top);
        if (count($values) === 2 && $values[0] === $values[1]) {
            return null;
        }

        return (string) array_key_first($top);
    }
}
