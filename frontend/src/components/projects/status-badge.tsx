import type { ProjectStatus } from "@/lib/api/types";

export function StatusBadge({ status }: { status: ProjectStatus }) {
  const archived = status === "ARCHIVED";
  return (
    <span
      className={
        archived
          ? "bg-muted text-muted-foreground inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
          : "inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800"
      }
    >
      {archived ? "Archived" : "Active"}
    </span>
  );
}
