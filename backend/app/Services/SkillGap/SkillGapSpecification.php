<?php

declare(strict_types=1);

namespace App\Services\SkillGap;

use App\Enums\Competency\CompetencyKey;
use App\Enums\SkillGap\GapPriority;
use App\Services\Analyzer\CanonicalJson;
use App\Services\Dna\FixedPoint;
use InvalidArgumentException;
use LogicException;

/**
 * The authoritative definition of every skill gap version
 * (docs/architecture/skill-gap-v1.md): the target profile, the
 * material-gap threshold and the priority rules. The engine has no constants
 * of its own.
 *
 * A published version never changes meaning: any change to a target,
 * threshold or priority rule is a new version. The fingerprint of 1.0.0 is
 * pinned by a test. Target values are product calibration choices, not
 * empirical industry standards.
 */
final readonly class SkillGapSpecification
{
    public const VERSION_1_0_0 = '1.0.0';

    /** Every skill gap version this code can compute. */
    public const VERSIONS = [self::VERSION_1_0_0];

    /**
     * @param  list<string>  $competencyVersions  competency versions whose results this version reads
     * @param  int  $materialGapThreshold  smallest raw gap (units) that counts as a gap
     * @param  array<string, int>  $priorities  priority value => smallest raw gap (units), ascending, LOW first
     * @param  int  $highPriorityMinimumEvidenceQuality  below this evidence quality (units) a HIGH priority is capped at MEDIUM
     */
    public function __construct(
        public string $version,
        public array $competencyVersions,
        public TargetProfile $targetProfile,
        public int $materialGapThreshold,
        public array $priorities,
        public int $highPriorityMinimumEvidenceQuality,
    ) {
        $this->validate();
    }

    public static function forVersion(string $version): self
    {
        return match ($version) {
            self::VERSION_1_0_0 => self::v1_0_0(),
            default => throw new InvalidArgumentException("Unknown skill gap version: {$version}"),
        };
    }

    /**
     * Skill gap version 1.0.0: target profile ENGINEERING_STANDARD 1.0.0 on
     * competency version 1.0.0 (levels at 0.40 / 0.65 / 0.85).
     *
     * Targets sit inside the ESTABLISHED band (0.75) for the design
     * competencies, and higher (0.90) for syntax validity, where a score of
     * 0.90 still allows about 2.5% of files with syntax errors. A gap below
     * 0.05 is not material (for a share component that is about one function
     * in a hundred). Priorities: LOW from 0.05, MEDIUM from 0.15 (more than
     * one level band), HIGH from 0.30, and HIGH only with evidence quality of
     * at least 0.60. All values are calibration choices.
     */
    public static function v1_0_0(): self
    {
        $u = FixedPoint::parse(...);

        return new self(
            version: self::VERSION_1_0_0,
            competencyVersions: ['1.0.0'],
            targetProfile: new TargetProfile(
                key: 'ENGINEERING_STANDARD',
                version: '1.0.0',
                description: 'A defined, measurable engineering standard for the analyzed code. Not a job title or seniority level.',
                targets: [
                    CompetencyKey::ComplexityManagement->value => $u('0.75'),
                    CompetencyKey::FunctionDesign->value => $u('0.75'),
                    CompetencyKey::TypeStructure->value => $u('0.75'),
                    CompetencyKey::CodeHygiene->value => $u('0.90'),
                ],
                rationales: [
                    CompetencyKey::ComplexityManagement->value => 'Inside the ESTABLISHED band: average and concentrated branching close to the best thresholds.',
                    CompetencyKey::FunctionDesign->value => 'Inside the ESTABLISHED band: few long functions, wide parameter lists or deep nesting.',
                    CompetencyKey::TypeStructure->value => 'Inside the ESTABLISHED band: few types above the length threshold.',
                    CompetencyKey::CodeHygiene->value => 'Syntax validity is expected of analyzable code: 0.90 allows about 2.5% of files with syntax errors.',
                ],
            ),
            materialGapThreshold: $u('0.05'),
            priorities: [
                GapPriority::Low->value => $u('0.05'),
                GapPriority::Medium->value => $u('0.15'),
                GapPriority::High->value => $u('0.30'),
            ],
            highPriorityMinimumEvidenceQuality: $u('0.60'),
        );
    }

    /**
     * The priority of a material raw gap: the highest level whose bound it
     * reaches; HIGH needs enough evidence quality, otherwise MEDIUM.
     *
     * @return array{0: GapPriority, 1: bool} priority and whether HIGH was capped
     */
    public function priorityFor(int $rawGap, int $evidenceQuality): array
    {
        if ($rawGap < $this->materialGapThreshold) {
            throw new LogicException('Only material gaps have a priority.');
        }
        $priority = GapPriority::Low;
        foreach ($this->priorities as $value => $minimum) {
            if ($rawGap >= $minimum) {
                $priority = GapPriority::from($value);
            }
        }
        if ($priority === GapPriority::High && $evidenceQuality < $this->highPriorityMinimumEvidenceQuality) {
            return [GapPriority::Medium, true];
        }

        return [$priority, false];
    }

    /**
     * The complete definition as plain data, in a fixed order.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $format = FixedPoint::format(...);
        $profile = $this->targetProfile;

        return [
            'version' => $this->version,
            'competency_versions' => $this->competencyVersions,
            'arithmetic' => 'integer fixed-point, 4 decimal places',
            'gap' => 'raw_gap = max(target_score - current_score, 0); material when raw_gap >= material_gap_threshold',
            'target_profile' => [
                'key' => $profile->key,
                'version' => $profile->version,
                'description' => $profile->description,
                'targets' => array_map($format, $profile->targets),
                'rationales' => $profile->rationales,
            ],
            'material_gap_threshold' => $format($this->materialGapThreshold),
            'priorities' => array_map($format, $this->priorities),
            'high_priority_minimum_evidence_quality' => $format($this->highPriorityMinimumEvidenceQuality),
        ];
    }

    /**
     * SHA-256 of the canonical JSON of toArray().
     */
    public function fingerprint(): string
    {
        return CanonicalJson::hash(json_decode((string) json_encode($this->toArray(), JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
    }

    private function validate(): void
    {
        foreach ([$this->version, $this->targetProfile->version] as $version) {
            if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $version) !== 1) {
                throw new LogicException('Versions are MAJOR.MINOR.PATCH.');
            }
        }
        if ($this->competencyVersions === [] || preg_match('/^[A-Z][A-Z_]*$/', $this->targetProfile->key) !== 1) {
            throw new LogicException('A skill gap version needs competency versions and a target profile key.');
        }
        $targets = $this->targetProfile->targets;
        if ($targets === []) {
            throw new LogicException('A target profile needs targets.');
        }
        foreach ($targets as $key => $target) {
            if (CompetencyKey::tryFrom($key) === null) {
                throw new LogicException("Unknown competency {$key}.");
            }
            if ($target <= 0 || $target > FixedPoint::ONE) {
                throw new LogicException('Targets are within (0, 1].');
            }
            if (! isset($this->targetProfile->rationales[$key])) {
                throw new LogicException("Target {$key} needs a rationale.");
            }
        }
        if (array_keys($this->targetProfile->rationales) !== array_keys($targets)) {
            throw new LogicException('Rationales must match the targets.');
        }
        if ($this->materialGapThreshold <= 0 || $this->materialGapThreshold >= FixedPoint::ONE) {
            throw new LogicException('The material-gap threshold is within (0, 1).');
        }
        if (array_keys($this->priorities) !== array_map(fn (GapPriority $p): string => $p->value, GapPriority::cases())) {
            throw new LogicException('Every priority must be defined, LOW first.');
        }
        $bounds = array_values($this->priorities);
        if ($bounds[0] !== $this->materialGapThreshold) {
            throw new LogicException('LOW starts at the material-gap threshold.');
        }
        for ($i = 1; $i < count($bounds); $i++) {
            if ($bounds[$i] <= $bounds[$i - 1] || $bounds[$i] > FixedPoint::ONE) {
                throw new LogicException('Priority bounds must strictly increase within 1.');
            }
        }
        if ($this->highPriorityMinimumEvidenceQuality < 0 || $this->highPriorityMinimumEvidenceQuality > FixedPoint::ONE) {
            throw new LogicException('The evidence-quality bound is within [0, 1].');
        }
    }
}
