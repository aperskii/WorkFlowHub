/*
|------------------------------------------------------------------------------
| What the deployment role may do
|------------------------------------------------------------------------------
|
| Scoped to the actions this project's Terraform actually calls, rather than
| AdministratorAccess. The human operator holds admin; a role assumable from a
| public repository should not.
|
| Split across several policies because a managed policy is limited to 6,144
| characters, and because the groupings are easier to review than one wall of
| actions.
|
| Two deliberate limits are worth reading before the policies themselves:
|
|   * IAM permissions are confined to roles named workflowhub-dev-*. The role
|     cannot alter the OIDC provider, its own trust policy, or its own
|     permissions, so a workflow cannot grant itself more than this.
|   * Many EC2 and Elastic Load Balancing actions do not support resource-level
|     permissions — Describe* in particular is all-or-nothing — so those
|     statements use "*" and are constrained by the action list instead.
*/

# -----------------------------------------------------------------------------
# Networking
# -----------------------------------------------------------------------------

data "aws_iam_policy_document" "ci_network" {
  statement {
    sid    = "ManageVpcNetworking"
    effect = "Allow"

    # Create/Delete/Describe for every networking resource type in
    # infra/terraform/dev/network.tf and security-groups.tf. Describe* is
    # required by Terraform on every plan, not just on create.
    actions = [
      "ec2:CreateVpc",
      "ec2:DeleteVpc",
      "ec2:DescribeVpcs",
      "ec2:DescribeVpcAttribute",
      "ec2:ModifyVpcAttribute",

      "ec2:CreateSubnet",
      "ec2:DeleteSubnet",
      "ec2:DescribeSubnets",
      "ec2:ModifySubnetAttribute",

      "ec2:CreateRouteTable",
      "ec2:DeleteRouteTable",
      "ec2:DescribeRouteTables",
      "ec2:CreateRoute",
      "ec2:DeleteRoute",
      "ec2:AssociateRouteTable",
      "ec2:DisassociateRouteTable",

      "ec2:CreateInternetGateway",
      "ec2:DeleteInternetGateway",
      "ec2:AttachInternetGateway",
      "ec2:DetachInternetGateway",
      "ec2:DescribeInternetGateways",

      "ec2:CreateSecurityGroup",
      "ec2:DeleteSecurityGroup",
      "ec2:DescribeSecurityGroups",
      "ec2:DescribeSecurityGroupRules",
      "ec2:AuthorizeSecurityGroupIngress",
      "ec2:AuthorizeSecurityGroupEgress",
      "ec2:RevokeSecurityGroupIngress",
      "ec2:RevokeSecurityGroupEgress",
      "ec2:ModifySecurityGroupRules",

      # Tagging is a separate action from creation; without it every resource
      # with a tags block fails after being created.
      "ec2:CreateTags",
      "ec2:DeleteTags",
      "ec2:DescribeTags",

      # Read by the availability zone data source, and by Terraform when it
      # resolves the network interfaces Fargate attaches to a task.
      "ec2:DescribeAvailabilityZones",
      "ec2:DescribeNetworkInterfaces",
      "ec2:DescribeAccountAttributes",
    ]

    resources = ["*"]
  }
}

resource "aws_iam_policy" "ci_network" {
  name        = "workflowhub-ci-network"
  description = "VPC, subnets, routing, and security groups for the dev environment"
  policy      = data.aws_iam_policy_document.ci_network.json
}

resource "aws_iam_role_policy_attachment" "ci_network" {
  role       = aws_iam_role.github_actions.name
  policy_arn = aws_iam_policy.ci_network.arn
}

# -----------------------------------------------------------------------------
# Database, secrets, and logs
# -----------------------------------------------------------------------------

