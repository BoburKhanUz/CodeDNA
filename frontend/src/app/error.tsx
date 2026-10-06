"use client";

import { Button } from "@/components/ui/button";

/**
 * Shown when rendering fails, e.g. the API is unreachable while checking the
 * session. Details are never displayed; the digest lets a developer find the
 * server log entry.
 */
export default function ErrorPage({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <main className="flex flex-1 flex-col items-center justify-center gap-4 p-6 text-center">
      <div role="alert" className="grid max-w-md gap-2">
        <h1 className="text-xl font-semibold">Unable to connect to CodeDNA</h1>
        <p className="text-muted-foreground text-sm">
          We couldn&apos;t load this page. Check your connection and try again.
        </p>
        {error.digest ? (
          <p className="text-muted-foreground text-xs">
            Reference: <code className="font-mono">{error.digest}</code>
          </p>
        ) : null}
      </div>
      <Button onClick={reset}>Try again</Button>
    </main>
  );
}
