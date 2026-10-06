<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/projects/{project}/source-snapshots (multipart/form-data).
 *
 * Only presence is validated here: the archive itself is judged by its
 * content (StoreUploadedSource / ZipArchiveInspector), never by its file
 * name or the client-declared MIME type.
 */
final class StoreSourceSnapshotRequest extends FormRequest
{
    /** Letters, digits and . _ : - (UUIDs fit), 8–128 characters. */
    private const IDEMPOTENCY_KEY_PATTERN = '/^[A-Za-z0-9._:-]{8,128}$/';

    /**
     * Owner only; non-owners get 404 before any validation (ProjectPolicy).
     */
    public function authorize(): Response
    {
        return Gate::inspect('uploadSource', $this->route('project'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // `file` also fails when PHP rejected the upload (e.g. above upload_max_filesize).
            'archive' => ['required', 'file'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archive.required' => 'Choose a ZIP archive to upload.',
            'archive.file' => 'The archive could not be uploaded.',
        ];
    }

    public function archive(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('archive');

        return $file;
    }

    /**
     * The optional Idempotency-Key header (docs/api/README.md#idempotency).
     */
    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');
        if ($key === null || $key === '') {
            return null;
        }
        if (preg_match(self::IDEMPOTENCY_KEY_PATTERN, $key) !== 1) {
            throw new ApiException(
                ErrorCode::BadRequest,
                'The Idempotency-Key header must be 8–128 letters, digits or ". _ : -" characters.',
            );
        }

        return $key;
    }
}
