/*
|------------------------------------------------------------------------------
| PostgreSQL
|------------------------------------------------------------------------------
|
| The first resource in this project that costs money if left running. Every
| setting below that carries a charge is chosen for the Free Tier allowance or
| switched off; see ADR-008 for the surrounding network decisions.
*/

# Resolved from the RDS API at plan time rather than hardcoded, so a version
# that does not exist fails during plan instead of part-way through an apply.
# Pinned to major 18 to match the postgres:18 image compose.yaml runs locally.
data "aws_rds_engine_version" "postgres" {
  engine  = "postgres"
  version = "18"
  latest  = true
}

# Tells RDS which subnets it may place the instance in. Both are isolated
# subnets with no route to the internet gateway, so the database has no path off
# the VPC regardless of any other setting.
#
# Two subnets in two availability zones are required even for a Single-AZ
# instance: RDS insists on somewhere to fail over to before it will accept the
# configuration, even when failover is disabled.
resource "aws_db_subnet_group" "main" {
  name        = "workflowhub-${var.environment}"
  description = "Isolated subnets for the WorkFlowHub database"
  subnet_ids  = aws_subnet.isolated[*].id

  tags = {
    Name = "workflowhub-${var.environment}-db-subnets"
  }
}

resource "aws_db_instance" "main" {
  identifier = "workflowhub-${var.environment}"

  engine         = "postgres"
  engine_version = data.aws_rds_engine_version.postgres.version_actual
  instance_class = var.db_instance_class

  db_name  = "workflowhub"
  username = "workflowhub_admin"

  # No password appears anywhere in this configuration, in state, or in any
  # variable. RDS generates one, stores it in Secrets Manager, and owns its
  # lifecycle. The secret's ARN is exposed as an output so a later step can
  # grant the application permission to read it.
  manage_master_user_password = true

  allocated_storage = var.db_allocated_storage
  storage_type      = "gp2"

  # Encryption at rest using the AWS-managed key for RDS. The managed key itself
  # is free; only a customer-managed key would carry a monthly charge.
  storage_encrypted = true

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.db.id]

  # No public address. Combined with the isolated subnets, the instance is
  # reachable only from inside the VPC, and only from the application tier's
  # security group on 5432.
  publicly_accessible = false
  multi_az            = false

  # Automated backups off. This environment is destroyed at the end of each
  # session, so there is nothing worth retaining, and backup storage beyond the
  # free allowance is charged.
  backup_retention_period = 0

  # Both are required for the destroy-every-session workflow. A final snapshot
  # would outlive the instance and be billed; deletion protection would make
  # `terraform destroy` fail rather than proceed.
  skip_final_snapshot = true
  deletion_protection = false

  # Explicitly off: both are chargeable beyond their free allowances and neither
  # is useful on a database that exists for a few hours at a time.
  performance_insights_enabled = false
  monitoring_interval          = 0

  auto_minor_version_upgrade = true

  tags = {
    Name = "workflowhub-${var.environment}-db"
  }
}
