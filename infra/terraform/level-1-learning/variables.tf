variable "region" {
  description = "AWS region this configuration deploys into."
  type        = string
  default     = "eu-central-1"
}

variable "bucket_name" {
  description = <<-EOT
    Name of the learning bucket.

    S3 bucket names are globally unique across every AWS account, so this
    carries a random suffix. The name says what the bucket is for: it is a
    disposable exercise, not the project's Terraform state backend.
  EOT
  type        = string
  default     = "workflowhub-tf-learning-40cdc842"
}