data "aws_iam_policy_document" "ci_data" {
  statement {
    sid    = "ManageDatabase"
    effect = "Allow"

    actions = [
      "rds:CreateDBInstance",
      "rds:DeleteDBInstance",
      "rds:ModifyDBInstance",
      "rds:DescribeDBInstances",
      "rds:CreateDBSubnetGroup",
      "rds:DeleteDBSubnetGroup",
      "rds:DescribeDBSubnetGroups",
      "rds:AddTagsToResource",
      "rds:RemoveTagsFromResource",
      "rds:ListTagsForResource",

      # Read by the aws_rds_engine_version data source, which resolves the
      # PostgreSQL version at plan time.
      "rds:DescribeDBEngineVersions",
      "rds:DescribeOrderableDBInstanceOptions",
    ]

    resources = ["*"]
  }

  statement {
    sid    = "ManageApplicationSecrets"
    effect = "Allow"

    actions = [
      "secretsmanager:CreateSecret",
      "secretsmanager:DeleteSecret",
      "secretsmanager:DescribeSecret",
      "secretsmanager:PutSecretValue",
      "secretsmanager:GetSecretValue",
      "secretsmanager:TagResource",
      "secretsmanager:UntagResource",
    ]

    # The APP_KEY secret this project creates, and the one RDS creates and owns
    # for the master password. Secrets Manager appends a random suffix to every
    # name, hence the trailing wildcard.
    resources = [
      "arn:aws:secretsmanager:${data.aws_region.current.region}:${local.account_id}:secret:${local.dev_prefix}-*",
      "arn:aws:secretsmanager:${data.aws_region.current.region}:${local.account_id}:secret:rds!db-*",
    ]
  }

  statement {
    sid       = "ListSecrets"
    effect    = "Allow"
    actions   = ["secretsmanager:ListSecrets"]
    resources = ["*"]
  }

  statement {
    sid    = "ManageTaskLogs"
    effect = "Allow"

    actions = [
      "logs:CreateLogGroup",
      "logs:DeleteLogGroup",
      "logs:DescribeLogGroups",
      "logs:PutRetentionPolicy",
      "logs:DeleteRetentionPolicy",
      "logs:TagResource",
      "logs:UntagResource",
      "logs:ListTagsForResource",

      # Read back, so a deployment can surface the migration task's output
      # rather than reporting only an exit code.
      "logs:DescribeLogStreams",
      "logs:GetLogEvents",
    ]

    resources = [
      "arn:aws:logs:${data.aws_region.current.region}:${local.account_id}:log-group:/ecs/${local.dev_prefix}",
      "arn:aws:logs:${data.aws_region.current.region}:${local.account_id}:log-group:/ecs/${local.dev_prefix}:*",
    ]
  }
}

resource "aws_iam_policy" "ci_data" {
  name        = "workflowhub-ci-data"
  description = "RDS, Secrets Manager, and CloudWatch Logs for the dev environment"
  policy      = data.aws_iam_policy_document.ci_data.json
}

resource "aws_iam_role_policy_attachment" "ci_data" {
  role       = aws_iam_role.github_actions.name
  policy_arn = aws_iam_policy.ci_data.arn
}

# -----------------------------------------------------------------------------
# Compute and load balancing
# -----------------------------------------------------------------------------

data "aws_iam_policy_document" "ci_compute" {
  statement {
    sid    = "ManageEcs"
    effect = "Allow"

    actions = [
      "ecs:CreateCluster",
      "ecs:DeleteCluster",
      "ecs:DescribeClusters",
      "ecs:RegisterTaskDefinition",
      "ecs:DeregisterTaskDefinition",
      "ecs:DescribeTaskDefinition",
      "ecs:ListTaskDefinitions",
      "ecs:CreateService",
      "ecs:UpdateService",
      "ecs:DeleteService",
      "ecs:DescribeServices",

      # The deployment steps themselves: run migrations as a one-off task, wait
      # for it, then scale the service up.
      "ecs:RunTask",
      "ecs:StopTask",
      "ecs:DescribeTasks",
      "ecs:ListTasks",

      "ecs:TagResource",
      "ecs:UntagResource",
      "ecs:ListTagsForResource",
    ]

    resources = ["*"]
  }

  statement {
    sid    = "ManageLoadBalancer"
    effect = "Allow"

    actions = [
      "elasticloadbalancing:CreateLoadBalancer",
      "elasticloadbalancing:DeleteLoadBalancer",
      "elasticloadbalancing:DescribeLoadBalancers",
      "elasticloadbalancing:DescribeLoadBalancerAttributes",
      "elasticloadbalancing:ModifyLoadBalancerAttributes",

      "elasticloadbalancing:CreateTargetGroup",
      "elasticloadbalancing:DeleteTargetGroup",
      "elasticloadbalancing:DescribeTargetGroups",
      "elasticloadbalancing:DescribeTargetGroupAttributes",
      "elasticloadbalancing:ModifyTargetGroup",
      "elasticloadbalancing:ModifyTargetGroupAttributes",
      "elasticloadbalancing:DescribeTargetHealth",

      "elasticloadbalancing:CreateListener",
      "elasticloadbalancing:DeleteListener",
      "elasticloadbalancing:DescribeListeners",
      "elasticloadbalancing:ModifyListener",

      "elasticloadbalancing:AddTags",
      "elasticloadbalancing:RemoveTags",
      "elasticloadbalancing:DescribeTags",
    ]

    resources = ["*"]
  }
}

