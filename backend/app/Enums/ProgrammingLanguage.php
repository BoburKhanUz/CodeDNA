<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Programming languages a developer may name as their preferred language.
 *
 * A self-declared preference, not an analysis result. Identifiers use the
 * same lowercase format as projects.language. The list only grows: values
 * are stored as plain strings (no cast), so removing a case never breaks
 * existing rows.
 */
enum ProgrammingLanguage: string
{
    case C = 'c';
    case Cpp = 'cpp';
    case CSharp = 'csharp';
    case Dart = 'dart';
    case Elixir = 'elixir';
    case Go = 'go';
    case Java = 'java';
    case JavaScript = 'javascript';
    case Kotlin = 'kotlin';
    case Php = 'php';
    case Python = 'python';
    case Ruby = 'ruby';
    case Rust = 'rust';
    case Scala = 'scala';
    case Swift = 'swift';
    case TypeScript = 'typescript';
}
