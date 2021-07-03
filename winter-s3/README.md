# Winter Boot Module - S3

Winter S3 is a module that provides easy configuration and access to S3 Object Storage or similar services from Winter
Boot applications.

- S3Template

## Setup

Append following code to your application.yml

```yaml
modules:
    -   module: 'dev\\winterframework\\s3\\S3Module'
        enabled: true
        configFile: s3-config.yml
```

# s3-config.yml

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


```yaml
#[Autowired]
private S3Template $s3;

// or

 #[Autowired("MyS3East")]
private S3Template $s3;

```