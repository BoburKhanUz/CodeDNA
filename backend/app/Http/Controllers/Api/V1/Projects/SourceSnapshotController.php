<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Projects;

use App\Actions\Snapshots\StoreUploadedSource;
use App\Http\Controllers\Controller;
use App\Http\Pagination\KeysetPaginator;
use App\Http\Requests\Projects\ListSourceSnapshotsRequest;
use App\Http\Requests\Projects\StoreSourceSnapshotRequest;
use App\Http\Resources\CursorCollection;
use App\Http\Resources\PaginatedCollection;
use App\Http\Resources\SourceSnapshotResource;
use App\Models\Project;
use App\Models\SourceSnapshot;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

/**
 * /api/v1/projects/{project}/source-snapshots — list, view and upload.
 *
 * Snapshots are immutable: there is no update or delete, and no download
 * (the analyzer will read objects through pre-signed URLs, Phase 10).
 */
final class SourceSnapshotController extends Controller
{
    public function index(ListSourceSnapshotsRequest $request, Project $project, Gate $gate, KeysetPaginator $keyset): PaginatedCollection|CursorCollection
    {
        $gate->authorize('view', $project);

        if ($request->usesCursor()) {
            return new CursorCollection($keyset->paginate(
                $project->sourceSnapshots()->getQuery(), ['source_snapshots.version'],
                fn (SourceSnapshot $snapshot): array => [(string) $snapshot->version],
                'source-snapshots:'.$project->id, $request->cursor(), $request->perPage(),
            ), SourceSnapshotResource::class);
        }

        $snapshots = $project->sourceSnapshots()
            ->orderByDesc('version')
            ->paginate($request->perPage(), page: $request->page());

        return new PaginatedCollection($snapshots, SourceSnapshotResource::class);
    }

    public function show(Project $project, SourceSnapshot $sourceSnapshot, Gate $gate): SourceSnapshotResource
    {
        // The route uses scoped bindings: the snapshot belongs to this project.
        $gate->authorize('view', $project);

        return new SourceSnapshotResource($sourceSnapshot);
    }

    public function store(StoreSourceSnapshotRequest $request, Project $project, StoreUploadedSource $storeUploadedSource): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $storeUploadedSource->handle(
            $project,
            $user,
            (string) $request->archive()->getRealPath(),
            $request->idempotencyKey(),
        );

        $response = (new SourceSnapshotResource($result->snapshot))->response();
        if ($result->created) {
            return $response->setStatusCode(201);
        }

        // An idempotent retry: the snapshot the first request created.
        return $response->setStatusCode(200)->header('Idempotent-Replayed', 'true');
    }
}
