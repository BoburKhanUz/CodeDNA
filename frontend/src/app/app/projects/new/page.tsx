import type { Metadata } from "next";
import Link from "next/link";

import { ProjectForm } from "@/components/projects/project-form";

export const metadata: Metadata = { title: "New project" };

export default function NewProjectPage() {
  return (
    <div className="grid gap-6">
      <div className="grid gap-2">
        <Link href="/app/projects" className="text-muted-foreground w-fit text-sm underline-offset-4 hover:underline">
          ← Projects
        </Link>
        <h1 className="text-2xl font-semibold tracking-tight">New project</h1>
      </div>
      <ProjectForm />
    </div>
  );
}
