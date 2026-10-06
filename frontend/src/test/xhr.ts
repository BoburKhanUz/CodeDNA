/**
 * Minimal XMLHttpRequest stand-in for upload tests (jsdom's would try the
 * network). Install with vi.stubGlobal("XMLHttpRequest", FakeXhr) and set
 * FakeXhr.handler to answer each request.
 */
export class FakeXhr {
  static instances: FakeXhr[] = [];
  static handler: (xhr: FakeXhr) => void = () => {};

  static reset() {
    FakeXhr.instances = [];
    FakeXhr.handler = () => {};
  }

  method = "";
  url = "";
  requestHeaders: Record<string, string> = {};
  body: unknown = null;
  withCredentials = false;
  status = 0;
  responseText = "";
  private responseHeaders: Record<string, string> = {};
  upload: { onprogress: ((event: { lengthComputable: boolean; loaded: number; total: number }) => void) | null } = {
    onprogress: null,
  };
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  onabort: (() => void) | null = null;
  ontimeout: (() => void) | null = null;

  open(method: string, url: string) {
    this.method = method;
    this.url = url;
  }

  setRequestHeader(name: string, value: string) {
    this.requestHeaders[name] = value;
  }

  send(body: unknown) {
    this.body = body;
    FakeXhr.instances.push(this);
    queueMicrotask(() => FakeXhr.handler(this));
  }

  getAllResponseHeaders() {
    return Object.entries(this.responseHeaders)
      .map(([name, value]) => `${name}: ${value}`)
      .join("\r\n");
  }

  progress(loaded: number, total: number) {
    this.upload.onprogress?.({ lengthComputable: true, loaded, total });
  }

  respond(status: number, body: unknown, headers: Record<string, string> = {}) {
    this.status = status;
    this.responseText = body === undefined ? "" : JSON.stringify(body);
    this.responseHeaders = { "content-type": "application/json", ...headers };
    this.onload?.();
  }

  failNetwork() {
    this.onerror?.();
  }
}
