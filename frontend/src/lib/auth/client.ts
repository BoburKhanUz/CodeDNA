import { api } from "@/lib/api/client";
import { isApiError } from "@/lib/api/errors";
import type { AuthResponse, LoginRequest, RegisterRequest, User } from "@/lib/api/types";

/** Browser-side authentication operations (Laravel Sanctum SPA flow). */

export async function login(credentials: LoginRequest): Promise<User> {
  const response = await api.post<AuthResponse>("/api/v1/auth/login", credentials);
  return response.data;
}

export async function register(input: RegisterRequest): Promise<User> {
  const response = await api.post<AuthResponse>("/api/v1/auth/register", input);
  return response.data;
}

/**
 * Ends the session. An already-expired session (401) counts as logged out;
 * other failures (network, 5xx) are reported to the caller.
 */
export async function logout(): Promise<void> {
  try {
    await api.post<void>("/api/v1/auth/logout");
  } catch (error) {
    if (isApiError(error) && error.status === 401) {
      return;
    }
    throw error;
  }
}
