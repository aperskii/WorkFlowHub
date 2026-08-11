# Consumed by the later ECS, RDS, and load balancer steps.

output "vpc_id" {
  description = "ID of the VPC."
  value       = aws_vpc.main.id
}

output "vpc_cidr" {
  description = "Address range of the VPC."
  value       = aws_vpc.main.cidr_block
}

output "availability_zones" {
  description = "Availability zones the subnets were spread across."
  value       = local.azs
}

output "public_subnet_ids" {
  description = "Public subnets, for the load balancer and the application tasks."
  value       = aws_subnet.public[*].id
}

output "isolated_subnet_ids" {
  description = "Isolated subnets with no internet route, for the database."
  value       = aws_subnet.isolated[*].id
}

output "alb_security_group_id" {
  description = "Security group for the load balancer."
  value       = aws_security_group.alb.id
}

output "app_security_group_id" {
  description = "Security group for the application tasks."
  value       = aws_security_group.app.id
}

output "db_security_group_id" {
  description = "Security group for the database."
  value       = aws_security_group.db.id
}

/*
|------------------------------------------------------------------------------
| Database
|------------------------------------------------------------------------------
*/

output "db_endpoint" {
  description = "Host and port of the database, in host:port form."
  value       = aws_db_instance.main.endpoint
}

output "db_address" {
  description = "Hostname of the database, without the port. Becomes DB_HOST."
  value       = aws_db_instance.main.address
}

output "db_port" {
  description = "Port the database listens on. Becomes DB_PORT."
  value       = aws_db_instance.main.port
}

output "db_name" {
  description = "Name of the initial database. Becomes DB_DATABASE."
  value       = aws_db_instance.main.db_name
}

output "db_username" {
  description = "Master username. Becomes DB_USERNAME."
  value       = aws_db_instance.main.username
}

/*
|------------------------------------------------------------------------------
| Container platform
|------------------------------------------------------------------------------
*/

output "ecs_cluster_name" {
  description = "Cluster name, for aws ecs run-task."
  value       = aws_ecs_cluster.main.name
}

output "app_task_definition" {
  description = "Family and revision of the application task definition."
  value       = aws_ecs_task_definition.app.arn
}

output "migrate_task_definition" {
  description = "Family and revision of the migration task definition."
  value       = aws_ecs_task_definition.migrate.arn
}

output "log_group_name" {
  description = "CloudWatch log group both task definitions write to."
  value       = aws_cloudwatch_log_group.app.name
}

output "alb_dns_name" {
  description = "Public DNS name of the load balancer. The application's address."
  value       = aws_lb.main.dns_name
}

output "app_url" {
  description = "Where the application is reachable. HTTP only until a domain and certificate exist."
  value       = "http://${aws_lb.main.dns_name}"
}

output "target_group_arn" {
  description = "Target group the service registers into, for describe-target-health."
  value       = aws_lb_target_group.app.arn
}

output "ecs_service_name" {
  description = "Name of the ECS service running the application."
  value       = aws_ecs_service.app.name
}

output "app_key_secret_arn" {
  description = "ARN of the Secrets Manager secret holding APP_KEY."
  value       = aws_secretsmanager_secret.app_key.arn
}

output "task_execution_role_arn" {
  description = "Role the ECS agent assumes to pull the image and read secrets."
  value       = aws_iam_role.task_execution.arn
}

output "task_role_arn" {
  description = "Role the container itself assumes. Carries only ECS Exec permissions."
  value       = aws_iam_role.task.arn
}

# The network configuration run-task needs, assembled here so the command line
# does not have to be built by hand from three separate outputs.
output "run_task_network_configuration" {
  description = "awsvpcConfiguration for aws ecs run-task."
  value = format(
    "awsvpcConfiguration={subnets=[%s],securityGroups=[%s],assignPublicIp=ENABLED}",
    join(",", aws_subnet.public[*].id),
    aws_security_group.app.id,
  )
}

output "db_master_secret_arn" {
  description = <<-EOT
    ARN of the Secrets Manager secret holding the generated master password.

    The password itself is never read here. A later step grants the application's
    task role permission to read this secret, and ECS injects the value at
    runtime, so the credential never passes through Terraform.
  EOT
  value       = aws_db_instance.main.master_user_secret[0].secret_arn
}
