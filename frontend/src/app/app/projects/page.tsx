import { Plus } from "lucide-react";
import type { Metadata } from "next";
import Link from "next/link";

import { ProjectList } from "@/components/projects/project-list";
import { Button } from "@/components/ui/button";

export const metadata: Metadata = { title: "Projects" };

export default function ProjectsPage() {
  return (
    <div className="grid max-w-5xl gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div className="grid gap-1">
          <h1 className="text-2xl font-semibold tracking-tight">Projects</h1>
          <p className="text-muted-foreground">Your projects and their source snapshots.</p>
        </div>
        <Button asChild>
          <Link href="/app/projects/new">
            <Plus aria-hidden="true" />
            New project
          </Link>
        </Button>
      </div>
      <ProjectList />
    </div>
  );
}
