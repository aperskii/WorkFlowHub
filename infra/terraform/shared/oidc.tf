/*
|------------------------------------------------------------------------------
| GitHub Actions federation
|------------------------------------------------------------------------------
|
| Lets a workflow run in this repository obtain short-lived AWS credentials by
| exchanging a signed OIDC token, instead of storing a long-lived access key in
| a repository secret. Nothing here costs money.
|
| The distinction that matters on a public repository: an access key is a secret
| that works from anywhere for anyone holding it, whereas this role can only be
| assumed by a token GitHub itself signed, asserting a workflow in this
| repository on this branch. A leaked token expires in minutes.
*/

data "aws_caller_identity" "current" {}

data "aws_region" "current" {}

# No thumbprint_list. AWS verifies the JWKS endpoint's TLS certificate against
# its own library of trusted certificate authorities and only falls back to
# thumbprints for providers signed by an untrusted CA, which GitHub is not.
# Supplying one is optional, and a hardcoded thumbprint is a value that silently
# goes stale when GitHub rotates its certificate.
resource "aws_iam_openid_connect_provider" "github" {
  url = "https://token.actions.githubusercontent.com"

  client_id_list = ["sts.amazonaws.com"]

  tags = {
    Name = "github-actions"
  }
}

locals {
  # The environment form of the subject claim, not the branch form.
  #
  # GitHub sends "repo:owner/name:ref:refs/heads/BRANCH" for an ordinary job,
  # but a job that declares `environment:` gets
  # "repo:owner/name:environment:NAME" instead. Both deployment workflows
  # declare one, so the branch form would never match and the branch form is
  # not trusted.
  #
  # That is deliberate rather than incidental. Trusting only the environment
  # subject means the role can only be assumed by a run that has already passed
  # the environment's required-reviewer gate: a workflow added later that
  # reaches for AWS without that gate simply cannot authenticate. The approval
  # becomes load-bearing instead of advisory.
  #
  # The branch is still constrained — the environment permits deployments from
  # main only — so scoping is not lost by dropping the ref form.
  github_subjects = [
    for prefix in var.github_subject_prefixes :
    "${prefix}:environment:${var.github_environment}"
  ]

  dev_prefix = var.dev_resource_prefix
  account_id = data.aws_caller_identity.current.account_id
}

data "aws_iam_policy_document" "github_assume_role" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [aws_iam_openid_connect_provider.github.arn]
    }

    # Both conditions are required. Without the audience check the role would
    # accept tokens minted for a different relying party.
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:aud"
      values   = ["sts.amazonaws.com"]
    }

    # StringEquals, not StringLike. A wildcard here is the classic mistake:
    # "repo:aperskii/*" would trust every repository the account owns, and
    # "repo:*" would trust all of GitHub.
    condition {
      test     = "StringEquals"
      variable = "token.actions.githubusercontent.com:sub"
      values   = local.github_subjects
    }
  }
}

resource "aws_iam_role" "github_actions" {
  name        = "workflowhub-github-actions"
  description = "Assumed by GitHub Actions workflows in ${var.github_repository} on ${var.github_branch}"

  assume_role_policy = data.aws_iam_policy_document.github_assume_role.json

  # An upper bound on how long credentials from a single assume-role call stay
  # valid. A deployment takes minutes, not hours.
  max_session_duration = 3600

  tags = {
    Name = "workflowhub-github-actions"
  }
}

output "github_actions_role_arn" {
  description = "Role ARN for the workflow's role-to-assume input."
  value       = aws_iam_role.github_actions.arn
}

output "github_actions_subjects" {
  description = "The exact OIDC subject claims this role accepts."
  value       = local.github_subjects
}
