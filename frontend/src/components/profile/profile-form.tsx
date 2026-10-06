"use client";

import { CheckCircle2 } from "lucide-react";
import { useRouter } from "next/navigation";
import { useState, type ChangeEvent, type FormEvent } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { firstServerErrors, hasErrors, type FieldErrors } from "@/components/auth/validation";
import { describedBy, Field } from "@/components/profile/field";
import {
  MAX_LENGTH,
  validateCountryCode,
  validateGithubUsername,
  validateHttpsUrl,
  validateMaxLength,
  validateTimezone,
} from "@/components/profile/validation";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";
import { isApiError } from "@/lib/api/errors";
import {
  PROGRAMMING_LANGUAGES,
  SUPPORTED_LOCALES,
  type DeveloperProfile,
  type SupportedLocale,
  type UpdateProfileRequest,
} from "@/lib/api/types";
import { updateProfile } from "@/lib/profile/client";
import { LOCALE_LABELS, PROGRAMMING_LANGUAGE_LABELS, timeZoneSuggestions } from "@/lib/profile/options";

const FIELDS = [
  "display_name",
  "job_title",
  "company",
  "bio",
  "city",
  "country_code",
  "website_url",
  "github_username",
  "linkedin_url",
  "avatar_url",
  "preferred_language",
  "timezone",
  "locale",
] as const;
type Field = (typeof FIELDS)[number];
type Values = Record<Field, string>;

/** Fields that may be cleared; an empty input is sent as null. */
const OPTIONAL_FIELDS = FIELDS.filter((field) => field !== "timezone" && field !== "locale");

function toValues(profile: DeveloperProfile): Values {
  return {
    display_name: profile.display_name ?? "",
    job_title: profile.job_title ?? "",
    company: profile.company ?? "",
    bio: profile.bio ?? "",
    city: profile.city ?? "",
    country_code: profile.country_code ?? "",
    website_url: profile.website_url ?? "",
    github_username: profile.github_username ?? "",
    linkedin_url: profile.linkedin_url ?? "",
    avatar_url: profile.avatar_url ?? "",
    preferred_language: profile.preferred_language ?? "",
    timezone: profile.timezone,
    locale: profile.locale,
  };
}

function toRequest(values: Values): UpdateProfileRequest {
  const request: Record<string, string | null> = {
    timezone: values.timezone.trim(),
    locale: values.locale,
  };
  for (const field of OPTIONAL_FIELDS) {
    const value = values[field].trim();
    request[field] = value === "" ? null : value;
  }
  if (request.country_code) request.country_code = request.country_code.toUpperCase();
  if (request.github_username) request.github_username = request.github_username.replace(/^@/, "");
  return request as UpdateProfileRequest;
}

function validate(values: Values): FieldErrors<Field> {
  return {
    display_name: validateMaxLength(values.display_name.trim(), MAX_LENGTH.display_name),
    job_title: validateMaxLength(values.job_title.trim(), MAX_LENGTH.job_title),
    company: validateMaxLength(values.company.trim(), MAX_LENGTH.company),
    bio: validateMaxLength(values.bio.trim(), MAX_LENGTH.bio),
    city: validateMaxLength(values.city.trim(), MAX_LENGTH.city),
    country_code: validateCountryCode(values.country_code),
    website_url: validateHttpsUrl(values.website_url),
    github_username: validateGithubUsername(values.github_username),
    linkedin_url: validateHttpsUrl(values.linkedin_url),
    avatar_url: validateHttpsUrl(values.avatar_url),
    timezone: validateTimezone(values.timezone),
  };
}

