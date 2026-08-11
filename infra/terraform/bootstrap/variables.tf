variable "region" {
  description = "AWS region the state bucket lives in. Matches the other configurations."
  type        = string
  default     = "eu-central-1"
}

variable "state_bucket_name" {
  description = <<-EOT
    Name of the bucket holding Terraform state.

    S3 bucket names are globally unique, hence the random suffix. Naming it
    after the AWS account would be the other convention, but a backend block
    cannot use variables or data sources — the bucket name has to be a literal
    in every configuration that points at it — and that would publish the
    account ID in a public repository.
  EOT
  type        = string
  default     = "workflowhub-tfstate-fc58eb3e"
}

variable "state_version_retention_days" {
  description = <<-EOT
    How long superseded state versions are kept.

    Long enough to recover from a bad apply, short enough that versions do not
    accumulate indefinitely. State files are kilobytes, so this is about tidiness
    rather than cost.
  EOT
  type        = number
  default     = 90
}
