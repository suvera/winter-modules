# WinterBoot Module - S3

Winter S3 is a module that provides easy configuration and access to S3 Object Storage or similar services from Winter
Boot applications.

- S3Template

## Setup

```shell
composer require suvera/winter-modules
```

Append following code to your application.yml

```yaml
modules:
    -   module: 'dev\winterframework\s3\S3Module'
        enabled: true
        configFile: s3-config.yml
```

# s3-config.yml

The `credentials` section is **optional**. Only `name`, `region`, and `version` are mandatory.

## Using access keys (key / secret / token)

Example:

```yaml
s3:
    -   name: MyS3East
        version: latest
        region: us-east-1
        credentials:
            -   key: a
                secret: b
                token: c
        endpoint: url
        retries: 5
```

## Using IAM roles (no keys required)

When the application runs inside AWS — for example, a Kubernetes pod deployed on AWS EKS with an
[IAM role for the service account](https://docs.aws.amazon.com/eks/latest/userguide/iam-roles-for-service-accounts.html),
or an EC2 instance with an instance profile — you can omit the `credentials` section entirely.

The AWS SDK will then resolve credentials automatically from the
[default credential provider chain](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/guide_credentials.html)
(environment variables, IAM role, instance metadata, etc.).

```yaml
s3:
    -   name: MyS3East
        version: latest
        region: us-east-1
        endpoint: url
        retries: 5
```


```yaml
#[Autowired]
private S3Template $s3;

// or

 #[Autowired("MyS3East")]
private S3Template $s3;

```