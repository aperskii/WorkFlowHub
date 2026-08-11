/*
|------------------------------------------------------------------------------
| Security groups
|------------------------------------------------------------------------------
|
| Three groups forming a chain: the internet may reach the load balancer, the
| load balancer may reach the application, and the application may reach the
| database. Nothing may skip a link.
|
| The app and db groups allow traffic from a *security group*, not a CIDR range.
| That is what makes this hold as the environment changes: whichever addresses a
| task happens to receive, it is authorised by membership of the group rather
| than by its IP. With the app tier sitting in public subnets, this is the
| control doing the work, so it is written to be checkable rather than clever.
|
| Rules are separate resources rather than inline blocks. Inline rules are
| replaced wholesale on every change, and two groups referencing each other
| inline cannot be expressed at all.
*/

# -----------------------------------------------------------------------------
# Load balancer
# -----------------------------------------------------------------------------

resource "aws_security_group" "alb" {
  name        = "workflowhub-${var.environment}-alb"
  description = "Public entry point. Accepts web traffic from the internet."
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "workflowhub-${var.environment}-alb"
  }
}

# Open to the world on purpose: this is the front door. HTTP is accepted so it
# can be redirected to HTTPS in a later step rather than silently timing out.
resource "aws_vpc_security_group_ingress_rule" "alb_http" {
  security_group_id = aws_security_group.alb.id
  description       = "HTTP from anywhere, redirected to HTTPS by the listener"

  cidr_ipv4   = "0.0.0.0/0"
  ip_protocol = "tcp"
  from_port   = 80
  to_port     = 80
}

resource "aws_vpc_security_group_ingress_rule" "alb_https" {
  security_group_id = aws_security_group.alb.id
  description       = "HTTPS from anywhere"

  cidr_ipv4   = "0.0.0.0/0"
  ip_protocol = "tcp"
  from_port   = 443
  to_port     = 443
}

resource "aws_vpc_security_group_egress_rule" "alb_all" {
  security_group_id = aws_security_group.alb.id
  description       = "Forward requests to the application tier"

  cidr_ipv4   = "0.0.0.0/0"
  ip_protocol = "-1"
}

# -----------------------------------------------------------------------------
# Application
# -----------------------------------------------------------------------------

resource "aws_security_group" "app" {
  name        = "workflowhub-${var.environment}-app"
  description = "Application tasks. Reachable only from the load balancer."
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "workflowhub-${var.environment}-app"
  }
}

# The single inbound rule for the application tier. referenced_security_group_id
# rather than a CIDR: only members of the load balancer's group may connect, no
# matter what address they hold.
resource "aws_vpc_security_group_ingress_rule" "app_from_alb" {
  security_group_id = aws_security_group.app.id
  description       = "Application port, load balancer only"

  referenced_security_group_id = aws_security_group.alb.id
  ip_protocol                  = "tcp"
  from_port                    = var.app_port
  to_port                      = var.app_port
}

# Outbound is unrestricted because the tasks genuinely need it: pulling images
# from ECR, reading secrets, writing logs to CloudWatch, and sending mail all
# leave the VPC. Without a NAT Gateway this traffic goes out through the
# internet gateway directly.
resource "aws_vpc_security_group_egress_rule" "app_all" {
  security_group_id = aws_security_group.app.id
  description       = "Outbound to ECR, AWS APIs, and external services"

  cidr_ipv4   = "0.0.0.0/0"
  ip_protocol = "-1"
}

# -----------------------------------------------------------------------------
# Database
# -----------------------------------------------------------------------------

resource "aws_security_group" "db" {
  name        = "workflowhub-${var.environment}-db"
  description = "PostgreSQL. Reachable only from the application tier."
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "workflowhub-${var.environment}-db"
  }
}

resource "aws_vpc_security_group_ingress_rule" "db_from_app" {
  security_group_id = aws_security_group.db.id
  description       = "PostgreSQL, application tier only"

  referenced_security_group_id = aws_security_group.app.id
  ip_protocol                  = "tcp"
  from_port                    = var.postgres_port
  to_port                      = var.postgres_port
}

# No egress rule exists for this group, and that is intentional rather than an
# omission. AWS attaches an allow-all egress rule to every new security group;
# Terraform removes it when the group declares no egress of its own, leaving the
# database unable to open outbound connections. It never needs to: a database
# answers connections, it does not start them.
