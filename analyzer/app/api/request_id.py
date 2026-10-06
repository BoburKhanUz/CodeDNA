"""Every response carries X-Request-ID: the caller's UUID if it sent a valid
one, otherwise a new UUID4. The ID is available as request.state.request_id."""

import uuid

from starlette.types import ASGIApp, Message, Receive, Scope, Send

from app.auth.hmac_signing import is_uuid

HEADER = "x-request-id"


class RequestIdMiddleware:
    def __init__(self, app: ASGIApp) -> None:
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        incoming = next((value.decode("latin-1") for key, value in scope["headers"] if key == HEADER.encode()), "")
        request_id = incoming if is_uuid(incoming) else str(uuid.uuid4())
        scope.setdefault("state", {})["request_id"] = request_id

        async def send_with_id(message: Message) -> None:
            if message["type"] == "http.response.start":
                headers = [(k, v) for k, v in message.get("headers", []) if k.lower() != HEADER.encode()]
                headers.append((HEADER.encode(), request_id.encode()))
                message["headers"] = headers
            await send(message)

        await self.app(scope, receive, send_with_id)
