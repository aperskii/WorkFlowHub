terraform {
  required_version = ">= 1.15.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.58"
    }
  }

  # This state describes resources that persist between sessions — the registry,
  # the OIDC provider, the deployment role — so losing the file would leave them
  # running with nothing tracking them. It lives in S3, versioned and encrypted.
  #
  # Locking is S3's own, not a DynamoDB table: use_lockfile writes a lock object
  # beside the state, which is one less resource to create and pay for.
  #
  # Every value here must be a literal. A backend is configured before Terraform
  # evaluates variables or data sources, so none can be referenced. The bucket is
  # created by infra/terraform/bootstrap.
  backend "s3" {
    bucket       = "workflowhub-tfstate-fc58eb3e"
    key          = "shared/terraform.tfstate"
    region       = "eu-central-1"
    encrypt      = true
    use_lockfile = true
  }
}