/** Edit form for the developer profile. Stays on the page after saving. */
export function ProfileForm({ profile }: { profile: DeveloperProfile }) {
  const router = useRouter();
  const [values, setValues] = useState<Values>(() => toValues(profile));
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<Field>>({});
  const [formError, setFormError] = useState<unknown>(null);
  const [saving, setSaving] = useState(false);
  const [saved, setSaved] = useState(false);
  const [timeZones] = useState(timeZoneSuggestions);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);
    setSaved(false);

    const errors = validate(values);
    setFieldErrors(errors);
    if (hasErrors(errors)) return;

    setSaving(true);
    try {
      const updated = await updateProfile(toRequest(values));
      setValues(toValues(updated));
      setSaved(true);
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
    } finally {
      setSaving(false);
    }
  }

  function update(field: Field) {
    return (event: ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
      setSaved(false);
      setValues((current) => ({ ...current, [field]: event.target.value }));
    };
  }

  function text(field: Field, label: string, options: { type?: string; autoComplete?: string; hint?: string; placeholder?: string } = {}) {
    const error = fieldErrors[field];
    return (
      <Field id={field} label={label} hint={options.hint} error={error}>
        <Input
          {...describedBy(field, error, options.hint)}
          type={options.type ?? "text"}
          autoComplete={options.autoComplete ?? "off"}
          placeholder={options.placeholder}
          value={values[field]}
          onChange={update(field)}
          disabled={saving}
        />
      </Field>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Developer profile</h2>
        </CardTitle>
        <CardDescription>How you describe yourself as a developer. Every field is optional.</CardDescription>
      </CardHeader>
      <form onSubmit={onSubmit} noValidate aria-busy={saving}>
        <CardContent className="grid gap-4 sm:grid-cols-2">
          {formError ? (
            <div className="sm:col-span-2">
              <ApiErrorAlert error={formError} />
            </div>
          ) : null}
          {text("display_name", "Display name", { autoComplete: "nickname" })}
          {text("job_title", "Job title", { autoComplete: "organization-title" })}
          {text("company", "Company", { autoComplete: "organization" })}
          <Field id="preferred_language" label="Preferred programming language" error={fieldErrors.preferred_language}>
            <NativeSelect
              {...describedBy("preferred_language", fieldErrors.preferred_language)}
              value={values.preferred_language}
              onChange={update("preferred_language")}
              disabled={saving}
            >
              <option value="">Not set</option>
              {values.preferred_language !== "" &&
              !(PROGRAMMING_LANGUAGES as readonly string[]).includes(values.preferred_language) ? (
                <option value={values.preferred_language}>{values.preferred_language}</option>
              ) : null}
              {PROGRAMMING_LANGUAGES.map((language) => (
                <option key={language} value={language}>
                  {PROGRAMMING_LANGUAGE_LABELS[language]}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field id="bio" label="Bio" hint="Up to 1000 characters." error={fieldErrors.bio} className="sm:col-span-2">
            <Textarea
              {...describedBy("bio", fieldErrors.bio, "Up to 1000 characters.")}
              rows={4}
              value={values.bio}
              onChange={update("bio")}
              disabled={saving}
            />
          </Field>
          {text("city", "City", { autoComplete: "address-level2" })}
          {text("country_code", "Country code", { hint: "Two letters, for example UZ.", placeholder: "UZ" })}
          {text("website_url", "Website", { type: "url", autoComplete: "url", placeholder: "https://" })}
          {text("github_username", "GitHub username", { placeholder: "octocat" })}
          {text("linkedin_url", "LinkedIn", { type: "url", placeholder: "https://www.linkedin.com/in/…" })}
          {text("avatar_url", "Avatar URL", { type: "url", placeholder: "https://", hint: "A link to an image. Uploads are not supported yet." })}
          <Field
            id="timezone"
            label="Time zone"
            hint="An IANA time zone such as Asia/Tashkent."
            error={fieldErrors.timezone}
          >
            <Input
              {...describedBy("timezone", fieldErrors.timezone, "An IANA time zone such as Asia/Tashkent.")}
              list="timezone-options"
              autoComplete="off"
              value={values.timezone}
              onChange={update("timezone")}
              disabled={saving}
            />
            <datalist id="timezone-options">
              {timeZones.map((zone) => (
                <option key={zone} value={zone} />
              ))}
            </datalist>
          </Field>
          <Field id="locale" label="Interface language" hint="Saved as a preference; CodeDNA is in English for now." error={fieldErrors.locale}>
            <NativeSelect
              {...describedBy("locale", fieldErrors.locale, "Saved as a preference; CodeDNA is in English for now.")}
              value={values.locale}
              onChange={update("locale")}
              disabled={saving}
            >
              {SUPPORTED_LOCALES.map((locale: SupportedLocale) => (
                <option key={locale} value={locale}>
                  {LOCALE_LABELS[locale]}
                </option>
              ))}
            </NativeSelect>
          </Field>
        </CardContent>
        <CardFooter className="mt-6 flex flex-wrap items-center gap-4">
          <Button type="submit" disabled={saving}>
            {saving ? "Saving…" : "Save profile"}
          </Button>
          <div role="status" aria-live="polite">
            {saved ? (
              <p className="flex items-center gap-2 text-sm">
                <CheckCircle2 className="size-4 text-emerald-600" aria-hidden="true" />
                Profile saved.
              </p>
            ) : null}
          </div>
        </CardFooter>
      </form>
    </Card>
  );
}
