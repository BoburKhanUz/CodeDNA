"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { FormField } from "@/components/auth/form-field";
import { firstServerErrors, hasErrors, validateNewPassword, type FieldErrors } from "@/components/auth/validation";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { isApiError } from "@/lib/api/errors";
import { changePassword } from "@/lib/profile/client";

const FIELDS = ["current_password", "password", "password_confirmation"] as const;
type Field = (typeof FIELDS)[number];

const EMPTY: Record<Field, string> = { current_password: "", password: "", password_confirmation: "" };

/**
 * Changes the password. The server ends the session afterwards (and signs
 * out other sessions), so a successful change goes to the sign-in page.
 */
export function PasswordForm() {
  const router = useRouter();
  const [values, setValues] = useState(EMPTY);
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<Field>>({});
  const [formError, setFormError] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);

    const errors: FieldErrors<Field> = {
      current_password: values.current_password === "" ? "Enter your current password." : undefined,
      password:
        validateNewPassword(values.password) ??
        (values.password === values.current_password
          ? "The new password must be different from the current password."
          : undefined),
      password_confirmation:
        values.password_confirmation !== values.password ? "The passwords do not match." : undefined,
    };
    setFieldErrors(errors);
    if (hasErrors(errors)) return;

    setSubmitting(true);
    try {
      await changePassword(values);
      router.replace("/login?reason=password-changed");
      router.refresh();
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
      // Never keep passwords around after a failed attempt.
      setValues(EMPTY);
      setSubmitting(false);
    }
  }

  function update(field: Field) {
    return (event: { target: { value: string } }) => setValues((current) => ({ ...current, [field]: event.target.value }));
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>
          <h2>Password</h2>
        </CardTitle>
        <CardDescription>After changing your password you will be signed out everywhere and asked to sign in again.</CardDescription>
      </CardHeader>
      <form onSubmit={onSubmit} noValidate aria-busy={submitting}>
        <CardContent className="grid max-w-sm gap-4">
          {formError ? <ApiErrorAlert error={formError} /> : null}
          <FormField
            id="current_password"
            label="Current password"
            type="password"
            autoComplete="current-password"
            value={values.current_password}
            onChange={update("current_password")}
            error={fieldErrors.current_password}
            disabled={submitting}
          />
          <FormField
            id="password"
            label="New password"
            type="password"
            autoComplete="new-password"
            value={values.password}
            onChange={update("password")}
            error={fieldErrors.password}
            disabled={submitting}
          />
          <FormField
            id="password_confirmation"
            label="Confirm new password"
            type="password"
            autoComplete="new-password"
            value={values.password_confirmation}
            onChange={update("password_confirmation")}
            error={fieldErrors.password_confirmation}
            disabled={submitting}
          />
        </CardContent>
        <CardFooter className="mt-6">
          <Button type="submit" disabled={submitting}>
            {submitting ? "Changing password…" : "Change password"}
          </Button>
        </CardFooter>
      </form>
    </Card>
  );
}
