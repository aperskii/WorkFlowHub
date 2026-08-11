provider "aws" {
  region = var.region

  default_tags {
    tags = {
      Project     = "WorkFlowHub"
      Environment = var.environment
      ManagedBy   = "Terraform"
    }
  }
}

/*
|------------------------------------------------------------------------------
| Container registry
|------------------------------------------------------------------------------
|
| Private repository holding the image docker/app/Dockerfile builds. The ECS
| service in a later step pulls from here.
|
| This lives in its own state rather than alongside the VPC and database
| because it outlives them: the dev environment is destroyed at the end of each
| session, and `terraform destroy` acts on a whole state with no clean way to
| exclude one resource. See ADR-009.
|
| An empty repository costs nothing. Only stored image data is charged, at
| $0.10 per GB-month in eu-central-1 against a 500 MB free allowance, and the
| lifecycle policy below bounds it.
*/

resource "aws_ecr_repository" "app" {
  name = "workflowhub-app"

  # MUTABLE so a moving tag such as `dev` can be repointed at a new build.
  # Worth revisiting alongside a real tagging strategy: immutable tags plus a
  # git SHA make it impossible to confuse two builds that share a name.
  image_tag_mutability = "MUTABLE"

  # Basic scanning is free and reports known CVEs in the image's OS packages on
  # every push. Enhanced scanning, which is chargeable, is not enabled.
  image_scanning_configuration {
    scan_on_push = true
  }

  # Permits `terraform destroy` to remove a repository that still holds images.
  # Kept even now that the state is separate: without it, a destroy of this
  # state fails rather than completing, and a half-applied destroy is worse to
  # reason about than a rebuilt image.
  force_delete = true

  tags = {
    Name = "workflowhub-app"
  }
}

# Two rules, because the repository accumulates two different kinds of thing and
# a single count-based rule handles neither well.
#
# A count over all images was the original approach and it measured the wrong
# quantity. Every push leaves the manifest :dev used to point at behind as an
# untagged image, and a local buildx push adds a provenance attestation as well,
# so "keep the 6 most recent images" was mostly retaining debris rather than
# builds. It also meant the number had to be guessed differently depending on
# whether a human or the workflow had pushed.
#
# Splitting them makes the retained count mean builds, and lets the leftovers be
# cleaned up on their own schedule.
resource "aws_ecr_lifecycle_policy" "app" {
  repository = aws_ecr_repository.app.name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Expire untagged leftovers after ${var.untagged_image_retention_days} day(s)"
        selection = {
          tagStatus   = "untagged"
          countType   = "sinceImagePushed"
          countUnit   = "days"
          countNumber = var.untagged_image_retention_days
        }
        action = {
          type = "expire"
        }
      },
      {
        # tagPatternList is required when tagStatus is "tagged"; "*" matches the
        # moving :dev tag and the commit tags the workflow pushes alike.
        rulePriority = 2
        description  = "Retain the ${var.retained_image_count} most recent tagged builds"
        selection = {
          tagStatus      = "tagged"
          tagPatternList = ["*"]
          countType      = "imageCountMoreThan"
          countNumber    = var.retained_image_count
        }
        action = {
          type = "expire"
        }
      }
    ]
  })
}
