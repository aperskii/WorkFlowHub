<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /**
     * Determine whether the user can list an organization's projects.
     *
     * Project visibility currently follows organization membership; there is no
     * per-project membership yet.
     */
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization) !== null;
    }

    /**
     * Determine whether the user can view the project.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->membershipFor($project->organization) !== null;
    }

    /**
     * Determine whether the user can create projects in the organization.
     */
    public function create(User $user, Organization $organization): bool
    {
        return $user->membershipFor($organization)?->role->canManageProjects() ?? false;
    }

    /**
     * Determine whether the user can update the project.
     */
    public function update(User $user, Project $project): bool
    {
        return $user->membershipFor($project->organization)?->role->canManageProjects() ?? false;
    }

    /**
     * Determine whether the user can change the project's status.
     */
    public function changeStatus(User $user, Project $project): bool
    {
        return $user->membershipFor($project->organization)?->role->canManageProjects() ?? false;
    }

    /**
     * Determine whether the user can archive the project.
     */
    public function archive(User $user, Project $project): bool
    {
        return $user->membershipFor($project->organization)?->role->canManageProjects() ?? false;
    }

    /**
     * Determine whether the user can run an AI risk analysis on the project.
     *
     * Stricter than `view` on purpose: every analysis is a billed call to an
     * external API, so the ability gates spend rather than access (ADR-011).
     */
    public function analyzeRisk(User $user, Project $project): bool
    {
        return $user->membershipFor($project->organization)?->role->canAnalyzeProjectRisk() ?? false;
    }
}
