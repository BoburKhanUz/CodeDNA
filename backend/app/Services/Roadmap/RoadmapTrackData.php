<?php

declare(strict_types=1);

namespace App\Services\Roadmap;

use App\Enums\Competency\CompetencyKey;
use App\Services\Analyzer\CanonicalJson;

/**
 * One learning track of the roadmap catalog: a validated
 * roadmap-track/1 document (resources/roadmaps/v1/<KEY>.json).
 */
final readonly class RoadmapTrackData
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function __construct(public array $document) {}

    public function key(): string
    {
        return (string) $this->document['key'];
    }

    public function version(): string
    {
        return (string) $this->document['version'];
    }

    public function competency(): CompetencyKey
    {
        return CompetencyKey::from((string) $this->document['competency']);
    }

    /**
     * @return list<array<string, mixed>> in learning order
     */
    public function steps(): array
    {
        return array_values($this->document['steps']);
    }

    /**
     * SHA-256 over the canonical JSON of the whole track.
     */
    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($this->document, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR));
    }
}
