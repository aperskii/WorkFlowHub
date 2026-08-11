output "bucket_name" {
  description = "Name of the created bucket."
  value       = aws_s3_bucket.learning.bucket
}

output "bucket_arn" {
  description = "ARN of the created bucket, the identifier IAM policies refer to."
  value       = aws_s3_bucket.learning.arn
}

output "bucket_region" {
  description = "Region the bucket was created in, to confirm it matches var.region."
  value       = aws_s3_bucket.learning.region
}
