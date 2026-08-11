/*
|------------------------------------------------------------------------------
| Container platform
|------------------------------------------------------------------------------
|
| A Fargate cluster and two task definitions. No aws_ecs_service exists yet:
| tasks are launched one at a time with `aws ecs run-task`, inspected, and
| stopped. Turning the application into a long-running service behind a load
| balancer is a later step.
*/

# The repository lives in the shared state (ADR-009). Reading it through the AWS
# API rather than a terraform_remote_state data source keeps this configuration
# independent of how that state is laid out or where it is stored; the only
# coupling is the repository name, which is stable.
data "aws_ecr_repository" "app" {
  name = var.ecr_repository_name
}

resource "aws_cloudwatch_log_group" "app" {
  name = "/ecs/workflowhub-${var.environment}"

  # Logs are charged for ingestion and then for storage, at $0.0324 per GB-month
  # in eu-central-1. A short retention keeps a stray debug loop from quietly
  # accumulating; at this volume the cost is a rounding error either way.
  retention_in_days = var.log_retention_days

  tags = {
    Name = "workflowhub-${var.environment}"
  }
}

resource "aws_ecs_cluster" "main" {
  name = "workflowhub-${var.environment}"

  # Container Insights is billed per metric and is not useful on a cluster that
  # runs a handful of one-off tasks.
  setting {
    name  = "containerInsights"
    value = "disabled"
  }

  tags = {
    Name = "workflowhub-${var.environment}"
  }
}

locals {
  image = "${data.aws_ecr_repository.app.repository_url}:${var.image_tag}"

  # Values that are not secret. The database host, port, name, and user are all
  # discoverable by anyone who can read the task definition anyway, and treating
  # them as secrets would mean four more Secrets Manager entries for no gain.
  app_environment = [
    { name = "APP_ENV", value = "production" },
    { name = "APP_DEBUG", value = "false" },
    { name = "APP_URL", value = "http://localhost:8080" },

    # Laravel's default stack writes to storage/logs, which nothing collects in
    # a container. stderr sends it to the awslogs driver instead.
    { name = "LOG_CHANNEL", value = "stderr" },

    { name = "DB_CONNECTION", value = "pgsql" },
    { name = "DB_HOST", value = aws_db_instance.main.address },
    { name = "DB_PORT", value = tostring(aws_db_instance.main.port) },
    { name = "DB_DATABASE", value = aws_db_instance.main.db_name },
    { name = "DB_USERNAME", value = aws_db_instance.main.username },

    { name = "SESSION_DRIVER", value = "database" },
    { name = "CACHE_STORE", value = "database" },
    { name = "QUEUE_CONNECTION", value = "database" },

    # Must be false outside local development; the entrypoint does not enforce
    # this one, so it is set explicitly here (ADR-007 §7).
    { name = "AUTH_AUTO_VERIFY_NEW_USERS", value = "false" },
  ]

  # Injected by the ECS agent from Secrets Manager and exposed to the container
  # as ordinary environment variables. The values never appear in the task
  # definition, in Terraform state, or in the ECS console.
  #
  # The RDS-managed secret holds a JSON document, so the trailing ":password::"
  # selects that one key. The syntax is arn:json-key:version-stage:version-id,
  # with the last two left empty to take the current version.
  app_secrets = [
    {
      name      = "APP_KEY"
      valueFrom = aws_secretsmanager_secret.app_key.arn
    },
    {
      name      = "DB_PASSWORD"
      valueFrom = "${aws_db_instance.main.master_user_secret[0].secret_arn}:password::"
    },
  ]

  log_configuration = {
    logDriver = "awslogs"
    options = {
      "awslogs-group"         = aws_cloudwatch_log_group.app.name
      "awslogs-region"        = var.region
      "awslogs-stream-prefix" = "ecs"
    }
  }
}

# -----------------------------------------------------------------------------
# Application
# -----------------------------------------------------------------------------

