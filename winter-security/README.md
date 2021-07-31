# Winter Boot Module - Security

Winter Security is a module that provides easy configuration to secure Winter Boot applications.


## Setup

Append following code to your application.yml

```yaml

modules:
    - module: 'dev\winterframework\data\redis\SecurityModule'
      enabled: true
      configFile: security-config.yml

```