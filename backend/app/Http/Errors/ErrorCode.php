<?php

declare(strict_types=1);

namespace App\Http\Errors;

/**
 * The public API's error vocabulary (docs/api/README.md#error-codes).
 *
 * Kept deliberately small: a code identifies what a client can act on, not
 * every internal failure. Add a case only when clients need to distinguish it.
 */
enum ErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case AuthenticationRequired = 'AUTHENTICATION_REQUIRED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Forbidden = 'FORBIDDEN';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case ValidationFailed = 'VALIDATION_FAILED';
    case RateLimited = 'RATE_LIMITED';
    // Projects and source snapshots (Phase 07).
    case ProjectArchived = 'PROJECT_ARCHIVED';
    case InvalidSourceType = 'INVALID_SOURCE_TYPE';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case SourceArchiveInvalid = 'SOURCE_ARCHIVE_INVALID';
    case SourceArchiveUnsafe = 'SOURCE_ARCHIVE_UNSAFE';
    case SourceArchiveTooLarge = 'SOURCE_ARCHIVE_TOO_LARGE';
    case SourceUncompressedSizeExceeded = 'SOURCE_UNCOMPRESSED_SIZE_EXCEEDED';
    case SourceFileCountExceeded = 'SOURCE_FILE_COUNT_EXCEEDED';
    case SourceFileTooLarge = 'SOURCE_FILE_TOO_LARGE';
    // Analysis runs (Phase 10).
    case AnalysisNotCompleted = 'ANALYSIS_NOT_COMPLETED';
    // AI assessments (Phase 15).
    case AiAssessmentDisabled = 'AI_ASSESSMENT_DISABLED';
    case AssessmentEvidenceUnavailable = 'ASSESSMENT_EVIDENCE_UNAVAILABLE';
    case AssessmentInputTooLarge = 'ASSESSMENT_INPUT_TOO_LARGE';
    // Local AI intelligence (Phase 29).
    case AiUnavailable = 'AI_UNAVAILABLE';
    case InsightEvidenceUnavailable = 'INSIGHT_EVIDENCE_UNAVAILABLE';
    case InsightInputTooLarge = 'INSIGHT_INPUT_TOO_LARGE';
    // Coding challenges (Phase 16).
    case ChallengesDisabled = 'CHALLENGES_DISABLED';
    case ChallengeNoEligibleGap = 'CHALLENGE_NO_ELIGIBLE_GAP';
    case ChallengeNoneAvailable = 'CHALLENGE_NONE_AVAILABLE';
    case ChallengeEvaluationUnavailable = 'CHALLENGE_EVALUATION_UNAVAILABLE';
    case ChallengeEvaluationPending = 'CHALLENGE_EVALUATION_PENDING';
    case ChallengeClosed = 'CHALLENGE_CLOSED';
    // Learning roadmaps (Phase 17).
    case RoadmapNoSkillGaps = 'ROADMAP_NO_SKILL_GAPS';
    case RoadmapNoActionableGaps = 'ROADMAP_NO_ACTIONABLE_GAPS';
    case RoadmapEvidenceInvalid = 'ROADMAP_EVIDENCE_INVALID';
    case RoadmapNotActive = 'ROADMAP_NOT_ACTIVE';
    case RoadmapStepPrerequisitesIncomplete = 'ROADMAP_STEP_PREREQUISITES_INCOMPLETE';
    // GitHub integration (Phase 19).
    case GitHubNotConfigured = 'GITHUB_NOT_CONFIGURED';
    case GitHubAuthRequired = 'GITHUB_AUTH_REQUIRED';
    case GitHubStateInvalid = 'GITHUB_STATE_INVALID';
    case GitHubInstallationRequired = 'GITHUB_INSTALLATION_REQUIRED';
    case GitHubRepositoryNotFound = 'GITHUB_REPOSITORY_NOT_FOUND';
    case GitHubBranchNotFound = 'GITHUB_BRANCH_NOT_FOUND';
    case GitHubAlreadyConnected = 'GITHUB_ALREADY_CONNECTED';
    case GitHubNotConnected = 'GITHUB_NOT_CONNECTED';
    case GitHubRateLimited = 'GITHUB_RATE_LIMITED';
    case GitHubUnavailable = 'GITHUB_UNAVAILABLE';
    // GitLab and Bitbucket Cloud (Phase 28); `details.provider` names the provider.
    case ProviderNotConfigured = 'PROVIDER_NOT_CONFIGURED';
    case ProviderAuthRequired = 'PROVIDER_AUTH_REQUIRED';
    case ProviderStateInvalid = 'PROVIDER_STATE_INVALID';
    case ProviderAccountInUse = 'PROVIDER_ACCOUNT_IN_USE';
    case ProviderRepositoryNotFound = 'PROVIDER_REPOSITORY_NOT_FOUND';
    case ProviderBranchNotFound = 'PROVIDER_BRANCH_NOT_FOUND';
    case ProviderNotConnected = 'PROVIDER_NOT_CONNECTED';
    case ProviderRateLimited = 'PROVIDER_RATE_LIMITED';
    case ProviderUnavailable = 'PROVIDER_UNAVAILABLE';
    // One repository source per project, whatever its provider (Phase 28).
    case SourceAlreadyConnected = 'SOURCE_ALREADY_CONNECTED';
    // Billing (Phase 23): commercial denials, never server failures.
    case FeatureNotIncluded = 'FEATURE_NOT_INCLUDED';
    case SubscriptionInactive = 'SUBSCRIPTION_INACTIVE';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case BillingUnavailable = 'BILLING_UNAVAILABLE';
    // Organizations (Phase 24). An unknown organization, or one the caller
    // is not a member of, is RESOURCE_NOT_FOUND, as for projects.
    case OrganizationSuspended = 'ORGANIZATION_SUSPENDED';
    case OrganizationArchived = 'ORGANIZATION_ARCHIVED';
    case MembershipSuspended = 'MEMBERSHIP_SUSPENDED';
    case InsufficientOrganizationRole = 'INSUFFICIENT_ORGANIZATION_ROLE';
    case AlreadyAMember = 'ALREADY_A_MEMBER';
    case InvitationExpired = 'INVITATION_EXPIRED';
    case InvitationRevoked = 'INVITATION_REVOKED';
    case InvitationAlreadyAccepted = 'INVITATION_ALREADY_ACCEPTED';
    case InvitationEmailMismatch = 'INVITATION_EMAIL_MISMATCH';
    case SeatLimitReached = 'SEAT_LIMIT_REACHED';
    case CannotRemoveOwner = 'CANNOT_REMOVE_OWNER';
    case CannotChangeOwnerRole = 'CANNOT_CHANGE_OWNER_ROLE';
    // Self-hosted installations (Phase 27).
    case RegistrationClosed = 'REGISTRATION_CLOSED';
    case InternalError = 'INTERNAL_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::AuthenticationRequired => 401,
            self::Forbidden, self::MembershipSuspended, self::InsufficientOrganizationRole, self::InvitationEmailMismatch, self::RegistrationClosed => 403,
            self::ResourceNotFound => 404,
            self::MethodNotAllowed => 405,
            self::PayloadTooLarge, self::SourceArchiveTooLarge => 413,
            self::CsrfTokenMismatch => 419,
            self::ProjectArchived, self::InvalidSourceType, self::AnalysisNotCompleted,
            self::AiAssessmentDisabled, self::AssessmentEvidenceUnavailable, self::AssessmentInputTooLarge,
            self::InsightEvidenceUnavailable, self::InsightInputTooLarge,
            self::ChallengesDisabled, self::ChallengeNoEligibleGap, self::ChallengeNoneAvailable,
            self::ChallengeEvaluationUnavailable, self::ChallengeEvaluationPending, self::ChallengeClosed,
            self::RoadmapNoSkillGaps, self::RoadmapNoActionableGaps, self::RoadmapEvidenceInvalid,
            self::RoadmapNotActive, self::RoadmapStepPrerequisitesIncomplete,
            self::GitHubAuthRequired, self::GitHubInstallationRequired, self::GitHubAlreadyConnected, self::GitHubNotConnected,
            self::ProviderAuthRequired, self::ProviderAccountInUse, self::ProviderNotConnected, self::SourceAlreadyConnected,
            self::OrganizationSuspended, self::OrganizationArchived, self::AlreadyAMember, self::InvitationExpired,
            self::InvitationRevoked, self::InvitationAlreadyAccepted, self::CannotRemoveOwner, self::CannotChangeOwnerRole => 409,
            self::ValidationFailed, self::InvalidCredentials, self::IdempotencyKeyReused,
            self::SourceArchiveInvalid, self::SourceArchiveUnsafe, self::SourceUncompressedSizeExceeded,
            self::SourceFileCountExceeded, self::SourceFileTooLarge,
            self::GitHubStateInvalid, self::GitHubRepositoryNotFound, self::GitHubBranchNotFound,
            self::ProviderStateInvalid, self::ProviderRepositoryNotFound, self::ProviderBranchNotFound => 422,
            self::FeatureNotIncluded, self::SubscriptionInactive, self::QuotaExceeded, self::SeatLimitReached => 402,
            self::RateLimited, self::GitHubRateLimited, self::ProviderRateLimited => 429,
            self::InternalError => 500,
            self::ServiceUnavailable, self::GitHubNotConfigured, self::GitHubUnavailable, self::BillingUnavailable,
            self::ProviderNotConfigured, self::ProviderUnavailable, self::AiUnavailable => 503,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::BadRequest => 'The request could not be processed.',
            self::AuthenticationRequired => 'Authentication is required.',
            self::InvalidCredentials => 'These credentials do not match our records.',
            self::Forbidden => 'This action is not allowed.',
            self::ResourceNotFound => 'The requested resource was not found.',
            self::MethodNotAllowed => 'This HTTP method is not supported for this resource.',
            self::PayloadTooLarge => 'The request body is too large.',
            self::CsrfTokenMismatch => 'CSRF token mismatch. Request a new token and retry.',
            self::ValidationFailed => 'The given data was invalid.',
            self::RateLimited => 'Too many requests. Retry later.',
            self::ProjectArchived => 'This project is archived and cannot be changed.',
            self::InvalidSourceType => 'This project does not accept uploaded source.',
            self::IdempotencyKeyReused => 'This Idempotency-Key was already used for a different upload.',
            self::SourceArchiveInvalid => 'The file is not a valid ZIP archive.',
            self::SourceArchiveUnsafe => 'The archive contains an unsafe entry.',
            self::SourceArchiveTooLarge => 'The archive is larger than the upload limit.',
            self::SourceUncompressedSizeExceeded => 'The archive expands beyond the allowed total size.',
            self::SourceFileCountExceeded => 'The archive contains more files than allowed.',
            self::SourceFileTooLarge => 'The archive contains a file larger than allowed.',
            self::AnalysisNotCompleted => 'This analysis has no result: it has not succeeded.',
            self::AiAssessmentDisabled => 'AI assessment is not enabled on this server.',
            self::AssessmentEvidenceUnavailable => 'There is no skill gap analysis that can be interpreted for this project.',
            self::AssessmentInputTooLarge => 'The evidence of this analysis exceeds the AI input limit.',
            self::AiUnavailable => 'The local AI service or its model is not available right now. Nothing was queued.',
            self::InsightEvidenceUnavailable => 'There is no deterministic result here that AI can interpret yet.',
            self::InsightInputTooLarge => 'The evidence exceeds the configured AI input or context limit.',
            self::ChallengesDisabled => 'Coding challenges are not enabled on this server.',
            self::ChallengeNoEligibleGap => 'There is no material skill gap with a supported challenge category.',
            self::ChallengeNoneAvailable => 'Every challenge for this skill gap analysis has already been assigned.',
            self::ChallengeEvaluationUnavailable => 'Challenge evaluation is not available right now. Nothing was submitted.',
            self::ChallengeEvaluationPending => 'The previous attempt is still being evaluated.',
            self::ChallengeClosed => 'This challenge is closed and accepts no further attempts.',
            self::RoadmapNoSkillGaps => 'This project has no skill gap analysis yet.',
            self::RoadmapNoActionableGaps => 'The newest skill gap analysis has no measurable gap with a learning track.',
            self::RoadmapEvidenceInvalid => 'The newest skill gap analysis does not match its specification and cannot be used.',
            self::RoadmapNotActive => 'This roadmap is no longer active and cannot be changed.',
            self::RoadmapStepPrerequisitesIncomplete => 'Complete the earlier steps this step depends on first.',
            self::GitHubNotConfigured => 'GitHub integration is not configured on this server.',
            self::GitHubAuthRequired => 'Connect your GitHub account first.',
            self::GitHubStateInvalid => 'This GitHub authorization link is invalid, expired or already used. Start again.',
            self::GitHubInstallationRequired => 'Install the CodeDNA GitHub App for this repository first.',
            self::GitHubRepositoryNotFound => 'This repository is not available to you through the CodeDNA GitHub App.',
            self::GitHubBranchNotFound => 'This branch does not exist in the repository.',
            self::GitHubAlreadyConnected => 'This project is already connected to a GitHub repository. Disconnect it first.',
            self::GitHubNotConnected => 'This project is not connected to a GitHub repository.',
            self::GitHubRateLimited => 'GitHub is rate limiting requests. Retry later.',
            self::ProviderNotConfigured => 'This repository provider is not configured on this server.',
            self::ProviderAuthRequired => 'Connect your account with this repository provider first, or connect it again.',
            self::ProviderStateInvalid => 'This authorization link is invalid, expired or already used. Start again.',
            self::ProviderAccountInUse => 'This provider account is already linked to another CodeDNA account.',
            self::ProviderRepositoryNotFound => 'This repository is not available to your connected account.',
            self::ProviderBranchNotFound => 'This branch does not exist in the repository.',
            self::ProviderNotConnected => 'This project is not connected to a repository on this provider.',
            self::ProviderRateLimited => 'The repository provider is rate limiting requests. Retry later.',
            self::ProviderUnavailable => 'The repository provider is not reachable right now. Retry later.',
            self::SourceAlreadyConnected => 'This project is already connected to a repository. Disconnect it first.',
            self::FeatureNotIncluded => 'Your plan does not include this feature.',
            self::SubscriptionInactive => 'Your subscription is not active, so its features are paused.',
            self::QuotaExceeded => 'This would exceed what your plan includes for the current period.',
            self::BillingUnavailable => 'Billing is not available right now. Retry later.',
            self::OrganizationSuspended => 'This organization is suspended; it can be read but not changed.',
            self::OrganizationArchived => 'This organization is archived; it can be read but not changed.',
            self::MembershipSuspended => 'Your membership in this organization is suspended.',
            self::InsufficientOrganizationRole => 'Your role in this organization does not allow this.',
            self::AlreadyAMember => 'This person is already a member of the organization.',
            self::InvitationExpired => 'This invitation has expired. Ask for a new one.',
            self::InvitationRevoked => 'This invitation was revoked.',
            self::InvitationAlreadyAccepted => 'This invitation was already used.',
            self::InvitationEmailMismatch => 'This invitation was sent to a different email address.',
            self::SeatLimitReached => 'The organization has no free seat.',
            self::CannotRemoveOwner => 'The owner cannot be removed from the organization.',
            self::CannotChangeOwnerRole => "The owner's role and status cannot be changed.",
            self::RegistrationClosed => 'New accounts cannot be created on this installation. Ask its administrator for access.',
            self::GitHubUnavailable => 'GitHub is not reachable right now. Retry later.',
            self::InternalError => 'An unexpected error occurred.',
            self::ServiceUnavailable => 'The service is temporarily unavailable.',
        };
    }
}
