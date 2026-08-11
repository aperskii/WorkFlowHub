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

variable "retained_image_count" {
  description = <<-EOT
    Number of manifest entries the repository keeps before expiring the oldest.

    This counts manifests, not builds. Buildx pushes three entries per build —
    the tagged image, its manifest, and a provenance attestation — so retaining
    two real builds needs six.

    Two builds rather than one so the previous image survives long enough to
    roll back to. Layers are stored compressed, roughly 67 MB per build against
    the 311 MB on disk, so two builds occupy about 134 MB and stay inside the
    500 MB free allowance.
  EOT
  type        = number
  default     = 6

  validation {
    condition     = var.retained_image_count >= 1
    error_message = "At least one image must be retained, or every push would expire immediately."
  }
}
