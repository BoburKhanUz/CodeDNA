<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Enterprise license verification keys (Phase 27)
|--------------------------------------------------------------------------
|
| docs/enterprise/licensing.md#keys. The Ed25519 PUBLIC keys whose
| signatures this release accepts, by key id: key id => base64 of the
| 32-byte public key. Only public keys ever appear here. The private signing
| key is held by the license issuer, offline, and never enters the
| repository, an image, CI or any configuration example.
|
| Deliberately not read from the environment: an operator-supplied key
| would let anyone sign their own license. Keys are added (and retired) in
| a release, which is how a key is rotated.
|
| No issuing key has been published for this release yet, so every license
| is refused as UNKNOWN_KEY and the installation runs as the Community
| edition. Tests use their own throwaway keys (tests/Support/LicenseFixtures).
|
*/

return [
    'trusted_keys' => [],
];