resource "aws_iam_policy" "ci_compute" {
  name        = "workflowhub-ci-compute"
  description = "ECS and Elastic Load Balancing for the dev environment"
  policy      = data.aws_iam_policy_document.ci_compute.json
}

resource "aws_iam_role_policy_attachment" "ci_compute" {
  role       = aws_iam_role.github_actions.name
  policy_arn = aws_iam_policy.ci_compute.arn
}

# -----------------------------------------------------------------------------
# Registry and identity
# -----------------------------------------------------------------------------

data "aws_iam_policy_document" "ci_registry_identity" {
  # Exchanges the role's credentials for a registry login. This action has no
  # resource form; the repository-scoped statement below is what limits where an
  # image can actually be pushed.
  statement {
    sid       = "AuthenticateToRegistry"
    effect    = "Allow"
    actions   = ["ecr:GetAuthorizationToken"]
    resources = ["*"]
  }

  statement {
    sid    = "PushAndPullApplicationImage"
    effect = "Allow"

    actions = [
      "ecr:BatchCheckLayerAvailability",
      "ecr:InitiateLayerUpload",
      "ecr:UploadLayerPart",
      "ecr:CompleteLayerUpload",
      "ecr:PutImage",
      "ecr:BatchGetImage",
      "ecr:GetDownloadUrlForLayer",
      "ecr:DescribeImages",
      "ecr:DescribeRepositories",
      "ecr:ListImages",
    ]

    resources = [aws_ecr_repository.app.arn]
  }

  # Confined to the task roles the dev environment creates. The role cannot
  # touch its own definition, the policies attached to it, or the OIDC provider,
  # so a compromised workflow cannot widen its own access.
  statement {
    sid    = "ManageDevTaskRoles"
    effect = "Allow"

    actions = [
      "iam:CreateRole",
      "iam:DeleteRole",
      "iam:GetRole",
      "iam:TagRole",
      "iam:UntagRole",
      "iam:ListRoleTags",
      "iam:PutRolePolicy",
      "iam:DeleteRolePolicy",
      "iam:GetRolePolicy",
      "iam:ListRolePolicies",
      "iam:AttachRolePolicy",
      "iam:DetachRolePolicy",
      "iam:ListAttachedRolePolicies",
      "iam:ListInstanceProfilesForRole",
    ]

    resources = ["arn:aws:iam::${local.account_id}:role/${local.dev_prefix}-*"]
  }

  # Handing a role to ECS is a separate permission from creating it. Restricted
  # by service so the role cannot be passed to, say, an EC2 instance.
  statement {
    sid    = "PassTaskRolesToEcs"
    effect = "Allow"

    actions   = ["iam:PassRole"]
    resources = ["arn:aws:iam::${local.account_id}:role/${local.dev_prefix}-*"]

    condition {
      test     = "StringEquals"
      variable = "iam:PassedToService"
      values   = ["ecs-tasks.amazonaws.com"]
    }
  }

  statement {
    sid       = "ResolveOwnIdentity"
    effect    = "Allow"
    actions   = ["sts:GetCallerIdentity"]
    resources = ["*"]
  }

  # Enough to run Terraform against the dev environment: read and write its
  # state, and take the lock while doing so.
  #
  # Scoped to the dev/ prefix, deliberately. The shared state describes this
  # role and the policies attached to it, so write access there would let a
  # workflow rewrite its own permissions — the same escalation the IAM scoping
  # above exists to prevent. Applying the shared configuration stays a manual
  # step run by a human.
  statement {
    sid    = "ReadWriteDevState"
    effect = "Allow"

    actions = [
      "s3:GetObject",
      "s3:PutObject",
      "s3:DeleteObject",
    ]

    resources = ["${var.state_bucket_arn}/dev/*"]
  }

  # ListBucket is required for Terraform to determine whether a state object
  # exists at all. Constrained by prefix so it cannot enumerate the shared state.
  statement {
    sid       = "ListDevState"
    effect    = "Allow"
    actions   = ["s3:ListBucket"]
    resources = [var.state_bucket_arn]

    condition {
      test     = "StringLike"
      variable = "s3:prefix"
      values   = ["dev/*"]
    }
  }
}

resource "aws_iam_policy" "ci_registry_identity" {
  name        = "workflowhub-ci-registry-identity"
  description = "ECR image push and the dev environment's task roles"
  policy      = data.aws_iam_policy_document.ci_registry_identity.json
}

resource "aws_iam_role_policy_attachment" "ci_registry_identity" {
  role       = aws_iam_role.github_actions.name
  policy_arn = aws_iam_policy.ci_registry_identity.arn
}
