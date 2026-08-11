variable "region" {
  description = "AWS region these resources live in. Matches infra/terraform/dev."
  type        = string
  default     = "eu-central-1"
}

variable "environment" {
  description = <<-EOT
    Environment tag applied to everything here.

    "shared" rather than "dev": these resources are not part of any one
    environment and are not destroyed with it.
  EOT
  type        = string
  default     = "shared"
}

variable "github_repository" {
  description = "The repository allowed to assume the deployment role, as owner/name."
  type        = string
  default     = "aperskii/WorkFlowHub"
}

variable "github_branch" {
  description = <<-EOT
    The only branch whose workflow runs may assume the deployment role.

    Scoped deliberately: the repository is public, so anyone can open a pull
    request, and a workflow running from an untrusted branch must not be able to
    reach this account.
  EOT
  type        = string
  default     = "main"
}

variable "github_subject_prefixes" {
  description = <<-EOT
    Subject claim prefixes GitHub may present, matched exactly.

    GitHub is migrating to an "immutable" subject format that embeds the numeric
    owner and repository IDs. This repository was created on 2026-08-10, after
    the 2026-07-15 cutoff, and its OIDC customization endpoint reports
    use_immutable_subject = false while advertising an immutable prefix — so
    which form arrives is genuinely ambiguous today.

    Both forms are listed rather than guessed. Each is an exact string naming
    this repository, so accepting both widens nothing: a run from any other
    repository matches neither.
  EOT
  type        = list(string)
  default = [
    "repo:aperskii/WorkFlowHub",
    "repo:aperskii@101721381/WorkFlowHub@1329846551",
  ]
}

variable "dev_resource_prefix" {
  description = "Name prefix of the resources the deployment role may manage."
  type        = string
  default     = "workflowhub-dev"
}

variable "retained_image_count" {
  description = <<-EOT
    Number of tagged builds the repository keeps.

    Counts tagged images, so this is a count of real builds rather than of
    manifests. Layers are stored compressed at roughly 67 MB per build and are
    shared between builds, so five stays well inside the 500 MB free allowance
    while leaving several versions to roll back to.
  EOT
  type        = number
  default     = 5

  validation {
    condition     = var.retained_image_count >= 1
    error_message = "At least one image must be retained, or every push would expire immediately."
  }
}

variable "untagged_image_retention_days" {
  description = <<-EOT
    How long an untagged image survives before being expired.

    Untagged images are leftovers: a manifest that :dev used to point at before
    the tag moved, or the provenance attestation a local buildx push creates
    alongside the image itself. Nothing references them and nothing can pull
    them, but they still occupy storage.

    One day rather than zero because expiring an untagged manifest that a tagged
    index still references would break that image. Images built by the
    deployment workflow are single manifests with no children, so this cannot
    arise for them; the delay is insurance against a hand-built push, which does
    produce children.
  EOT
  type        = number
  default     = 1

  validation {
    condition     = var.untagged_image_retention_days >= 1
    error_message = "Expiring untagged images immediately risks removing children of a manifest still in use."
  }
}
