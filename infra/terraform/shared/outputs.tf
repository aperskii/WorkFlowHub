# Consumed by the docker push step, and later by the ECS task definition.
#
# These cross a state boundary: infra/terraform/dev cannot reference them
# directly, so the image URL is passed in as a variable there rather than read
# from this state. See ADR-009.

output "ecr_repository_url" {
  description = "Repository URL, the docker push target and the ECS image reference."
  value       = aws_ecr_repository.app.repository_url
}

output "ecr_repository_name" {
  description = "Repository name, for aws ecr commands."
  value       = aws_ecr_repository.app.name
}

output "ecr_repository_arn" {
  description = "Repository ARN, for the IAM policy letting ECS pull from it."
  value       = aws_ecr_repository.app.arn
}

output "ecr_registry" {
  description = "Registry hostname, the target for docker login."
  value       = split("/", aws_ecr_repository.app.repository_url)[0]
}
