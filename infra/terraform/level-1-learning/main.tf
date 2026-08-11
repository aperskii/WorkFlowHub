provider "aws" {
  region = var.region
}

# A single S3 bucket, created to practise the init / plan / apply / destroy
# cycle. It holds no data and nothing depends on it.
resource "aws_s3_bucket" "learning" {
  bucket = var.bucket_name

  tags = {
    Project   = "WorkFlowHub"
    Purpose   = "terraform-learning"
    ManagedBy = "Terraform"
  }
}

# Refuse public access at the bucket level.
#
# All four settings are separate on purpose: the first two reject new public
# grants, and the second two neutralise any that already exist. Turning on only
# the first pair would leave an existing public policy in force.
resource "aws_s3_bucket_public_access_block" "learning" {
  bucket = aws_s3_bucket.learning.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

# Encrypt objects at rest with S3-managed keys.
#
# AES256 rather than KMS: a customer-managed KMS key carries a monthly charge
# plus per-request costs, and buys nothing here since there is no data to
# protect and no requirement to control the key.
#
# In provider v4 and later this is a separate resource rather than a block
# inside aws_s3_bucket, which is why the plan below shows three resources.
resource "aws_s3_bucket_server_side_encryption_configuration" "learning" {
  bucket = aws_s3_bucket.learning.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}
