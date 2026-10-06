"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { ApiErrorAlert } from "@/components/auth/api-error-alert";
import { FormField } from "@/components/auth/form-field";
import {
  firstServerErrors,
  hasErrors,
  validateEmail,
  validateNewPassword,
  type FieldErrors,
} from "@/components/auth/validation";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@/components/ui/card";
import { isApiError } from "@/lib/api/errors";
import { register } from "@/lib/auth/client";

const FIELDS = ["name", "email", "password", "password_confirmation"] as const;
type Field = (typeof FIELDS)[number];

export function RegisterForm() {
  const router = useRouter();
  const [values, setValues] = useState<Record<Field, string>>({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
  });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors<Field>>({});
  const [formError, setFormError] = useState<unknown>(null);
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);

    const errors: FieldErrors<Field> = {
      name: values.name.trim() === "" ? "Enter your name." : values.name.length > 255 ? "Use at most 255 characters." : undefined,
      email: validateEmail(values.email),
      password: validateNewPassword(values.password),
      password_confirmation:
        values.password_confirmation !== values.password ? "The passwords do not match." : undefined,
    };
    setFieldErrors(errors);
    if (hasErrors(errors)) return;

    setSubmitting(true);
    try {
      // Registration signs the user in (201 + session), so go straight to the app.
      await register({
        name: values.name.trim(),
        email: values.email.trim(),
        password: values.password,
        password_confirmation: values.password_confirmation,
      });
      router.replace("/app");
      router.refresh();
    } catch (error) {
      if (isApiError(error) && error.code === "VALIDATION_FAILED") {
        setFieldErrors(firstServerErrors(error.fieldErrors, FIELDS));
      }
      setFormError(error);
      setSubmitting(false);
    }
  }

  function update(field: Field) {
    return (event: { target: { value: string } }) => setValues((current) => ({ ...current, [field]: event.target.value }));
  }

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle>
          <h1>Create your account</h1>
        </CardTitle>
        <CardDescription>Start building your developer DNA.</CardDescription>
      </CardHeader>
      <form onSubmit={onSubmit} noValidate aria-busy={submitting}>
        <CardContent className="grid gap-4">
          {formError ? <ApiErrorAlert error={formError} /> : null}
          <FormField
            id="name"
            label="Name"
            autoComplete="name"
            value={values.name}
            onChange={update("name")}
            error={fieldErrors.name}
            disabled={submitting}
          />
          <FormField
            id="email"
            label="Email"
            type="email"
            autoComplete="email"
            value={values.email}
            onChange={update("email")}
            error={fieldErrors.email}
            disabled={submitting}
          />
          <FormField
            id="password"
            label="Password"
            type="password"
            autoComplete="new-password"
            value={values.password}
            onChange={update("password")}
            error={fieldErrors.password}
            disabled={submitting}
          />
          <FormField
            id="password_confirmation"
            label="Confirm password"
            type="password"
            autoComplete="new-password"
            value={values.password_confirmation}
            onChange={update("password_confirmation")}
            error={fieldErrors.password_confirmation}
            disabled={submitting}
          />
        </CardContent>
        <CardFooter className="mt-6 flex flex-col gap-4">
          <Button type="submit" className="w-full" disabled={submitting}>
            {submitting ? "Creating account…" : "Create account"}
          </Button>
          <p className="text-muted-foreground text-sm">
            Already have an account?{" "}
            <Link href="/login" className="text-foreground underline underline-offset-4">
              Sign in
            </Link>
          </p>
        </CardFooter>
      </form>
    </Card>
  );
}
