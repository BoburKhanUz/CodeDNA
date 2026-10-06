"use client";

import { CheckCircle2, Upload } from "lucide-react";
import { useRouter } from "next/navigation";
import { useRef, useState, type ChangeEvent, type FormEvent } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { isApiError } from "@/lib/api/errors";
import { MAX_ARCHIVE_BYTES, type SourceSnapshot } from "@/lib/api/types";
import { uploadSource } from "@/lib/projects/client";
import { formatBytes } from "@/lib/projects/format";

type Phase =
  | { status: "idle" }
  | { status: "uploading"; progress: number }
  | { status: "done"; snapshot: SourceSnapshot }
  | { status: "failed"; error: unknown };

function newIdempotencyKey(): string | undefined {
  return typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID() : undefined;
}

/**
 * Uploads a ZIP archive as a new source snapshot. Nothing is analyzed: the
 * result is an immutable snapshot ("Source snapshot v1 created").
 *
 * One idempotency key is kept per chosen file, so retrying after a network
 * failure can never create a second snapshot of the same upload.
 */
export function UploadSource({ projectId, onUploaded }: { projectId: string; onUploaded: (snapshot: SourceSnapshot) => void }) {
  const router = useRouter();
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [idempotencyKey, setIdempotencyKey] = useState<string | undefined>(undefined);
  const [phase, setPhase] = useState<Phase>({ status: "idle" });

  function onChoose(event: ChangeEvent<HTMLInputElement>) {
    const chosen = event.target.files?.[0] ?? null;
    setPhase({ status: "idle" });
    setFile(chosen);
    setIdempotencyKey(chosen ? newIdempotencyKey() : undefined);
    setFileError(chosen ? checkFile(chosen) : null);
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (file === null) {
      setFileError("Choose a ZIP archive.");
      return;
    }
    const problem = checkFile(file);
    setFileError(problem);
    if (problem) return;

    setPhase({ status: "uploading", progress: 0 });
    try {
      const snapshot = await uploadSource(projectId, file, {
        idempotencyKey,
        onProgress: (progress) => setPhase({ status: "uploading", progress }),
      });
      setPhase({ status: "done", snapshot });
      setFile(null);
      setIdempotencyKey(undefined);
      if (inputRef.current) inputRef.current.value = "";
      onUploaded(snapshot);
    } catch (error) {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      setPhase({ status: "failed", error });
    }
  }

  const uploading = phase.status === "uploading";
  const percent = uploading ? Math.round(phase.progress * 100) : 0;

  return (
    <form onSubmit={onSubmit} noValidate aria-busy={uploading} className="grid max-w-xl gap-3">
      <div className="grid gap-2">
        <Label htmlFor="archive">ZIP archive</Label>
        <input
          ref={inputRef}
          id="archive"
          name="archive"
          type="file"
          accept=".zip,application/zip"
          onChange={onChoose}
          disabled={uploading}
          aria-invalid={fileError ? true : undefined}
          aria-describedby={fileError ? "archive-hint archive-error" : "archive-hint"}
          className="file:bg-secondary file:text-secondary-foreground text-sm file:mr-3 file:rounded-md file:border-0 file:px-3 file:py-1.5 file:text-sm file:font-medium"
        />
        <p id="archive-hint" className="text-muted-foreground text-xs">
          Up to {formatBytes(MAX_ARCHIVE_BYTES)}. The archive is stored as-is; nothing in it is run.
        </p>
        {fileError ? (
          <p id="archive-error" className="text-destructive text-sm">
            {fileError}
          </p>
        ) : null}
      </div>

      {uploading ? (
        <div className="grid gap-1">
          <div
            role="progressbar"
            aria-label="Upload progress"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent}
            className="bg-muted h-2 overflow-hidden rounded-full"
          >
            <div className="bg-primary h-full transition-[width]" style={{ width: `${percent}%` }} />
          </div>
          <p role="status" className="text-muted-foreground text-xs">
            {percent < 100 ? `Uploading… ${percent}%` : "Checking and storing the archive…"}
          </p>
        </div>
      ) : null}

      {phase.status === "failed" ? <ApiErrorAlert error={phase.error} /> : null}

      <div role="status" aria-live="polite">
        {phase.status === "done" ? (
          <p className="flex items-center gap-2 text-sm">
            <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
            Source snapshot v{phase.snapshot.version} created.
          </p>
        ) : null}
      </div>

      <Button type="submit" className="w-fit" disabled={uploading}>
        <Upload aria-hidden="true" />
        {uploading ? "Uploading…" : "Upload source"}
      </Button>
    </form>
  );
}

/** Early, client-side checks only; the server inspects the archive's content. */
function checkFile(file: File): string | null {
  if (!file.name.toLowerCase().endsWith(".zip")) {
    return "Choose a .zip file.";
  }
  if (file.size === 0) {
    return "The file is empty.";
  }
  if (file.size > MAX_ARCHIVE_BYTES) {
    return `The file is larger than ${formatBytes(MAX_ARCHIVE_BYTES)}.`;
  }
  return null;
}
