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

resource "aws_ecr_lifecycle_policy" "app" {
  repository = aws_ecr_repository.app.name

  policy = jsonencode({
    rules = [
      {
        rulePriority = 1
        description  = "Retain only the ${var.retained_image_count} most recent images"
        selection = {
          tagStatus   = "any"
          countType   = "imageCountMoreThan"
          countNumber = var.retained_image_count
        }
        action = {
          type = "expire"
        }
      }
    ]
  })
}
