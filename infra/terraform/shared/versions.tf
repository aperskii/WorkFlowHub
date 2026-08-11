terraform {
  required_version = ">= 1.15.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.58"
    }
  }

  # Local state, as in infra/terraform/dev. This state is deliberately separate
  # from that one: the resources here outlive a working session, and the dev
  # environment is destroyed at the end of each. See ADR-009.
}
