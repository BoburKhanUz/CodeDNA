import { ApiError } from "./errors";
import { parseApiResponse } from "./http";

/**
 * Browser API client for the same-origin Laravel API (/api/v1/...).
 *
 * - Relative URLs only: the browser talks to Nginx, which routes /api and
 *   /sanctum to Laravel. No API host is configured or hardcoded.
 * - Cookies are always sent (`credentials: "include"`). Authentication is
 *   the HttpOnly Laravel session cookie; no token is ever stored by us.
 * - State-changing requests carry X-XSRF-TOKEN, copied from Laravel's
 *   XSRF-TOKEN cookie (readable by design). If the cookie is missing it is
 *   fetched from /sanctum/csrf-cookie first. A 419 is retried exactly once
 *   after refreshing the cookie. Nothing else is retried, including 429.
 * - File uploads (`upload`) use XMLHttpRequest only because fetch cannot
 *   report upload progress; CSRF, cookies and error parsing are the same.
 */

type Method = "GET" | "POST" | "PUT" | "PATCH" | "DELETE";

const CSRF_COOKIE_PATH = "/sanctum/csrf-cookie";
const XSRF_COOKIE = "XSRF-TOKEN";

let csrfRequest: Promise<void> | null = null;

export const api = {
  get: <T>(path: string) => request<T>("GET", path),
  post: <T>(path: string, body?: unknown) => request<T>("POST", path, body),
  put: <T>(path: string, body?: unknown) => request<T>("PUT", path, body),
  patch: <T>(path: string, body?: unknown) => request<T>("PATCH", path, body),
  delete: <T>(path: string) => request<T>("DELETE", path),
};

export interface UploadOptions {
  /** Called with the fraction (0–1) of the request body sent so far. */
  onProgress?: (fraction: number) => void;
  /** Extra request headers, e.g. Idempotency-Key. */
  headers?: Record<string, string>;
}

/** POSTs multipart form data (a file upload) with the same CSRF and error handling as `request`. */
export async function upload<T>(path: string, form: FormData, options: UploadOptions = {}): Promise<T> {
  assertSameOriginPath(path);

  if (readCookie(XSRF_COOKIE) === null) {
    await refreshCsrfCookie();
  }

  let response = await sendForm(path, form, options);

  if (response.status === 419) {
    await refreshCsrfCookie();
    response = await sendForm(path, form, options);
  }

  return parseApiResponse<T>(response);
}

export async function request<T>(method: Method, path: string, body?: unknown): Promise<T> {
  assertSameOriginPath(path);
  const mutating = method !== "GET";

  if (mutating && readCookie(XSRF_COOKIE) === null) {
    await refreshCsrfCookie();
  }

  let response = await send(method, path, body);

  if (mutating && response.status === 419) {
    await refreshCsrfCookie();
    response = await send(method, path, body);
  }

  return parseApiResponse<T>(response);
}

/** Asks Laravel for a fresh XSRF-TOKEN cookie (concurrent callers share one request). */
export function refreshCsrfCookie(): Promise<void> {
  csrfRequest ??= send("GET", CSRF_COOKIE_PATH)
    .then((response) => parseApiResponse<void>(response))
    .finally(() => {
      csrfRequest = null;
    });
  return csrfRequest;
}

async function send(method: Method, path: string, body?: unknown): Promise<Response> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };

  const xsrfToken = readCookie(XSRF_COOKIE);
  if (method !== "GET" && xsrfToken !== null) {
    headers["X-XSRF-TOKEN"] = xsrfToken;
  }
  if (body !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  try {
    return await fetch(path, {
      method,
      headers,
      credentials: "include",
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    throw new ApiError({ status: null, code: "NETWORK_ERROR" });
  }
}

function sendForm(path: string, form: FormData, options: UploadOptions): Promise<Response> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", path);
    xhr.withCredentials = true;
    xhr.setRequestHeader("Accept", "application/json");
    xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
    const xsrfToken = readCookie(XSRF_COOKIE);
    if (xsrfToken !== null) {
      xhr.setRequestHeader("X-XSRF-TOKEN", xsrfToken);
    }
    for (const [name, value] of Object.entries(options.headers ?? {})) {
      xhr.setRequestHeader(name, value);
    }
    // No Content-Type: the browser sets multipart/form-data with its boundary.

    xhr.upload.onprogress = (event) => {
      if (event.lengthComputable && event.total > 0) {
        options.onProgress?.(Math.min(1, event.loaded / event.total));
      }
    };
    xhr.onload = () => {
      if (xhr.status < 200 || xhr.status > 599) {
        reject(new ApiError({ status: null, code: "NETWORK_ERROR" }));
        return;
      }
      resolve(
        new Response(xhr.status === 204 ? null : xhr.responseText, {
          status: xhr.status,
          headers: parseResponseHeaders(xhr.getAllResponseHeaders()),
        }),
      );
    };
    const fail = () => reject(new ApiError({ status: null, code: "NETWORK_ERROR" }));
    xhr.onerror = fail;
    xhr.onabort = fail;
    xhr.ontimeout = fail;
    xhr.send(form);
  });
}

function parseResponseHeaders(raw: string): Headers {
  const headers = new Headers();
  for (const line of raw.trim().split(/[\r\n]+/)) {
    const separator = line.indexOf(":");
    if (separator > 0) {
      headers.append(line.slice(0, separator).trim(), line.slice(separator + 1).trim());
    }
  }
  return headers;
}

/** Only same-origin absolute paths ("/api/..."), never full or protocol-relative URLs. */
function assertSameOriginPath(path: string): void {
  if (!path.startsWith("/") || path.startsWith("//") || path.includes("\\")) {
    throw new Error(`API paths must be same-origin absolute paths, got "${path}"`);
  }
}

function readCookie(name: string): string | null {
  for (const part of document.cookie.split(";")) {
    const [key, ...value] = part.trim().split("=");
    if (key === name) {
      return decodeURIComponent(value.join("="));
    }
  }
  return null;
}
