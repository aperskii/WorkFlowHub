output "state_bucket_name" {
  description = <<-EOT
    Name of the state bucket.

    Copy this into the backend block of each configuration that stores state
    here. It cannot be wired up automatically: a backend block is read before
    Terraform evaluates anything, so it accepts only literal values.
  EOT
  value       = aws_s3_bucket.state.bucket
}

output "state_bucket_arn" {
  description = "Bucket ARN, for the IAM policy granting the deployment role access to the dev state."
  value       = aws_s3_bucket.state.arn
}

output "backend_configuration" {
  description = "The backend block to add to a configuration that should store its state here."
  value       = <<-EOT
    terraform {
      backend "s3" {
        bucket       = "${aws_s3_bucket.state.bucket}"
        key          = "<shared|dev>/terraform.tfstate"
        region       = "${var.region}"
        encrypt      = true
        use_lockfile = true
      }
    }
  EOT
}
