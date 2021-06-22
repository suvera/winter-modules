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