"use client";

import { createContext, useContext, type ReactNode } from "react";

import type { User } from "@/lib/api/types";

const CurrentUserContext = createContext<User | null>(null);

/**
 * Provides the user that the server resolved for this request (see
 * lib/auth/session.ts). The authenticated area is only rendered after the
 * server confirmed the session, so client components never need their own
 * /me request.
 */
export function AuthProvider({ user, children }: { user: User; children: ReactNode }) {
  return <CurrentUserContext.Provider value={user}>{children}</CurrentUserContext.Provider>;
}

export function useCurrentUser(): User {
  const user = useContext(CurrentUserContext);
  if (user === null) {
    throw new Error("useCurrentUser must be used inside the authenticated area (AuthProvider).");
  }
  return user;
}
