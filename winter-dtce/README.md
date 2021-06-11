# WinterBoot Module - Distributed Task Computing Engine (DTCE)


Winter DTCE is a Distributed Task Computing Engine.

- A Big task will be divided into multiple small tasks

- Small tasks will get executed concurrently

- Output aggregator will collect output from all small tasks

- Monitor do monitoring for any failures, retries... etc.



## Setup

setup

To enable DTCE module in applications, append following code to **application.yml**

```yaml

modules:
    -   module: dev\winterframework\kafka\DtceModule
        enabled: true
        configFile: dtce-config.yml

```
