<?php

declare(strict_types=1);

namespace App\Services\Enterprise;

/**
 * The outcome of reading the installation's license (Phase 27,
 * docs/enterprise/licensing.md#statuses). Only VALID grants anything; every
 * other status leaves the installation on the Community edition.
 */
enum LicenseStatus: string
{
    /** No license configured, or an empty license file. */
    case Absent = 'ABSENT';
    /** Verified: signed by a trusted key, this installation, within its validity. */
    case Valid = 'VALID';
    /** The configured file is missing, unreadable or too large. */
    case Unreadable = 'UNREADABLE';
    /** Not a license document, or a signed payload with invalid claims. */
    case Malformed = 'MALFORMED';
    /** A license format or schema version this release does not understand. */
    case UnsupportedVersion = 'UNSUPPORTED_VERSION';
    /** Signed with a key this release does not trust (or no key is trusted yet). */
    case UnknownKey = 'UNKNOWN_KEY';
    /** The signature does not match the document: altered, or forged. */
    case InvalidSignature = 'INVALID_SIGNATURE';
    /** Issued for another installation (its APP_URL host). */
    case WrongInstallation = 'WRONG_INSTALLATION';
    /** Its validity has not started yet. */
    case NotYetValid = 'NOT_YET_VALID';
    /** Its validity has ended. */
    case Expired = 'EXPIRED';

    public function grants(): bool
    {
        return $this === self::Valid;
    }
}
