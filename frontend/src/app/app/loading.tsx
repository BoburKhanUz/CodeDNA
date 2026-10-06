/**
 * Shown inside the app shell while a page loads. Deliberately not at the root:
 * a Suspense boundary above the /app layout would turn its session redirect
 * into a streamed 200 instead of a real HTTP 307 to /login.
 */
export default function Loading() {
  return (
    <div
      role="status"
      aria-live="polite"
      className="text-muted-foreground flex flex-1 items-center justify-center p-6 text-sm"
    >
      Loading your workspace…
    </div>
  );
}
