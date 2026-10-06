import type { ProgrammingLanguage, SupportedLocale } from "@/lib/api/types";

/** Display labels for the profile form's choices. Values come from lib/api/types. */

export const LOCALE_LABELS: Record<SupportedLocale, string> = {
  en: "English",
  uz: "Oʻzbekcha",
  ru: "Русский",
};

export const PROGRAMMING_LANGUAGE_LABELS: Record<ProgrammingLanguage, string> = {
  c: "C",
  cpp: "C++",
  csharp: "C#",
  dart: "Dart",
  elixir: "Elixir",
  go: "Go",
  java: "Java",
  javascript: "JavaScript",
  kotlin: "Kotlin",
  php: "PHP",
  python: "Python",
  ruby: "Ruby",
  rust: "Rust",
  scala: "Scala",
  swift: "Swift",
  typescript: "TypeScript",
};

/**
 * Time zone suggestions from the browser. Suggestions only: the server
 * validates against canonical IANA names, and a browser may list a legacy
 * alias (e.g. "Asia/Calcutta") that the server rejects with a field error.
 */
export function timeZoneSuggestions(): string[] {
  try {
    const zones = Intl.supportedValuesOf("timeZone");
    return zones.includes("UTC") ? zones : ["UTC", ...zones];
  } catch {
    return ["UTC"];
  }
}
