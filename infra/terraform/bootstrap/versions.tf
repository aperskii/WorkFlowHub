terraform {
  required_version = ">= 1.15.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.58"
    }
  }

  # Local state, deliberately and permanently.
  #
  # This configuration creates the bucket the other two states live in, so it
  # cannot store its own state there without depending on something it has not
  # created yet. Keeping it local breaks that circularity rather than working
  # around it.
  #
  # The risk that normally makes local state uncomfortable barely applies here:
  # this state describes one bucket, it changes almost never, and if the file
  # were lost the bucket can be adopted again with `terraform import`. Losing
  # the state does not lose the state store.
}
