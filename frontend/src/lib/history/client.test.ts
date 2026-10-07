import { beforeEach, describe, expect, it, vi } from "vitest";

import { compareHistory, getHistory, getHistoryPoint } from "@/lib/history/client";
import { jsonResponse, page, project } from "@/test/responses";
import { comparison, id, threeCompatible } from "@/test/history";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
});

const url = () => String(fetchMock.mock.calls[0][0]);

describe("history client", () => {
  it("reads a bounded page", async () => {
    fetchMock.mockResolvedValue(jsonResponse(page(threeCompatible())));
    await getHistory(project.id, 0, 500);
    expect(url()).toBe(`/api/v1/projects/${project.id}/history?page=1&per_page=100`);
  });

  it("reads one point and compares by IDs only", async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: threeCompatible()[0] }));
    await getHistoryPoint(project.id, id(3).toUpperCase());
    expect(url()).toBe(`/api/v1/projects/${project.id}/history/${id(3)}`);

    fetchMock.mockReset();
    fetchMock.mockResolvedValueOnce(jsonResponse({ data: comparison() }));
    await compareHistory(project.id, id(1), id(3));
    expect(url()).toBe(`/api/v1/projects/${project.id}/history/compare?from=${id(1)}&to=${id(3)}`);
  });

  it("refuses anything that is not an ID before any request", async () => {
    expect(() => getHistory("../x")).toThrow("Invalid project ID.");
    await expect(compareHistory(project.id, "1&score=1", id(1))).rejects.toThrow("Invalid assessment ID.");
    await expect(getHistoryPoint(project.id, "x")).rejects.toThrow("Invalid assessment ID.");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
