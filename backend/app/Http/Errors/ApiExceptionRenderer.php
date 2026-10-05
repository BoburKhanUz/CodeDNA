<?php

declare(strict_types=1);

namespace App\Http\Errors;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every API exception as the standard error envelope:
 *
 *     {"error": {"code": "...", "message": "...", "request_id": "...", "details": {...}}}
 *
 * Messages come from the ErrorCode vocabulary, or from ApiException, whose
 * messages are written for clients. Other exception messages,
 * stack traces and internal details are never included, whatever APP_DEBUG
 * says; they go to the log instead.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $e): JsonResponse
    {
        [$code, $message, $details] = $this->describe($e);

        $error = [
            'code' => $code->value,
            'message' => $message,
            'request_id' => Context::get('request_id'),
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        $headers = $e instanceof HttpExceptionInterface ? $e->getHeaders() : [];

        return new JsonResponse(['error' => $error], $code->status(), $headers);
    }

    /**
     * @return array{0: ErrorCode, 1: string, 2: array<string, mixed>}
     */
    private function describe(Throwable $e): array
    {
        return match (true) {
            $e instanceof ApiException => [$e->errorCode, $e->getMessage(), $e->details],
            $e instanceof ValidationException => [
                ErrorCode::ValidationFailed,
                ErrorCode::ValidationFailed->defaultMessage(),
                ['fields' => $e->errors()],
            ],
            $e instanceof AuthenticationException => $this->standard(ErrorCode::AuthenticationRequired),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => $this->standard(ErrorCode::Forbidden),
            $e instanceof ModelNotFoundException,
            $e instanceof RecordsNotFoundException,
            $e instanceof NotFoundHttpException => $this->standard(ErrorCode::ResourceNotFound),
            $e instanceof MethodNotAllowedHttpException => $this->standard(ErrorCode::MethodNotAllowed),
            $e instanceof TokenMismatchException => $this->standard(ErrorCode::CsrfTokenMismatch),
            $e instanceof PostTooLargeException => $this->standard(ErrorCode::PayloadTooLarge),
            $e instanceof ThrottleRequestsException => $this->standard(ErrorCode::RateLimited),
            $e instanceof HttpExceptionInterface => $this->standard($this->fromStatus($e->getStatusCode())),
            default => $this->standard(ErrorCode::InternalError),
        };
    }

    /**
     * @return array{0: ErrorCode, 1: string, 2: array<string, mixed>}
     */
    private function standard(ErrorCode $code): array
    {
        return [$code, $code->defaultMessage(), []];
    }

    private function fromStatus(int $status): ErrorCode
    {
        return match (true) {
            $status === 401 => ErrorCode::AuthenticationRequired,
            $status === 403 => ErrorCode::Forbidden,
            $status === 404 => ErrorCode::ResourceNotFound,
            $status === 405 => ErrorCode::MethodNotAllowed,
            $status === 413 => ErrorCode::PayloadTooLarge,
            $status === 419 => ErrorCode::CsrfTokenMismatch,
            $status === 429 => ErrorCode::RateLimited,
            $status === 503 => ErrorCode::ServiceUnavailable,
            $status >= 400 && $status < 500 => ErrorCode::BadRequest,
            default => ErrorCode::InternalError,
        };
    }
}
