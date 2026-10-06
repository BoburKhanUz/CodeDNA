import { beforeEach, describe, expect, it, vi } from "vitest";

import { archiveProject, createProject, getProject, listProjects, listSourceSnapshots, uploadSource } from "@/lib/projects/client";
import { jsonResponse, page, project, snapshot } from "@/test/responses";
import { FakeXhr } from "@/test/xhr";

const fetchMock = vi.fn<typeof fetch>();
const UPLOAD_ATTEMPT = "attempt-abcd-1234";

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  FakeXhr.reset();
  vi.stubGlobal("XMLHttpRequest", FakeXhr);
  document.cookie = "XSRF-TOKEN=token; path=/";
});

const url = (index: number) => String(fetchMock.mock.calls[index]?.[0]);
const method = (index: number) => fetchMock.mock.calls[index]?.[1]?.method;

describe("projects client", () => {
  it("lists, reads, creates and archives projects", async () => {
    fetchMock
      .mockResolvedValueOnce(jsonResponse(page([project])))
      .mockResolvedValueOnce(jsonResponse({ data: project }))
      .mockResolvedValueOnce(jsonResponse({ data: project }, 201))
      .mockResolvedValueOnce(jsonResponse({ data: { ...project, status: "ARCHIVED" } }));

    expect((await listProjects(2)).data).toEqual([project]);
    expect(await getProject(project.id)).toEqual(project);
    expect(await createProject({ name: "Billing", slug: "billing", source_type: "UPLOAD" })).toEqual(project);
    expect((await archiveProject(project.id)).status).toBe("ARCHIVED");

    expect(url(0)).toBe("/api/v1/projects?page=2&per_page=25");
    expect(url(1)).toBe(`/api/v1/projects/${project.id}`);
    expect([url(2), method(2)]).toEqual(["/api/v1/projects", "POST"]);
    expect([url(3), method(3)]).toEqual([`/api/v1/projects/${project.id}/archive`, "POST"]);
  });

  it("lists snapshots and uploads source with an idempotency key", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(page([snapshot(1)])));
    FakeXhr.handler = (xhr) => xhr.respond(201, { data: snapshot(2) });
    const file = new File(["PK"], "source.zip", { type: "application/zip" });

    expect((await listSourceSnapshots(project.id)).data[0]?.version).toBe(1);
    expect((await uploadSource(project.id, file, { idempotencyKey: UPLOAD_ATTEMPT })).version).toBe(2);

    expect(url(0)).toBe(`/api/v1/projects/${project.id}/source-snapshots?page=1&per_page=25`);
    const [xhr] = FakeXhr.instances;
    expect(xhr?.url).toBe(`/api/v1/projects/${project.id}/source-snapshots`);
    expect(xhr?.requestHeaders["Idempotency-Key"]).toBe(UPLOAD_ATTEMPT);
    expect((xhr?.body as FormData).get("archive")).toBe(file);
  });

  it("never builds URLs from anything but a ULID", async () => {
    for (const id of ["../admin", "a/b", "x".repeat(25), "%2e%2e", "https://evil.example"]) {
      await expect(getProject(id)).rejects.toThrow("Invalid project ID.");
    }
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
