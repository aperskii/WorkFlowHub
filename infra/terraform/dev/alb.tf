/*
|------------------------------------------------------------------------------
| Load balancer
|------------------------------------------------------------------------------
|
| The public entry point, and the first resource in this project that bills by
| the hour purely for existing: $0.027 per hour in eu-central-1, roughly $19.71
| a month, plus a negligible capacity charge at this traffic level. There is no
| free tier for it. That is the reason this environment is destroyed at the end
| of every session.
|
| HTTP only. Terminating TLS needs a certificate, which needs a domain, which is
| a decision of its own; until then the listener speaks plain HTTP on port 80.
*/

resource "aws_lb" "main" {
  name               = "workflowhub-${var.environment}"
  load_balancer_type = "application"

  # Internet-facing, so it needs subnets with a route to the internet gateway.
  # This is the only tier that is deliberately reachable from outside.
  internal = false
  subnets  = aws_subnet.public[*].id

  security_groups = [aws_security_group.alb.id]

  # Off so `terraform destroy` is never blocked. Correct for a disposable
  # environment; the opposite of what production should do.
  enable_deletion_protection = false

  tags = {
    Name = "workflowhub-${var.environment}"
  }
}

# target_type must be "ip" rather than "instance": Fargate tasks use awsvpc
# networking and own an elastic network interface directly, so there is no
# container instance to register.
resource "aws_lb_target_group" "app" {
  name        = "workflowhub-${var.environment}-app"
  target_type = "ip"
  vpc_id      = aws_vpc.main.id

  port     = var.app_port
  protocol = "HTTP"

  # Drain time before a deregistering task is dropped. The 300 second default
  # exists to let real requests finish; there is no real traffic here, and it
  # would add five minutes to every teardown.
  deregistration_delay = var.deregistration_delay

  # Thresholds derived from what the container actually did in Level 4b: it
  # served a 200 roughly 18 seconds after the entrypoint began, and about 5
  # seconds after supervisord brought nginx up.
  #
  # A 15 second interval with two successes registers a new task as healthy
  # about 30 seconds after it starts answering, which is comfortably inside the
  # grace period below. Three failures marks it unhealthy after 45 seconds:
  # slow enough to ride out a single blip, fast enough that a broken deployment
  # is caught in under a minute.
  health_check {
    enabled             = true
    path                = "/up"
    protocol            = "HTTP"
    matcher             = "200"
    interval            = 15
    timeout             = 5
    healthy_threshold   = 2
    unhealthy_threshold = 3
  }

  tags = {
    Name = "workflowhub-${var.environment}-app"
  }
}

resource "aws_lb_listener" "http" {
  load_balancer_arn = aws_lb.main.arn
  port              = 80
  protocol          = "HTTP"

  default_action {
    type             = "forward"
    target_group_arn = aws_lb_target_group.app.arn
  }

  tags = {
    Name = "workflowhub-${var.environment}-http"
  }
}
