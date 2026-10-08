"use client";

import { useSyncExternalStore } from "react";

function subscribe(onChange: () => void): () => void {
  document.addEventListener("visibilitychange", onChange);
  return () => document.removeEventListener("visibilitychange", onChange);
}

const visible = () => document.visibilityState !== "hidden";

/**
 * Whether the page is visible (Phase 26). Polling hooks pause while the tab is
 * hidden and resume on their next tick once it is shown again: a background
 * tab never keeps asking the API for a status nobody is reading.
 * Rendered on the server as visible.
 */
export function usePageVisible(): boolean {
  return useSyncExternalStore(subscribe, visible, () => true);
}
