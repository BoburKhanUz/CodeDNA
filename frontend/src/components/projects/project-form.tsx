"use client";

import { useRouter } from "next/navigation";
import { useState, type ChangeEvent, type FormEvent } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { firstServerErrors, hasErrors, type FieldErrors } from "@/components/auth/validation";
import { describedBy, Field } from "@/components/profile/field";
import { validateHttpsUrl } from "@/components/profile/validation";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { isApiError } from "@/lib/api/errors";
import { PROGRAMMING_LANGUAGES, type CreateProjectRequest, type SourceType } from "@/lib/api/types";
import { PROGRAMMING_LANGUAGE_LABELS } from "@/lib/profile/options";
import { createProject } from "@/lib/projects/client";
import { slugify } from "@/lib/projects/format";

const FIELDS = ["name", "slug", "description", "source_type", "repository_url", "language", "default_branch"] as const;
type Field = (typeof FIELDS)[number];
type Values = Record<Field, string>;

/** Mirrors backend/app/Http/Requests/Projects/ProjectRules.php; the server remains authoritative. */
const SLUG_PATTERN = /^[a-z0-9]+(-[a-z0-9]+)*$/;
const BRANCH_PATTERN = /^(?!.*\.\.)(?!.*\/\/)(?!.*@\{)(?!.*\.lock$)[A-Za-z0-9][A-Za-z0-9._/-]*(?<![/.])$/;

function validate(values: Values): FieldErrors<Field> {
  const name = values.name.trim();
  const slug = values.slug.trim();
  const branch = values.default_branch.trim();
  const url = values.repository_url.trim();
  return {
    name: name === "" ? "Enter a project name." : name.length > 255 ? "Use at most 255 characters." : undefined,
    slug:
      slug === ""
        ? "Enter a slug."
        : slug.length > 100
          ? "Use at most 100 characters."
          : SLUG_PATTERN.test(slug)
            ? undefined
            : "Use lowercase letters, digits and single hyphens.",
    description: values.description.trim().length > 2000 ? "Use at most 2000 characters." : undefined,
    repository_url:
      values.source_type === "REPOSITORY"
        ? url === ""
          ? "Enter the repository URL."
          : validateHttpsUrl(url)
        : undefined,
    default_branch:
      branch === "" || (branch.length <= 255 && BRANCH_PATTERN.test(branch)) ? undefined : "Enter a valid branch name, such as main.",
  };
}

function toRequest(values: Values): CreateProjectRequest {
  const optional = (value: string) => (value.trim() === "" ? null : value.trim());
  const request: CreateProjectRequest = {
    name: values.name.trim(),
    slug: values.slug.trim(),
    source_type: values.source_type as SourceType,
    description: optional(values.description),
    default_branch: optional(values.default_branch),
    language: optional(values.language),
  };
  if (values.source_type === "REPOSITORY") {
    request.repository_url = values.repository_url.trim();
  }
  return request;
}

/** Creates a project, then opens it. */
export function ProjectForm() {
  const router = useRouter();
  const [values, setValues] = useState<Values>({
    name: "",
    slug: "",
    description: "",
    source_type: "UPLOAD",
    repository_url: "",
    language: "",
    default_branch: "",
  });
  const [slugEdited, setSlugEdited] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<Field>>({});
  const [formError, setFormError] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);

  function update(field: Field) {
    return (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
      const value = event.target.value;
      setValues((current) => {
        const next = { ...current, [field]: value };
        // The slug follows the name until the developer edits it.
        if (field === "name" && !slugEdited) next.slug = slugify(value);
        return next;
      });
      if (field === "slug") setSlugEdited(true);
    };
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);

    const errors = validate(values);
    setFieldErrors(errors);
    if (hasErrors(errors)) return;

    setSubmitting(true);
    try {
      const project = await createProject(toRequest(values));
      router.push(`/app/projects/${project.id}`);
    } catch (error) {
      if (isApiError(error) && error.status === 401) {
        router.replace("/login");
        router.refresh();
        return;
      }
      if (isApiError(error) && error.code === "VALIDATION_FAILED") {
        setFieldErrors(firstServerErrors(error.fieldErrors, FIELDS));
      }
      setFormError(error);
      setSubmitting(false);
    }
  }

  const isRepository = values.source_type === "REPOSITORY";

  return (
    <Card className="max-w-2xl">
      <CardHeader>
        <CardTitle>
          <h2>Project details</h2>
        </CardTitle>
        <CardDescription>You can upload source code once the project exists.</CardDescription>
      </CardHeader>
      <form onSubmit={onSubmit} noValidate aria-busy={submitting}>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          {formError ? (
            <div className="sm:col-span-2">
              <ApiErrorAlert error={formError} />
            </div>
          ) : null}
          <Field id="name" label="Name" error={fieldErrors.name}>
            <Input {...describedBy("name", fieldErrors.name)} value={values.name} onChange={update("name")} disabled={submitting} autoComplete="off" />
          </Field>
          <Field id="slug" label="Slug" hint="Lowercase letters, digits and hyphens. Unique among your projects." error={fieldErrors.slug}>
            <Input
              {...describedBy("slug", fieldErrors.slug, "Lowercase letters, digits and hyphens. Unique among your projects.")}
              value={values.slug}
              onChange={update("slug")}
              disabled={submitting}
              autoComplete="off"
            />
          </Field>
          <Field id="description" label="Description" error={fieldErrors.description} className="sm:col-span-2">
            <Textarea
              {...describedBy("description", fieldErrors.description)}
              rows={3}
              value={values.description}
              onChange={update("description")}
              disabled={submitting}
            />
          </Field>
          <fieldset className="grid gap-2 sm:col-span-2" disabled={submitting}>
            <legend className="mb-2 text-sm font-medium">Source</legend>
            <label className="flex items-start gap-2 text-sm">
              <input type="radio" name="source_type" value="UPLOAD" checked={!isRepository} onChange={update("source_type")} className="mt-1" />
              <span>
                <span className="font-medium">Upload</span>
                <span className="text-muted-foreground block">Upload ZIP archives of the source code.</span>
              </span>
            </label>
            <label className="flex items-start gap-2 text-sm">
              <input type="radio" name="source_type" value="REPOSITORY" checked={isRepository} onChange={update("source_type")} className="mt-1" />
              <span>
                <span className="font-medium">Repository</span>
                <span className="text-muted-foreground block">
                  Record the repository URL. Importing from repositories is not available yet.
                </span>
              </span>
            </label>
          </fieldset>
          {isRepository ? (
            <Field id="repository_url" label="Repository URL" error={fieldErrors.repository_url} className="sm:col-span-2">
              <Input
                {...describedBy("repository_url", fieldErrors.repository_url)}
                type="url"
                placeholder="https://"
                value={values.repository_url}
                onChange={update("repository_url")}
                disabled={submitting}
                autoComplete="off"
              />
            </Field>
          ) : null}
          <Field id="language" label="Language" error={fieldErrors.language}>
            <NativeSelect {...describedBy("language", fieldErrors.language)} value={values.language} onChange={update("language")} disabled={submitting}>
              <option value="">Not set</option>
              {PROGRAMMING_LANGUAGES.map((language) => (
                <option key={language} value={language}>
                  {PROGRAMMING_LANGUAGE_LABELS[language]}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field id="default_branch" label="Default branch" error={fieldErrors.default_branch}>
            <Input
              {...describedBy("default_branch", fieldErrors.default_branch)}
              placeholder="main"
              value={values.default_branch}
              onChange={update("default_branch")}
              disabled={submitting}
              autoComplete="off"
            />
          </Field>
        </CardContent>
        <CardFooter className="mt-6">
          <Button type="submit" disabled={submitting}>
            {submitting ? "Creating project…" : "Create project"}
          </Button>
        </CardFooter>
      </form>
    </Card>
  );
}
