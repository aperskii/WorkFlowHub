terraform {
  required_version = ">= 1.15.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.58"
    }

    # Generates the application key. random_bytes produces cryptographically
    # secure bytes and keeps the value in state rather than regenerating it on
    # every plan, which would invalidate every existing session.
    random = {
      source  = "hashicorp/random"
      version = "~> 3.9"
    }
  }

  # Remote, so that something other than one laptop can run Terraform against
  # this environment. That is the reason it moved: the state, not the
  # permissions, was what stopped the deployment workflow provisioning anything.
  #
  # Kept under a separate key from the shared state. The two remain independent
  # configurations for the reasons in ADR-009; sharing a bucket is storage, not
  # a shared lifecycle.
  backend "s3" {
    bucket       = "workflowhub-tfstate-fc58eb3e"
    key          = "dev/terraform.tfstate"
    region       = "eu-central-1"
    encrypt      = true
    use_lockfile = true
  }
}
