<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateOrganization
{
    /**
     * The number of times the transaction is retried when a concurrent request
     * claims the generated slug first.
     */
    private const int MAX_ATTEMPTS = 5;

    /**
     * Create an organization and make the given user its first owner.
     *
     * A failed statement aborts the surrounding PostgreSQL transaction, so a
     * slug collision is retried by replaying the whole transaction rather than
     * by recovering inside it.
     */
    public function handle(User $creator, string $name): Organization
    {
        Validator::make(['name' => $name], [
            'name' => ['required', 'string', 'max:255'],
        ])->validate();

        $attempts = 0;

        while (true) {
            try {
                return DB::transaction(function () use ($creator, $name): Organization {
                    $organization = Organization::create([
                        'name' => $name,
                        'slug' => $this->uniqueSlug($name),
                    ]);

                    $organization->memberships()->create([
                        'user_id' => $creator->id,
                        'role' => OrganizationRole::Owner,
                    ]);

                    return $organization;
                });
            } catch (UniqueConstraintViolationException $exception) {
                if (++$attempts >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Build a normalized, lowercase slug that is not already taken.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'organization';
        }

        $slug = $base;
        $suffix = 1;

        while (Organization::where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
