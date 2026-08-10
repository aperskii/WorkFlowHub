<?php

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateProject
{
    /**
     * The number of times the insert is retried when a concurrent request claims
     * the generated slug first.
     */
    private const int MAX_ATTEMPTS = 5;

    /**
     * Create a project owned by the given organization.
     *
     * Slugs are unique per organization, so a collision is resolved by replaying
     * the insert with a freshly generated candidate rather than by recovering
     * from the aborted statement.
     */
    public function handle(
        Organization $organization,
        string $name,
        ?string $description = null,
        ProjectStatus $status = ProjectStatus::Planning,
    ): Project {
        Validator::make(['name' => $name, 'description' => $description], [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        $attempts = 0;

        while (true) {
            try {
                return $organization->projects()->create([
                    'name' => $name,
                    'slug' => $this->uniqueSlug($organization, $name),
                    'description' => $description,
                    'status' => $status,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if (++$attempts >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /**
     * Build a normalized, lowercase slug that is not already taken within the
     * given organization.
     */
    private function uniqueSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'project';
        }

        $slug = $base;
        $suffix = 1;

        while ($organization->projects()->where('slug', $slug)->exists()) {
            $suffix++;
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