resource "aws_ecs_task_definition" "app" {
  family = "workflowhub-${var.environment}-app"

  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.task_cpu
  memory                   = var.task_memory

  execution_role_arn = aws_iam_role.task_execution.arn
  task_role_arn      = aws_iam_role.task.arn

  # readonlyRootFilesystem is deliberately not set anywhere below. AWS documents
  # that ECS Exec is unsupported with a read-only root filesystem, because the
  # SSM agent needs to create directories inside the container.
  container_definitions = jsonencode([
    {
      name      = "app"
      image     = local.image
      essential = true

      portMappings = [
        {
          containerPort = var.app_port
          protocol      = "tcp"
        }
      ]

      environment = local.app_environment
      secrets     = local.app_secrets

      logConfiguration = local.log_configuration

      # ECS ignores the image's own HEALTHCHECK, so the same check is declared
      # here. Hitting /up through nginx exercises the whole request path rather
      # than just proving the process is alive.
      healthCheck = {
        command = [
          "CMD-SHELL",
          "php -r 'exit(@file_get_contents(\"http://127.0.0.1:${var.app_port}/up\") !== false ? 0 : 1);'"
        ]
        interval    = 30
        timeout     = 5
        retries     = 3
        startPeriod = 30
      }

      # Reaps the zombie processes an ECS Exec session can leave behind, as
      # recommended in the ECS Exec documentation.
      linuxParameters = {
        initProcessEnabled = true
      }
    }
  ])

  tags = {
    Name = "workflowhub-${var.environment}-app"
  }
}

# -----------------------------------------------------------------------------
# Service
# -----------------------------------------------------------------------------

# Replaces the manual `aws ecs run-task` of the previous step. The service keeps
# one task running, replaces it if it dies, and registers it with the load
# balancer's target group.
resource "aws_ecs_service" "app" {
  name            = "workflowhub-${var.environment}-app"
  cluster         = aws_ecs_cluster.main.id
  task_definition = aws_ecs_task_definition.app.arn
  launch_type     = "FARGATE"

  desired_count = var.app_desired_count

  # Not inherited from anything. run-task takes --enable-execute-command per
  # invocation; a service needs it declared, or exec is unavailable on exactly
  # the long-lived tasks where it is most useful.
  enable_execute_command = true

  network_configuration {
    subnets         = aws_subnet.public[*].id
    security_groups = [aws_security_group.app.id]

    # Mandatory here. Without a NAT gateway (ADR-008 §2) a task with no public
    # address cannot reach ECR, and fails to start with CannotPullContainerError.
    assign_public_ip = true
  }

  load_balancer {
    target_group_arn = aws_lb_target_group.app.arn
    container_name   = "app"
    container_port   = var.app_port
  }

  # How long the load balancer's health checks are ignored after a task starts.
  # Level 4b measured roughly 18 seconds from entrypoint to a proven 200, so
  # this is about five times the observed figure — enough slack for a cold image
  # pull, while still failing a genuinely broken deployment inside two minutes.
  health_check_grace_period_seconds = var.health_check_grace_period

  # The target group cannot accept registrations until it is attached to a
  # listener. Without this, service creation races the listener and fails.
  depends_on = [aws_lb_listener.http]

  tags = {
    Name = "workflowhub-${var.environment}-app"
  }
}

# -----------------------------------------------------------------------------
# Migrations
# -----------------------------------------------------------------------------

# Same image and same configuration, with the command replaced so the container
# runs migrations and exits instead of starting supervisord. The image's
# entrypoint still runs first, so configuration is cached and the APP_KEY guard
# still applies before artisan is reached.
#
# Deliberately a separate task definition rather than a command override at
# run-task time: the intent is recorded in the infrastructure rather than in
# whichever shell command someone happens to type.
resource "aws_ecs_task_definition" "migrate" {
  family = "workflowhub-${var.environment}-migrate"

  requires_compatibilities = ["FARGATE"]
  network_mode             = "awsvpc"
  cpu                      = var.task_cpu
  memory                   = var.task_memory

  execution_role_arn = aws_iam_role.task_execution.arn
  task_role_arn      = aws_iam_role.task.arn

  container_definitions = jsonencode([
    {
      name      = "migrate"
      essential = true
      image     = local.image

      command = ["php", "artisan", "migrate", "--force"]

      environment = local.app_environment
      secrets     = local.app_secrets

      logConfiguration = local.log_configuration
    }
  ])

  tags = {
    Name = "workflowhub-${var.environment}-migrate"
  }
}
