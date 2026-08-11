provider "aws" {
  region = var.region

  default_tags {
    tags = {
      Project     = "WorkFlowHub"
      Environment = "shared"
      ManagedBy   = "Terraform"
    }
  }
}

/*
|------------------------------------------------------------------------------
| Terraform state bucket
|------------------------------------------------------------------------------
|
| Holds the state for infra/terraform/shared and infra/terraform/dev, under
| separate keys. Costs a few pence a year: state files are kilobytes, and no
| DynamoDB table is involved because locking is handled by S3 itself.
|
| Why this exists at all. Local state has been adequate while one person applied
| everything from one machine, but two things have changed. The shared state now
| describes real resources that persist between sessions, so losing the file
| would leave a repository, an OIDC provider and four policies that Terraform no
| longer knows about. And a GitHub Actions runner has no state, which is the
| single reason the deployment workflow cannot run Terraform and the environment
| still has to be created by hand.
|
| ADR-009 §5 named the ordering problem this solves and deferred it. This is the
| step it deferred to.
*/

resource "aws_s3_bucket" "state" {
  bucket = var.state_bucket_name

  # Deleting this bucket would orphan every resource both other states describe,
  # leaving them running with nothing tracking them.
  #
  # This is the same guard argued against for the container registry in ADR-009
  # §3, and the reasoning differs rather than reverses: there, a destroy that
  # failed part-way could abort before removing the database and leave it
  # billing. Nothing here is destroyed as part of a session, so that failure
  # mode does not exist, and the thing being protected is the record of
  # everything else.
  lifecycle {
    prevent_destroy = true
  }

  tags = {
    Name = var.state_bucket_name
  }
}

# Every apply overwrites the state file. Without versioning, a corrupted or
# truncated write is unrecoverable; with it, the previous version is one console
# click away.
resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id

  versioning_configuration {
    status = "Enabled"
  }
}

# State records every attribute of every resource in plain text, including
# generated passwords. Encryption at rest with the S3-managed key is free.
resource "aws_s3_bucket_server_side_encryption_configuration" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "state" {
  bucket = aws_s3_bucket.state.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# Old state versions accumulate one per apply and are never read after the next
# few. Kept long enough to recover from a bad write, then removed.
resource "aws_s3_bucket_lifecycle_configuration" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    id     = "expire-old-state-versions"
    status = "Enabled"

    filter {}

    noncurrent_version_expiration {
      noncurrent_days = var.state_version_retention_days
    }

    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }

  depends_on = [aws_s3_bucket_versioning.state]
}
