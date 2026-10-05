#!/bin/sh
# Idempotent MinIO initialisation for local development (runs on every
# `docker compose up` via the one-shot `minio-init` service):
#
#   1. the source bucket exists and has no anonymous access;
#   2. an application user exists whose policy is limited to that bucket.
#
# The application (Laravel now, via the S3 API) uses only the application
# user's credentials (SOURCE_STORAGE_*), never the MinIO root credentials.
set -eu

: "${MINIO_ROOT_USER:?MINIO_ROOT_USER is required}"
: "${MINIO_ROOT_PASSWORD:?MINIO_ROOT_PASSWORD is required}"
: "${SOURCE_STORAGE_BUCKET:?SOURCE_STORAGE_BUCKET is required}"
: "${SOURCE_STORAGE_ACCESS_KEY_ID:?SOURCE_STORAGE_ACCESS_KEY_ID is required}"
: "${SOURCE_STORAGE_SECRET_ACCESS_KEY:?SOURCE_STORAGE_SECRET_ACCESS_KEY is required}"

alias=codedna
bucket="$SOURCE_STORAGE_BUCKET"
policy_name=codedna-app
policy_file=$(mktemp)
trap 'rm -f "$policy_file"' EXIT

mc alias set "$alias" http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null

mc mb --ignore-existing "$alias/$bucket"
mc anonymous set none "$alias/$bucket" >/dev/null

cat > "$policy_file" <<JSON
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["s3:GetBucketLocation", "s3:ListBucket"],
      "Resource": ["arn:aws:s3:::${bucket}"]
    },
    {
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
      "Resource": ["arn:aws:s3:::${bucket}/*"]
    }
  ]
}
JSON

# `policy create` replaces an existing policy of the same name; `user add`
# updates the secret of an existing user. Both are safe to repeat.
mc admin policy create "$alias" "$policy_name" "$policy_file" >/dev/null
mc admin user add "$alias" "$SOURCE_STORAGE_ACCESS_KEY_ID" "$SOURCE_STORAGE_SECRET_ACCESS_KEY" >/dev/null

# `policy attach` fails if the policy is already attached, so check first.
# Match the JSON "policyName" field exactly (comma-separated list): the access
# key itself may contain the policy name as a substring.
if ! mc admin user info "$alias" "$SOURCE_STORAGE_ACCESS_KEY_ID" --json \
    | grep -Eq "\"policyName\":\"([^\"]*,)?${policy_name}(,|\")"; then
    mc admin policy attach "$alias" "$policy_name" --user "$SOURCE_STORAGE_ACCESS_KEY_ID" >/dev/null
fi

echo "minio-init: bucket '$bucket' and application user ready"
