import { beforeEach, describe, expect, it, vi } from "vitest";

import { getAiStatus, getInsight, listInsights, requestInsight } from "@/lib/insights/client";
import { aiReady, INSIGHT_ID, queuedInsight, SUBJECT_ID } from "@/test/insights";
import { jsonResponse, project } from "@/test/responses";

const fetchMock = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubGlobal("fetch", fetchMock);
  fetchMock.mockReset();
  document.cookie = "XSRF-TOKEN=test-token";
});

describe("insights client", () => {
  it("reads the AI status", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: aiReady }));
    await expect(getAiStatus()).resolves.toEqual(aiReady);
    expect(String(fetchMock.mock.calls[0][0])).toMatch(/\/api\/v1\/ai\/status$/);
  });

  it("lists the insights of one subject", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: [queuedInsight] }));
    await expect(listInsights(project.id.toUpperCase(), "GROWTH_INTERPRETATION", SUBJECT_ID.toUpperCase())).resolves.toEqual([queuedInsight]);
    expect(String(fetchMock.mock.calls[0][0])).toMatch(
      new RegExp(`/api/v1/projects/${project.id}/insights\\?kind=GROWTH_INTERPRETATION&subject_id=${SUBJECT_ID}$`),
    );
  });

  it("requests an insight by kind and subject only", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: queuedInsight }, 202));
    await requestInsight(project.id, "ROADMAP_GUIDANCE", SUBJECT_ID);
    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toMatch(new RegExp(`/api/v1/projects/${project.id}/insights$`));
    expect(init?.method).toBe("POST");
    expect(JSON.parse(String(init?.body))).toEqual({ kind: "ROADMAP_GUIDANCE", subject_id: SUBJECT_ID });
  });

  it("reads one insight", async () => {
    fetchMock.mockResolvedValue(jsonResponse({ data: queuedInsight }));
    await getInsight(project.id, INSIGHT_ID);
    expect(String(fetchMock.mock.calls[0][0])).toMatch(new RegExp(`/insights/${INSIGHT_ID}$`));
  });

  it("refuses malformed identifiers and kinds without calling the API", async () => {
    await expect(listInsights("../admin", "GROWTH_INTERPRETATION", SUBJECT_ID)).rejects.toThrow("Invalid project ID.");
    await expect(listInsights(project.id, "GROWTH_INTERPRETATION", "x&kind=other")).rejects.toThrow("Invalid insight subject.");
    // @ts-expect-error an unknown kind is rejected at runtime too
    await expect(requestInsight(project.id, "SCORE_OVERRIDE", SUBJECT_ID)).rejects.toThrow("Invalid insight subject.");
    await expect(getInsight(project.id, "../../ai/status")).rejects.toThrow("Invalid insight ID.");
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
