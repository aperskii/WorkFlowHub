# Version constraints for this configuration.
#
# Pinning both Terraform and the provider keeps a plan reproducible: without
# these, a future provider release could change what `terraform plan` produces
# from identical source.

terraform {
  required_version = ">= 1.15.0"

  required_providers {
    aws = {
      source = "hashicorp/aws"
      # ~> 6.58 accepts 6.58.x and later 6.x minors, but never 7.0, which would
      # be free to introduce breaking changes.
      version = "~> 6.58"
    }
  }

  # State is deliberately local for this exercise. A remote backend belongs with
  # the real infrastructure, not with a throwaway bucket, and configuring one
  # here would create the chicken-and-egg problem of needing a bucket to store
  # the state of the bucket.
}
