import { beforeEach, describe, expect, it } from "vitest";

import { ApiError, describeApiError } from "@/lib/api/errors";
import { API_ERROR_CODES } from "@/lib/api/types";
import { assignableRoles, canManage, isInvitationToken, isOrganizationId, previewInvitation } from "@/lib/organizations/client";
import { afterSignInPath, forgetInvitation, invitationLink, pendingInvitation, rememberInvitation } from "@/lib/organizations/invitation-link";
import { membership, TOKEN } from "@/test/organizations";

beforeEach(() => window.sessionStorage.clear());

describe("organization roles in the UI", () => {
  it("mirrors the server rule: act only on lower roles, never on oneself or the owner", () => {
    expect(canManage("OWNER", membership("ADMIN"), false)).toBe(true);
    expect(canManage("OWNER", membership("MEMBER"), false)).toBe(true);
    expect(canManage("OWNER", membership("OWNER"), true)).toBe(false);
    expect(canManage("ADMIN", membership("MEMBER"), false)).toBe(true);
    expect(canManage("ADMIN", membership("ADMIN"), false)).toBe(false);
    expect(canManage("ADMIN", membership("MEMBER"), true)).toBe(false);
    expect(canManage("MEMBER", membership("MEMBER"), false)).toBe(false);
    expect(assignableRoles("OWNER")).toEqual(["ADMIN", "MEMBER"]);
    expect(assignableRoles("ADMIN")).toEqual(["ADMIN", "MEMBER"]);
    expect(assignableRoles("MEMBER")).toEqual([]);
  });

  it("validates IDs and tokens before building URLs", async () => {
    expect(isOrganizationId("01k6p0a1b2c3d4e5f6g7h8j9or")).toBe(true);
    expect(isOrganizationId("../../admin")).toBe(false);
    expect(isInvitationToken(TOKEN)).toBe(true);
    expect(isInvitationToken(`${TOKEN}x`)).toBe(false);
    expect(isInvitationToken("a/b".padEnd(43, "a"))).toBe(false);
    await expect(previewInvitation("../x")).rejects.toThrow("Invalid invitation link.");
  });
});

describe("invitation links", () => {
  it("keep the token in the fragment and remember it for after sign-in", () => {
    expect(invitationLink("https://codedna.example", TOKEN)).toBe(`https://codedna.example/invitations/accept#${TOKEN}`);
    expect(afterSignInPath()).toBe("/app");
    rememberInvitation(TOKEN);
    expect(pendingInvitation()).toBe(TOKEN);
    expect(afterSignInPath()).toBe("/invitations/accept");
    forgetInvitation();
    expect(afterSignInPath()).toBe("/app");
  });

  it("ignores anything stored that is not a token", () => {
    window.sessionStorage.setItem("codedna.pendingInvitation", "javascript:alert(1)");
    expect(pendingInvitation()).toBeNull();
    expect(afterSignInPath()).toBe("/app");
  });
});

describe("organization error messages", () => {
  const codes = [
    "ORGANIZATION_SUSPENDED", "ORGANIZATION_ARCHIVED", "MEMBERSHIP_SUSPENDED", "INSUFFICIENT_ORGANIZATION_ROLE", "ALREADY_A_MEMBER",
    "INVITATION_EXPIRED", "INVITATION_REVOKED", "INVITATION_ALREADY_ACCEPTED", "INVITATION_EMAIL_MISMATCH", "SEAT_LIMIT_REACHED",
    "CANNOT_REMOVE_OWNER", "CANNOT_CHANGE_OWNER_ROLE",
  ] as const;

  it("has a specific message for every organization code", () => {
    const generic = describeApiError(new ApiError({ status: 500, code: "INTERNAL_ERROR" }));
    for (const code of codes) {
      expect(API_ERROR_CODES).toContain(code);
      const text = describeApiError(new ApiError({ status: 409, code }));
      expect(text).not.toBe(generic);
      expect(text.length).toBeGreaterThan(10);
    }
  });
});
