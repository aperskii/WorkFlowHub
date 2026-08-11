provider "aws" {
  region = var.region

  # Applied to every resource this configuration creates, so tagging cannot be
  # forgotten on one resource and drift from the rest.
  default_tags {
    tags = {
      Project     = "WorkFlowHub"
      Environment = var.environment
      ManagedBy   = "Terraform"
    }
  }
}

# Availability zones are read from the account rather than hardcoded. Which
# zones exist, and which an account may use, varies between accounts even inside
# the same region.
#
# The opt-in filter excludes Local Zones and Wavelength zones, which appear in
# the list but cannot host ordinary subnets.
data "aws_availability_zones" "available" {
  state = "available"

  filter {
    name   = "opt-in-status"
    values = ["opt-in-not-required"]
  }
}

locals {
  azs = slice(data.aws_availability_zones.available.names, 0, var.az_count)

  # Two tiers carved out of 10.0.0.0/16, deliberately far apart so a third tier
  # can be added between them later without renumbering anything:
  #
  #   public   10.0.0.0/20    10.0.16.0/20    (4,096 addresses each)
  #   isolated 10.0.128.0/20  10.0.144.0/20
  #
  # cidrsubnet(prefix, 4, n) adds 4 bits to the /16, producing /20 blocks; the
  # +8 offset on the isolated tier starts it at the halfway point of the range.
  public_subnet_cidrs   = [for i in range(var.az_count) : cidrsubnet(var.vpc_cidr, 4, i)]
  isolated_subnet_cidrs = [for i in range(var.az_count) : cidrsubnet(var.vpc_cidr, 4, i + 8)]
}

resource "aws_vpc" "main" {
  cidr_block = var.vpc_cidr

  # Both are required for RDS: an instance is reached by DNS name, and without
  # hostname support inside the VPC that name does not resolve.
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = {
    Name = "workflowhub-${var.environment}"
  }
}

resource "aws_internet_gateway" "main" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "workflowhub-${var.environment}-igw"
  }
}

/*
|------------------------------------------------------------------------------
| Public tier
|------------------------------------------------------------------------------
|
| Holds the load balancer and, in a later step, the Fargate tasks. Tasks get a
| public IP so they can reach ECR, AWS APIs, and outbound services directly,
| which is what removes the need for a NAT Gateway. Nothing reaches them from
| the internet regardless, because the app security group accepts traffic only
| from the load balancer. ADR-008 records that trade-off.
*/

resource "aws_subnet" "public" {
  count = var.az_count

  vpc_id            = aws_vpc.main.id
  cidr_block        = local.public_subnet_cidrs[count.index]
  availability_zone = local.azs[count.index]

  map_public_ip_on_launch = true

  tags = {
    Name = "workflowhub-${var.environment}-public-${local.azs[count.index]}"
    Tier = "public"
  }
}

resource "aws_route_table" "public" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "workflowhub-${var.environment}-public"
  }
}

# The route that makes the tier public. Everything not destined for the VPC
# itself leaves through the internet gateway.
resource "aws_route" "public_internet" {
  route_table_id         = aws_route_table.public.id
  destination_cidr_block = "0.0.0.0/0"
  gateway_id             = aws_internet_gateway.main.id
}

resource "aws_route_table_association" "public" {
  count = var.az_count

  subnet_id      = aws_subnet.public[count.index].id
  route_table_id = aws_route_table.public.id
}

/*
|------------------------------------------------------------------------------
| Isolated tier
|------------------------------------------------------------------------------
|
| For the database only. Its route table carries no route to the internet
| gateway, so these subnets have no path off the VPC in either direction. That
| is unaffected by the no-NAT decision: a database has no reason to reach the
| internet, so it loses nothing by having no route.
*/

resource "aws_subnet" "isolated" {
  count = var.az_count

  vpc_id            = aws_vpc.main.id
  cidr_block        = local.isolated_subnet_cidrs[count.index]
  availability_zone = local.azs[count.index]

  tags = {
    Name = "workflowhub-${var.environment}-isolated-${local.azs[count.index]}"
    Tier = "isolated"
  }
}

# Deliberately routeless. AWS adds an unmanaged "local" route covering the VPC
# CIDR to every route table, which is how the app tier reaches the database;
# nothing else is added here, so there is no path out.
resource "aws_route_table" "isolated" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "workflowhub-${var.environment}-isolated"
  }
}

resource "aws_route_table_association" "isolated" {
  count = var.az_count

  subnet_id      = aws_subnet.isolated[count.index].id
  route_table_id = aws_route_table.isolated.id
}
