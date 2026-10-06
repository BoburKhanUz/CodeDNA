"use client";

import { FolderPlus } from "lucide-react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { StatusBadge } from "@/components/projects/status-badge";
import { Button } from "@/components/ui/button";
import { isApiError } from "@/lib/api/errors";
import type { Paginated, Project } from "@/lib/api/types";
import { listProjects } from "@/lib/projects/client";
import { formatDate, languageLabel, sourceTypeLabel } from "@/lib/projects/format";

type State = { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; page: Paginated<Project> };

/** The signed-in developer's projects, newest first, page by page. */
export function ProjectList() {
  const router = useRouter();
  const [page, setPage] = useState(1);
  const [state, setState] = useState<State>({ status: "loading" });

  const load = useCallback(
    (pageNumber: number) => {
      listProjects(pageNumber)
        .then((result) => setState({ status: "ready", page: result }))
        .catch((error: unknown) => {
          if (isApiError(error) && error.status === 401) {
            router.replace("/login");
            router.refresh();
            return;
          }
          setState({ status: "error", error });
        });
    },
    [router],
  );

  useEffect(() => load(page), [load, page]);

  function goTo(pageNumber: number) {
    setState({ status: "loading" });
    setPage(pageNumber);
  }

  if (state.status === "loading") {
    return (
      <p role="status" className="text-muted-foreground text-sm">
        Loading projects…
      </p>
    );
  }

  if (state.status === "error") {
    return (
      <div className="grid max-w-md gap-3">
        <ApiErrorAlert error={state.error} />
        <Button
          variant="outline"
          className="w-fit"
          onClick={() => {
            // Same page number: the effect would not re-run, so load directly.
            setState({ status: "loading" });
            load(page);
          }}
        >
          Try again
        </Button>
      </div>
    );
  }

  const { data: projects, meta } = state.page;

  if (meta.total === 0) {
    return (
      <div className="grid max-w-md justify-items-start gap-3 rounded-lg border border-dashed p-6">
        <FolderPlus className="text-muted-foreground size-6" aria-hidden="true" />
        <h2 className="font-medium">No projects yet</h2>
        <p className="text-muted-foreground text-sm">
          Create a project, then upload a ZIP archive of its source code to record the first source snapshot.
        </p>
        <Button asChild>
          <Link href="/app/projects/new">Create project</Link>
        </Button>
      </div>
    );
  }

  return (
    <div className="grid gap-4">
      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full text-left text-sm">
          <thead className="bg-muted/50 text-muted-foreground">
            <tr>
              <th scope="col" className="px-4 py-2 font-medium">Name</th>
              <th scope="col" className="px-4 py-2 font-medium">Language</th>
              <th scope="col" className="px-4 py-2 font-medium">Status</th>
              <th scope="col" className="px-4 py-2 font-medium">Source</th>
              <th scope="col" className="px-4 py-2 font-medium">Created</th>
            </tr>
          </thead>
          <tbody>
            {projects.map((project) => (
              <tr key={project.id} className="border-t">
                <td className="px-4 py-2">
                  <Link href={`/app/projects/${project.id}`} className="font-medium underline-offset-4 hover:underline">
                    {project.name}
                  </Link>
                  <p className="text-muted-foreground text-xs">{project.slug}</p>
                </td>
                <td className="px-4 py-2">{languageLabel(project.language)}</td>
                <td className="px-4 py-2">
                  <StatusBadge status={project.status} />
                </td>
                <td className="px-4 py-2">{sourceTypeLabel(project.source_type)}</td>
                <td className="px-4 py-2 whitespace-nowrap">{formatDate(project.created_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {meta.last_page > 1 ? (
        <nav aria-label="Pagination" className="flex items-center gap-3 text-sm">
          <Button variant="outline" size="sm" disabled={meta.current_page <= 1} onClick={() => goTo(meta.current_page - 1)}>
            Previous
          </Button>
          <span>
            Page {meta.current_page} of {meta.last_page}
          </span>
          <Button
            variant="outline"
            size="sm"
            disabled={meta.current_page >= meta.last_page}
            onClick={() => goTo(meta.current_page + 1)}
          >
            Next
          </Button>
        </nav>
      ) : null}
    </div>
  );
}
