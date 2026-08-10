<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use App\Enums\OrganizationRole;
use App\Policies\InvitationPolicy;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $organization_id
 * @property string $email
 * @property OrganizationRole $role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property int|null $invited_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Organization $organization
 * @property-read User|null $invitedBy
 */
#[Fillable([
    'email',
    'role',
    'token_hash',
    'expires_at',
    'accepted_at',
    'revoked_at',
    'invited_by_user_id',
])]
#[UsePolicy(InvitationPolicy::class)]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Generate a cryptographically secure raw invitation token.
     *
     * The raw value is returned to the caller once so it can be placed in the
     * invitation URL. Only its hash is ever persisted.
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * Hash a raw invitation token for storage and lookup.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Normalize an email address so invitations are matched case-insensitively.
     */
    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Get the organization this invitation grants access to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Get the user who sent this invitation, if they still exist.
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Derive the invitation's state from its timestamps.
     *
     * The state is never stored, so it cannot drift out of sync with the record.
     */
    public function status(): InvitationStatus
    {
        if ($this->accepted_at !== null) {
            return InvitationStatus::Accepted;
        }

        if ($this->revoked_at !== null) {
            return InvitationStatus::Revoked;
        }

        if ($this->expires_at->isPast()) {
            return InvitationStatus::Expired;
        }

        return InvitationStatus::Pending;
    }

    /**
     * Determine whether this invitation may still be accepted.
     */
    public function isPending(): bool
    {
        return $this->status()->isAcceptable();
    }

    /**
     * Determine whether the given email address owns this invitation.
     */
    public function isFor(string $email): bool
    {
        return $this->email === self::normalizeEmail($email);
    }

    /**
     * Scope the query to invitations that have not been accepted or revoked.
     *
     * Expired invitations are included: they remain visible so they can be
     * resent from the members page.
     *
     * @param  Builder<Invitation>  $query
     */
    #[Scope]
    protected function outstanding(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at');
    }
}
