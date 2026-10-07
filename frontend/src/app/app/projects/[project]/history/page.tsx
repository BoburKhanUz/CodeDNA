import type { Metadata } from "next";

import { HistoryView } from "@/components/history/history-view";

export const metadata: Metadata = { title: "Historical DNA" };

/**
 * A project's historical DNA: every stored code assessment as it was
 * recorded. The ID is validated by the client component; the API is
 * owner-only.
 */
export default async function ProjectHistoryPage({ params }: { params: Promise<{ project: string }> }) {
  const { project } = await params;
  return <HistoryView projectId={project} />;
}
