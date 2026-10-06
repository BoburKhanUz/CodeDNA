import { api } from "@/lib/api/client";
import type {
  ChangePasswordRequest,
  DeveloperProfile,
  DeveloperProfileResponse,
  UpdateProfileRequest,
} from "@/lib/api/types";

/** Browser-side developer profile and account security operations. */

export async function getProfile(): Promise<DeveloperProfile> {
  const response = await api.get<DeveloperProfileResponse>("/api/v1/profile");
  return response.data;
}

export async function updateProfile(changes: UpdateProfileRequest): Promise<DeveloperProfile> {
  const response = await api.patch<DeveloperProfileResponse>("/api/v1/profile", changes);
  return response.data;
}

/** Changes the password. The server ends the session, so the caller must send the user to sign in. */
export async function changePassword(input: ChangePasswordRequest): Promise<void> {
  await api.patch<void>("/api/v1/auth/password", input);
}
