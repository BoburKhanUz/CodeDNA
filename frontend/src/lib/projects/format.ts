import { PROGRAMMING_LANGUAGE_LABELS } from "@/lib/profile/options";
import type { ProgrammingLanguage } from "@/lib/api/types";

/** Display helpers for project pages. All output is plain text. */

export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  const units = ["KB", "MB", "GB"];
  let value = bytes / 1024;
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  return `${value >= 10 ? value.toFixed(0) : value.toFixed(1)} ${units[unit]}`;
}

/** Dates are shown in UTC, the API's time zone, so they never shift between viewers. */
export function formatDate(iso: string | null): string {
  if (iso === null) return "—";
  return new Intl.DateTimeFormat("en", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(iso));
}

export function formatDateTime(iso: string | null): string {
  if (iso === null) return "—";
  return `${new Intl.DateTimeFormat("en", { dateStyle: "medium", timeStyle: "short", timeZone: "UTC" }).format(new Date(iso))} UTC`;
}

export function languageLabel(language: string | null): string {
  if (language === null) return "—";
  return PROGRAMMING_LANGUAGE_LABELS[language as ProgrammingLanguage] ?? language;
}

export function sourceTypeLabel(sourceType: string): string {
  return sourceType === "REPOSITORY" ? "Repository" : "Upload";
}

/** Lowercase-hyphen slug for a project name (the user can edit it). */
export function slugify(name: string): string {
  return name
    .normalize("NFKD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
    .slice(0, 100)
    .replace(/-+$/g, "");
}
