<?php

declare(strict_types=1);

namespace App\Http\Requests\Challenge;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use App\Services\Challenge\ChallengeCatalog;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/projects/{project}/challenges/{challenge}/submissions
 *
 *     { "language": "python", "source": "def solve(...):\n    ..." }
 *     Idempotency-Key: <optional, 8–128 of A-Z a-z 0-9 . _ : ->
 *
 * One file of source in a supported language, within the configured size
 * and line limits, valid UTF-8 without NUL bytes. Nothing else: no command,
 * test, dependency, file name, image or runtime can be supplied.
 */
final class StoreChallengeSubmissionRequest extends FormRequest
{
    private const FIELDS = ['language', 'source'];

    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9._:\-]{8,128}$/';

    public function authorize(): Response
    {
        return Gate::inspect('practice', $this->route('project'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'language' => ['required', 'string', Rule::in(ChallengeCatalog::EXECUTABLE_LANGUAGES)],
            'source' => ['required', 'string'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (array_diff(array_map('strval', array_keys($this->all())), self::FIELDS) !== []) {
                    $validator->errors()->add('request', 'Only language and source are accepted.');
                }
                $source = $this->input('source');
                if (! is_string($source)) {
                    return;
                }
                $maxBytes = (int) config('codedna.challenges.max_source_bytes');
                $maxLines = (int) config('codedna.challenges.max_source_lines');
                if (strlen($source) > $maxBytes) {
                    $validator->errors()->add('source', "The source must not be larger than {$maxBytes} bytes.");
                } elseif (! mb_check_encoding($source, 'UTF-8') || str_contains($source, "\0")) {
                    $validator->errors()->add('source', 'The source must be UTF-8 text without NUL characters.');
                } elseif (substr_count($source, "\n") + 1 > $maxLines) {
                    $validator->errors()->add('source', "The source must not have more than {$maxLines} lines.");
                } elseif (trim($source) === '') {
                    $validator->errors()->add('source', 'The source must not be empty.');
                }
            },
        ];
    }

    public function language(): string
    {
        return (string) $this->validated('language');
    }

    public function source(): string
    {
        return (string) $this->validated('source');
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return null;
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new ApiException(ErrorCode::BadRequest, 'The Idempotency-Key header must be 8–128 letters, digits or ". _ : -" characters.');
        }

        return $key;
    }
}
