/*
|------------------------------------------------------------------------------
| Application key
|------------------------------------------------------------------------------
|
| Laravel encrypts sessions and cookies with APP_KEY. config/app.php sets the
| cipher to AES-256-CBC, which Encrypter requires a 32-byte key for, and
| EncryptionServiceProvider strips a "base64:" prefix and base64-decodes the
| rest. So the value has to be exactly "base64:" followed by 32 base64-encoded
| random bytes.
|
| This secret lives in the dev state rather than the shared one, so it is
| destroyed with the environment. A new key is generated next session, which
| invalidates any session cookie issued before it. That is correct here: the
| database is destroyed at the same time, so there are no sessions to keep.
*/

resource "random_bytes" "app_key" {
  length = 32
}

resource "aws_secretsmanager_secret" "app_key" {
  name        = "workflowhub-${var.environment}-app-key"
  description = "Laravel APP_KEY for the WorkFlowHub ${var.environment} environment"

  # Skip the recovery window entirely. The default holds a deleted secret for
  # 30 days, during which its name cannot be reused — so the next session's
  # apply would fail on a name collision, and the secret would keep billing in
  # the meantime.
  recovery_window_in_days = 0

  tags = {
    Name = "workflowhub-${var.environment}-app-key"
  }
}

resource "aws_secretsmanager_secret_version" "app_key" {
  secret_id     = aws_secretsmanager_secret.app_key.id
  secret_string = "base64:${random_bytes.app_key.base64}"
}
