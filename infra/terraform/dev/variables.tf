variable "region" {
  description = "AWS region this environment deploys into."
  type        = string
  default     = "eu-central-1"
}

variable "environment" {
  description = "Environment name, used in tags and resource names."
  type        = string
  default     = "dev"
}

variable "vpc_cidr" {
  description = <<-EOT
    Address range for the VPC.

    A /16 gives 65,536 addresses, far more than this application needs, but the
    range is private and costs nothing, so sizing down only creates the risk of
    running out later. 10.0.0.0/16 avoids the 172.17.0.0/16 range Docker uses by
    default, which would otherwise conflict on any host running both.
  EOT
  type        = string
  default     = "10.0.0.0/16"
}

variable "az_count" {
  description = <<-EOT
    Number of availability zones to spread subnets across.

    Two is the minimum: an Application Load Balancer requires subnets in at
    least two zones before it will accept a configuration.
  EOT
  type        = number
  default     = 2

  validation {
    condition     = var.az_count >= 2
    error_message = "At least two availability zones are required by the load balancer."
  }
}

variable "app_port" {
  description = <<-EOT
    Port the application container listens on, and the only port the load
    balancer may reach it over.

    Plain HTTP: TLS is terminated at the load balancer, so traffic inside the
    VPC is unencrypted between the two. Matches the port nginx binds in
    docker/app/nginx.conf.
  EOT
  type        = number
  default     = 8080
}

variable "postgres_port" {
  description = "Port the database listens on."
  type        = number
  default     = 5432
}

variable "db_instance_class" {
  description = <<-EOT
    Database instance size.

    db.t4g.micro is named in the RDS Free Tier terms alongside db.t3.micro and
    db.t2.micro, and is the cheapest of them if the free allowance is ever
    exhausted: $0.019/hour against $0.021 for db.t3.micro in eu-central-1.
  EOT
  type        = string
  default     = "db.t4g.micro"
}

variable "ecr_repository_name" {
  description = <<-EOT
    Name of the ECR repository holding the application image.

    The repository is managed in infra/terraform/shared and looked up here
    through the AWS API, so only its name crosses the state boundary (ADR-009).
  EOT
  type        = string
  default     = "workflowhub-app"
}

variable "image_tag" {
  description = "Tag of the image to run. Matches what Level 4a pushed."
  type        = string
  default     = "dev"
}

variable "task_cpu" {
  description = <<-EOT
    Fargate CPU units for each task. 256 is a quarter of a vCPU and the
    smallest size Fargate offers.
  EOT
  type        = string
  default     = "256"
}

variable "task_memory" {
  description = <<-EOT
    Fargate memory in MB. 512 is the smallest amount permitted alongside 256
    CPU units, and comfortably above the 256M memory_limit set in the image's
    php.ini.
  EOT
  type        = string
  default     = "512"
}

variable "app_desired_count" {
  description = <<-EOT
    Number of application tasks the service keeps running.

    One is enough to prove the path works. Two would remove the brief gap during
    a deployment, at twice the Fargate cost, which is not worth paying in an
    environment with no users.
  EOT
  type        = number
  default     = 1
}

variable "health_check_grace_period" {
  description = <<-EOT
    Seconds before the load balancer's health checks count against a new task.

    Level 4b measured roughly 18 seconds from entrypoint start to a proven 200
    response, so this is about five times the observed boot time.
  EOT
  type        = number
  default     = 90
}

variable "deregistration_delay" {
  description = <<-EOT
    Seconds the target group waits before dropping a deregistering task.

    The 300 second default exists to let in-flight requests finish. There is no
    real traffic here and the environment is destroyed every session, so the
    default would add five minutes to every teardown for no benefit.
  EOT
  type        = number
  default     = 30
}

variable "log_retention_days" {
  description = "How long CloudWatch keeps task logs. Short, for a disposable environment."
  type        = number
  default     = 7
}

variable "db_allocated_storage" {
  description = <<-EOT
    Storage in GB.

    20 GB is the RDS Free Tier allowance for General Purpose SSD, and also the
    minimum RDS accepts for this engine, so it is both the cheapest and the
    smallest possible choice.
  EOT
  type        = number
  default     = 20

  validation {
    condition     = var.db_allocated_storage <= 20
    error_message = "Above 20 GB the storage is billed rather than covered by the Free Tier allowance."
  }
}
