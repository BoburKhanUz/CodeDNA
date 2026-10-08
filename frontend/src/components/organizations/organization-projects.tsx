"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { type FormEvent, useCallback, useEffect, useState } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { FormField } from "@/components/auth/form-field";
import { OrganizationFrame, useOrganizationErrors } from "@/components/organizations/organization-frame";
import { StatusBadge } from "@/components/projects/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";
import { isApiError } from "@/lib/api/errors";
import { type Organization, PROGRAMMING_LANGUAGES, type Project } from "@/lib/api/types";
import { createOrganizationProject, isAdmin, listOrganizationProjects } from "@/lib/organizations/client";
import { PROGRAMMING_LANGUAGE_LABELS } from "@/lib/profile/options";
import { formatDate, languageLabel, slugify } from "@/lib/projects/format";

type State = { status: "loading" } | { status: "error"; error: unknown } | { status: "ready"; projects: Project[] };

/**
 * /app/organizations/[organization]/projects: the team's projects. Each
 * opens in the ordinary project pages, where membership decides access.
 * Admins and the owner create team projects; they use the team's plan.
 */
export function OrganizationProjects({ organizationId }: { organizationId: string }) {
  return (
    <OrganizationFrame organizationId={organizationId} title="Projects">
      {(organization) => <Projects organization={organization} />}
    </OrganizationFrame>
  );
}

function Projects({ organization }: { organization: Organization }) {
  const [state, setState] = useState<State>({ status: "loading" });
  const fail = useOrganizationErrors(useCallback((error: unknown) => setState({ status: "error", error }), []));
  const load = useCallback(() => {
    listOrganizationProjects(organization.id)
      .then((page) => setState({ status: "ready", projects: page.data }))
      .catch(fail);
  }, [organization.id, fail]);
  useEffect(() => load(), [load]);

  return (
    <div className="grid gap-6">
      {state.status === "loading" ? <p role="status">Loading projects…</p> : null}
      {state.status === "error" ? <ApiErrorAlert error={state.error} /> : null}
      {state.status === "ready" && state.projects.length === 0 ? (
        <p className="text-muted-foreground rounded-lg border border-dashed p-6 text-sm" data-testid="organization-projects-empty">
          This team has no projects yet.
        </p>
      ) : null}
      {state.status === "ready" && state.projects.length > 0 ? (
        <ul className="grid gap-2" data-testid="organization-projects">
          {state.projects.map((project) => (
            <li key={project.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-3 text-sm">
              <span>
                <Link href={`/app/projects/${project.id}`} className="font-medium underline-offset-4 hover:underline">
                  {project.name}
                </Link>{" "}
                <span className="text-muted-foreground">
                  {project.slug} · {languageLabel(project.language)} · created {formatDate(project.created_at)}
                </span>
              </span>
              <StatusBadge status={project.status} />
            </li>
          ))}
        </ul>
      ) : null}
      {isAdmin(organization.role) && organization.status === "ACTIVE" ? <CreateTeamProject organization={organization} /> : null}
    </div>
  );
}

function CreateTeamProject({ organization }: { organization: Organization }) {
  const router = useRouter();
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [slugEdited, setSlugEdited] = useState(false);
  const [language, setLanguage] = useState("");
  const [error, setError] = useState<unknown>(null);
  const [sending, setSending] = useState(false);
  const fields = isApiError(error) ? error.fieldErrors : {};

  function submit(event: FormEvent) {
    event.preventDefault();
    setSending(true);
    setError(null);
    createOrganizationProject(organization.id, { name: name.trim(), slug, source_type: "UPLOAD", language: language === "" ? null : language })
      .then((project) => router.push(`/app/projects/${project.id}`))
      .catch((e: unknown) => setError(e))
      .finally(() => setSending(false));
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Create a team project</h2>
        </CardTitle>
        <CardDescription>The project belongs to the team: every member can work on it, and it counts towards the team&apos;s plan.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={submit} className="grid max-w-md gap-3" noValidate>
          {error !== null ? <ApiErrorAlert error={error} /> : null}
          <FormField
            id="team-project-name"
            label="Name"
            value={name}
            error={fields.name?.[0]}
            onChange={(e) => {
              setName(e.target.value);
              if (!slugEdited) setSlug(slugify(e.target.value));
            }}
          />
          <FormField
            id="team-project-slug"
            label="Slug"
            value={slug}
            error={fields.slug?.[0]}
            onChange={(e) => {
              setSlugEdited(true);
              setSlug(e.target.value);
            }}
          />
          <div className="grid gap-2">
            <Label htmlFor="team-project-language">Language</Label>
            <NativeSelect id="team-project-language" value={language} onChange={(e) => setLanguage(e.target.value)}>
              <option value="">Not specified</option>
              {PROGRAMMING_LANGUAGES.map((l) => (
                <option key={l} value={l}>
                  {PROGRAMMING_LANGUAGE_LABELS[l]}
                </option>
              ))}
            </NativeSelect>
          </div>
          <Button type="submit" className="w-fit" disabled={sending || name.trim() === "" || slug === ""}>
            {sending ? "Creating…" : "Create team project"}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
