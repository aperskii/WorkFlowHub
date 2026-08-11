/*
|------------------------------------------------------------------------------
| Task roles
|------------------------------------------------------------------------------
|
| Two roles with different audiences, which is the distinction worth keeping
| straight:
|
|   execution role - assumed by the ECS agent, before the container starts. It
|                    pulls the image, reads the secrets, and creates log
|                    streams. The application never sees these permissions.
|   task role      - assumed by the container itself. Anything the application
|                    code calls AWS for uses this.
|
| The application calls no AWS service today, so the task role carries only what
| ECS Exec needs.
*/

data "aws_caller_identity" "current" {}

# Both roles are assumed by ECS on the task's behalf. The SourceArn and
# SourceAccount conditions are AWS's documented guard against the confused
# deputy problem: they stop this role being assumed on behalf of some other
# account's ECS tasks.
data "aws_iam_policy_document" "ecs_task_assume_role" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRole"]

    principals {
      type        = "Service"
      identifiers = ["ecs-tasks.amazonaws.com"]
    }

    condition {
      test     = "ArnLike"
      variable = "aws:SourceArn"
      values   = ["arn:aws:ecs:${var.region}:${data.aws_caller_identity.current.account_id}:*"]
    }

    condition {
      test     = "StringEquals"
      variable = "aws:SourceAccount"
      values   = [data.aws_caller_identity.current.account_id]
    }
  }
}

# -----------------------------------------------------------------------------
# Execution role
# -----------------------------------------------------------------------------

resource "aws_iam_role" "task_execution" {
  name               = "workflowhub-${var.environment}-task-execution"
  description        = "Pulls the image, reads secrets, and writes logs on the task's behalf"
  assume_role_policy = data.aws_iam_policy_document.ecs_task_assume_role.json

  tags = {
    Name = "workflowhub-${var.environment}-task-execution"
  }
}

# Grants the ECR pull and CloudWatch Logs writes every Fargate task needs.
# Notably it does not grant secretsmanager access, which is why the policy below
# exists separately.
resource "aws_iam_role_policy_attachment" "task_execution_managed" {
  role       = aws_iam_role.task_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

# Exactly two secrets, named by ARN. Not a wildcard: this role is what turns a
# leaked task definition into read access on whatever it can reach, so the blast
# radius is worth bounding to the two values this application actually needs.
data "aws_iam_policy_document" "task_execution_secrets" {
  statement {
    sid    = "ReadTaskSecrets"
    effect = "Allow"

    actions = ["secretsmanager:GetSecretValue"]

    resources = [
      aws_secretsmanager_secret.app_key.arn,
      aws_db_instance.main.master_user_secret[0].secret_arn,
    ]
  }
}

resource "aws_iam_role_policy" "task_execution_secrets" {
  name   = "read-task-secrets"
  role   = aws_iam_role.task_execution.id
  policy = data.aws_iam_policy_document.task_execution_secrets.json
}

# -----------------------------------------------------------------------------
# Task role
# -----------------------------------------------------------------------------

resource "aws_iam_role" "task" {
  name               = "workflowhub-${var.environment}-task"
  description        = "Assumed by the application container itself"
  assume_role_policy = data.aws_iam_policy_document.ecs_task_assume_role.json

  tags = {
    Name = "workflowhub-${var.environment}-task"
  }
}

# The four actions AWS documents for ECS Exec, verbatim from the "ECS Exec
# permissions" section of the task IAM role guide. They let the SSM agent that
# ECS bind-mounts into the container open its channels back to Systems Manager.
#
# Resource is "*" because that is what AWS specifies; these actions do not
# support resource-level permissions. Access is instead constrained at the
# caller's end, by who may invoke ecs:ExecuteCommand.
data "aws_iam_policy_document" "task_exec_ssm" {
  statement {
    sid    = "AllowECSExec"
    effect = "Allow"

    actions = [
      "ssmmessages:CreateControlChannel",
      "ssmmessages:CreateDataChannel",
      "ssmmessages:OpenControlChannel",
      "ssmmessages:OpenDataChannel",
    ]

    resources = ["*"]
  }
}

resource "aws_iam_role_policy" "task_exec_ssm" {
  name   = "ecs-exec"
  role   = aws_iam_role.task.id
  policy = data.aws_iam_policy_document.task_exec_ssm.json
}
