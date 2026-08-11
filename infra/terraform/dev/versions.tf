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

  # State is local for now. This environment is destroyed at the end of each
  # session, one person works on it, and the .tf files in git are the only thing
  # that needs to survive. A remote backend is a later, dedicated step; see
  # ADR-008 for why it is deferred rather than skipped.
}
