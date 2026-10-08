<?php

declare(strict_types=1);

use App\Enums\Billing\QuotaKey;
use App\Enums\Organizations\MembershipStatus;
use App\Enums\Organizations\OrganizationAuditAction;
use App\Enums\Organizations\OrganizationRole;
use App\Enums\Organizations\OrganizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 24: organizations (teams) and their members, invitations, audit
 * log and billing account, and organization-owned projects
 * (docs/teams/teams-architecture.md).
 *
 * Additive and safe for existing data: every existing project keeps
 * organization_id NULL (personal), users, billing and history are
 * unchanged. The only change to existing constraints is that project slugs
 * become unique per owner among personal projects (the existing rule, since
 * every existing project is personal) and per organization among team
 * projects; and a usage-ledger entry may name an organization instead of a
 * user as its billing subject.
 *
 * - organizations: the tenant. Exactly one OWNER, who is owner_user_id,
 *   with an ACTIVE membership, and one billing account (checked at commit
 *   by a deferred constraint trigger, so both can be created together).
 * - organization_memberships: one row per organization and user, ever.
 *   Removal and suspension are statuses, never deletes.
 * - organization_invitations: only the SHA-256 of the token is stored. An
 *   accepted or revoked invitation never changes again.
 * - organization_audit_events: append-only.
 * - organization_billing_accounts: the organization as a billing subject
 *   (plan reference, seat entitlement). No payment data.
 * - billing_organization_usage_counters: quota counters of organizations;
 *   the usage ledger (billing_usage_events) records them with
 *   organization_id instead of user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default(OrganizationStatus::Active->value);
            $table->timestamps();
            $table->index('owner_user_id');
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 16);
            $table->string('status', 16);
            $table->timestamp('joined_at');
            $table->timestamps();
            // One membership per organization and user, whatever its status.
            $table->unique(['organization_id', 'user_id']);
            $table->unique(['id', 'organization_id']);
            // "My organizations" and seat counts.
            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'status', 'role']);
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->string('email', 254);
            $table->string('role', 16);
            $table->char('token_hash', 64)->unique();
            $table->foreignUlid('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignUlid('accepted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('organization_audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 40);
            $table->string('target_type', 32)->nullable();
            $table->ulid('target_id')->nullable();
            $table->jsonb('metadata')->default('{}');
            $table->string('request_id', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // The timeline, newest first.
            $table->index(['organization_id', 'created_at', 'id']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('organization_billing_accounts', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->primary()->constrained()->restrictOnDelete();
            $table->foreignUlid('billing_plan_id')->constrained('billing_plans')->restrictOnDelete();
            $table->string('entitlement_version', 16);
            $table->integer('seat_limit');
            $table->timestamps();
        });

        Schema::create('billing_organization_usage_counters', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->string('quota_key', 32);
            $table->timestamp('period_start');
            $table->bigInteger('used')->default(0);
            $table->timestamp('updated_at')->useCurrent();
            $table->primary(['organization_id', 'quota_key', 'period_start']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });
        Schema::table('billing_usage_events', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
        });

        $in = static fn (array $cases): string => implode(', ', array_map(fn ($c): string => "'".$c->value."'", $cases));
        $orgStatuses = $in(OrganizationStatus::cases());
        $roles = $in(OrganizationRole::cases());
        $assignable = $in(OrganizationRole::assignable());
        $memberStatuses = $in(MembershipStatus::cases());
        $actions = $in(OrganizationAuditAction::cases());
        $quotas = $in(QuotaKey::cases());

        DB::statement(<<<SQL
            ALTER TABLE organizations
                ADD CONSTRAINT organizations_name_not_blank CHECK (btrim(name) <> '' AND name = btrim(name)),
                ADD CONSTRAINT organizations_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
                ADD CONSTRAINT organizations_status CHECK (status IN ({$orgStatuses}))
        SQL);
        DB::statement(<<<SQL
            ALTER TABLE organization_memberships
                ADD CONSTRAINT organization_memberships_role CHECK (role IN ({$roles})),
                ADD CONSTRAINT organization_memberships_status CHECK (status IN ({$memberStatuses}))
        SQL);
        // At most one OWNER per organization (the trigger below makes it exactly one, owner_user_id).
        DB::statement("CREATE UNIQUE INDEX organization_memberships_one_owner ON organization_memberships (organization_id) WHERE role = 'OWNER'");
        DB::statement(<<<SQL
            ALTER TABLE organization_invitations
                ADD CONSTRAINT organization_invitations_role CHECK (role IN ({$assignable})),
                ADD CONSTRAINT organization_invitations_email CHECK (email = lower(btrim(email)) AND email ~ '^[^@\\s]+@[^@\\s]+\$'),
                ADD CONSTRAINT organization_invitations_token_hash CHECK (token_hash ~ '^[0-9a-f]{64}\$'),
                ADD CONSTRAINT organization_invitations_expiry CHECK (expires_at > created_at),
                ADD CONSTRAINT organization_invitations_final CHECK (accepted_at IS NULL OR revoked_at IS NULL),
                ADD CONSTRAINT organization_invitations_accepted_by CHECK ((accepted_at IS NULL) = (accepted_by_user_id IS NULL))
        SQL);
        // One open invitation per organization and email; a new one replaces it.
        DB::statement('CREATE UNIQUE INDEX organization_invitations_one_open ON organization_invitations (organization_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
        DB::statement(<<<SQL
            ALTER TABLE organization_audit_events
                ADD CONSTRAINT organization_audit_events_action CHECK (action IN ({$actions})),
                ADD CONSTRAINT organization_audit_events_target CHECK ((target_type IS NULL) = (target_id IS NULL)),
                ADD CONSTRAINT organization_audit_events_metadata CHECK (jsonb_typeof(metadata) = 'object' AND octet_length(metadata::text) <= 4096)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE organization_billing_accounts
                ADD CONSTRAINT organization_billing_accounts_seats CHECK (seat_limit BETWEEN 1 AND 100000),
                ADD CONSTRAINT organization_billing_accounts_version CHECK (entitlement_version ~ '^[0-9]+\.[0-9]+\.[0-9]+$')
        SQL);
        DB::statement("ALTER TABLE billing_organization_usage_counters ADD CONSTRAINT billing_organization_usage_counters_key CHECK (quota_key IN ({$quotas})),
            ADD CONSTRAINT billing_organization_usage_counters_used CHECK (used >= 0)");

        // Projects: personal slugs stay unique per owner; team slugs are unique per organization.
        DB::statement('ALTER TABLE projects DROP CONSTRAINT projects_user_id_slug_unique');
        DB::statement('CREATE UNIQUE INDEX projects_personal_slug_unique ON projects (user_id, slug) WHERE organization_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX projects_organization_slug_unique ON projects (organization_id, slug) WHERE organization_id IS NOT NULL');
        DB::statement('CREATE INDEX projects_organization_status_index ON projects (organization_id, status, created_at) WHERE organization_id IS NOT NULL');

        // The usage ledger has exactly one billing subject: a user or an organization.
        DB::statement('ALTER TABLE billing_usage_events ALTER COLUMN user_id DROP NOT NULL');
        DB::statement('ALTER TABLE billing_usage_events ADD CONSTRAINT billing_usage_events_subject CHECK ((user_id IS NULL) <> (organization_id IS NULL))');
        DB::statement('CREATE INDEX billing_usage_events_organization_index ON billing_usage_events (organization_id, created_at) WHERE organization_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION organization_refuse_change() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '%: records are append-only', TG_TABLE_NAME USING ERRCODE = 'check_violation';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER organization_audit_events_immutable BEFORE UPDATE ON organization_audit_events
                FOR EACH ROW EXECUTE FUNCTION organization_refuse_change();

            -- The owner invariant, checked at commit: an organization has
            -- exactly one OWNER membership, it is owner_user_id's and ACTIVE,
            -- and the organization has its billing account.
            CREATE OR REPLACE FUNCTION organization_check_owner(org_id char(26)) RETURNS void AS $$
            DECLARE
                owner_id char(26);
            BEGIN
                SELECT owner_user_id INTO owner_id FROM organizations WHERE id = org_id;
                IF NOT FOUND THEN
                    RETURN;
                END IF;
                IF NOT EXISTS (SELECT 1 FROM organization_memberships m WHERE m.organization_id = org_id AND m.user_id = owner_id
                        AND m.role = 'OWNER' AND m.status = 'ACTIVE')
                    OR EXISTS (SELECT 1 FROM organization_memberships m WHERE m.organization_id = org_id AND m.role = 'OWNER' AND m.user_id <> owner_id) THEN
                    RAISE EXCEPTION 'organizations: % must have exactly one ACTIVE OWNER membership, its owner', org_id USING ERRCODE = 'check_violation';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM organization_billing_accounts a WHERE a.organization_id = org_id) THEN
                    RAISE EXCEPTION 'organizations: % has no billing account', org_id USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION organizations_owner_trigger() RETURNS trigger AS $$
            BEGIN
                PERFORM organization_check_owner(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER organizations_owner_invariant AFTER INSERT OR UPDATE ON organizations
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION organizations_owner_trigger();

            CREATE OR REPLACE FUNCTION organization_memberships_owner_trigger() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    PERFORM organization_check_owner(OLD.organization_id);
                ELSE
                    PERFORM organization_check_owner(NEW.organization_id);
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER organization_memberships_owner_invariant AFTER INSERT OR UPDATE OR DELETE ON organization_memberships
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION organization_memberships_owner_trigger();

            CREATE OR REPLACE FUNCTION organization_billing_accounts_owner_trigger() RETURNS trigger AS $$
            BEGIN
                PERFORM organization_check_owner(OLD.organization_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER organization_billing_accounts_owner_invariant AFTER DELETE ON organization_billing_accounts
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION organization_billing_accounts_owner_trigger();

            -- Identity never changes: an organization keeps its owner and
            -- slug, a membership its organization and user.
            CREATE OR REPLACE FUNCTION organizations_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.owner_user_id IS DISTINCT FROM OLD.owner_user_id OR NEW.slug IS DISTINCT FROM OLD.slug THEN
                    RAISE EXCEPTION 'organizations: owner and slug are immutable' USING ERRCODE = 'check_violation';
                END IF;
                IF OLD.status = 'ARCHIVED' AND NEW.status IS DISTINCT FROM OLD.status THEN
                    RAISE EXCEPTION 'organizations: an archived organization stays archived' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER organizations_guarded BEFORE UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION organizations_guard();

            CREATE OR REPLACE FUNCTION organization_memberships_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                    RAISE EXCEPTION 'organization_memberships: organization and user are immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER organization_memberships_guarded BEFORE UPDATE ON organization_memberships FOR EACH ROW EXECUTE FUNCTION organization_memberships_guard();

            -- An invitation is decided once (accepted or revoked); what it
            -- grants and to whom never changes.
            CREATE OR REPLACE FUNCTION organization_invitations_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.accepted_at IS NOT NULL OR OLD.revoked_at IS NOT NULL
                    OR NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.email IS DISTINCT FROM OLD.email
                    OR NEW.role IS DISTINCT FROM OLD.role OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
                    OR NEW.invited_by_user_id IS DISTINCT FROM OLD.invited_by_user_id OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'organization_invitations: a decided invitation, and what it grants, never change' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER organization_invitations_guarded BEFORE UPDATE ON organization_invitations FOR EACH ROW EXECUTE FUNCTION organization_invitations_guard();

            -- A project is personal or an organization's from its creation, for good.
            CREATE OR REPLACE FUNCTION projects_scope_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.organization_id IS DISTINCT FROM OLD.organization_id OR NEW.user_id IS DISTINCT FROM OLD.user_id THEN
                    RAISE EXCEPTION 'projects: owner and organization are immutable' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER projects_scope_guarded BEFORE UPDATE ON projects FOR EACH ROW EXECUTE FUNCTION projects_scope_guard();
        SQL);
    }

    public function down(): void
    {
        // Rolling back would turn team projects into someone's personal
        // projects and drop team history: refused once organizations exist.
        if (Schema::hasTable('organizations') && DB::table('organizations')->exists()) {
            throw new RuntimeException('Organizations exist; this migration is rolled back only on an empty organization schema.');
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS projects_scope_guarded ON projects;
            DROP FUNCTION IF EXISTS projects_scope_guard();
            DROP INDEX IF EXISTS billing_usage_events_organization_index;
            ALTER TABLE billing_usage_events DROP CONSTRAINT IF EXISTS billing_usage_events_subject;
            DROP INDEX IF EXISTS projects_organization_status_index;
            DROP INDEX IF EXISTS projects_organization_slug_unique;
            DROP INDEX IF EXISTS projects_personal_slug_unique;
        SQL);
        Schema::table('billing_usage_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
        DB::statement('ALTER TABLE billing_usage_events ALTER COLUMN user_id SET NOT NULL');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_user_id_slug_unique UNIQUE (user_id, slug)');
        foreach (['billing_organization_usage_counters', 'organization_billing_accounts', 'organization_audit_events', 'organization_invitations',
            'organization_memberships', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS organization_refuse_change();
            DROP FUNCTION IF EXISTS organizations_owner_trigger();
            DROP FUNCTION IF EXISTS organization_memberships_owner_trigger();
            DROP FUNCTION IF EXISTS organization_billing_accounts_owner_trigger();
            DROP FUNCTION IF EXISTS organization_check_owner(char);
            DROP FUNCTION IF EXISTS organizations_guard();
            DROP FUNCTION IF EXISTS organization_memberships_guard();
            DROP FUNCTION IF EXISTS organization_invitations_guard();
        SQL);
    }
};
