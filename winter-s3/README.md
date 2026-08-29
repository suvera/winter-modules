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
#[Autowired("MyS3East")]
private S3Template $s3;
```

## S3Template Methods

The S3Template provides access to all AWS S3 operations through magic method calls. Below is a comprehensive list of available methods organized by usage frequency:

| Method & Description | Input Arguments & Output |
|----------------------|--------------------------|
| **putObject** - Uploads an object to a S3 bucket | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `Body` (string/stream), `ACL` (string, optional), `ContentType` (string, optional), `Metadata` (array, optional)<br>**Output:** `Aws\Result` with keys: `ETag`, `VersionId`, `ServerSideEncryption`, etc. |
| **getObject** - Retrieves an object from a S3 bucket | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `Range` (string, optional), `VersionId` (string, optional)<br>**Output:** `Aws\Result` with keys: `Body` (stream), `ContentLength`, `ContentType`, `LastModified`, `ETag` |
| **headObject** - Gets metadata of an S3 object without returning the object itself | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `VersionId` (string, optional)<br>**Output:** `Aws\Result` with keys: `ContentLength`, `ContentType`, `LastModified`, `ETag`, `Metadata` |
| **deleteObject** - Removes an object from a S3 bucket | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `VersionId` (string, optional)<br>**Output:** `Aws\Result` confirming deletion |
| **listObjects** - Lists objects in a S3 bucket (v1) | **Input:** `array` with keys: `Bucket` (string), `Prefix` (string, optional), `MaxKeys` (int, optional), `Delimiter` (string, optional)<br>**Output:** `Aws\Result` with keys: `Contents` (array of objects), `CommonPrefixes`, `IsTruncated` |
| **listObjectsV2** - Lists objects in a S3 bucket (v2) | **Input:** `array` with keys: `Bucket` (string), `Prefix` (string, optional), `MaxKeys` (int, optional), `StartAfter` (string, optional)<br>**Output:** `Aws\Result` with keys: `Contents` (array of objects), `CommonPrefixes`, `IsTruncated`, `KeyCount` |
| **copyObject** - Creates a copy of an object that is already stored in S3 | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `CopySource` (string), `ACL` (string, optional)<br>**Output:** `Aws\Result` with keys: `ETag`, `LastModified`, `VersionId` |
| **createBucket** - Creates a new S3 bucket | **Input:** `array` with keys: `Bucket` (string), `ACL` (string, optional), `CreateBucketConfiguration` (array, optional)<br>**Output:** `Aws\Result` with keys: `Location` |
| **deleteBucket** - Deletes the S3 bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **headBucket** - Determines if a bucket exists and you have permission to access it | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with bucket headers |
| **listBuckets** - Lists all buckets under the authenticated sender's account | **Input:** `array` (optional)<br>**Output:** `Aws\Result` with `Buckets` array containing bucket info |
| **getObjectAcl** - Returns the access control list (ACL) of an object | **Input:** `array` with keys: `Bucket` (string), `Key` (string)<br>**Output:** `Aws\Result` with `Owner` and `Grants` arrays |
| **putObjectAcl** - Sets the access control list (ACL) permissions for a new or existing object | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `ACL` (string) or `AccessControlPolicy`<br>**Output:** `Aws\Result` confirming ACL update |
| **getBucketAcl** - Returns the access control list (ACL) of a bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with `Owner` and `Grants` arrays |
| **putBucketAcl** - Sets the access control list (ACL) permissions for a bucket | **Input:** `array` with keys: `Bucket` (string), `ACL` (string) or `AccessControlPolicy`<br>**Output:** `Aws\Result` confirming ACL update |
| **getBucketLocation** - Returns the Region the bucket resides in | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with `LocationConstraint` |
| **getBucketVersioning** - Returns the versioning state of a bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with `Status` and `MFADelete` |
| **putBucketVersioning** - Sets the versioning state of an existing bucket | **Input:** `array` with keys: `Bucket` (string), `VersioningConfiguration` (array)<br>**Output:** `Aws\Result` confirming versioning update |
| **getBucketLifecycle** - Returns the lifecycle configuration information | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with `Rules` array |
| **putBucketLifecycle** - Sets lifecycle configuration for a bucket | **Input:** `array` with keys: `Bucket` (string), `LifecycleConfiguration` (array)<br>**Output:** `Aws\Result` confirming lifecycle update |
| **deleteBucketLifecycle** - Removes lifecycle configuration from a bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **getBucketPolicy** - Returns the policy of a specified bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with `Policy` string |
| **putBucketPolicy** - Applies an Amazon S3 bucket policy to an Amazon S3 bucket | **Input:** `array` with keys: `Bucket` (string), `Policy` (string)<br>**Output:** `Aws\Result` confirming policy update |
| **deleteBucketPolicy** - Removes the policy from the bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **getBucketCors** - Returns the cors configuration for the bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with `CORSRules` array |
| **putBucketCors** - Sets the cors configuration for a bucket | **Input:** `array` with keys: `Bucket` (string), `CORSConfiguration` (array)<br>**Output:** `Aws\Result` confirming CORS update |
| **deleteBucketCors** - Deletes the cors configuration for the bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **getBucketWebsite** - Returns the website configuration for a bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with website configuration |
| **putBucketWebsite** - Sets the configuration of the website that is to be enabled from the bucket | **Input:** `array` with keys: `Bucket` (string), `WebsiteConfiguration` (array)<br>**Output:** `Aws\Result` confirming website update |
| **deleteBucketWebsite** - Removes the website configuration for a bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **restoreObject** - Restores an archived copy of an object back into Amazon S3 | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `RestoreRequest` (array)<br>**Output:** `Aws\Result` confirming restoration |
| **createMultipartUpload** - Initiates a multipart upload | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `ACL` (string, optional), `ContentType` (string, optional)<br>**Output:** `Aws\Result` with `UploadId`, `Bucket`, `Key` |
| **uploadPart** - Uploads a part in a multipart upload | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `PartNumber` (int), `UploadId` (string), `Body` (string/stream)<br>**Output:** `Aws\Result` with `ETag` |
| **uploadPartCopy** - Uploads a part by copying data from an existing object as data source | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `PartNumber` (int), `UploadId` (string), `CopySource` (string)<br>**Output:** `Aws\Result` with `ETag`, `CopySourceVersionId` |
| **completeMultipartUpload** - Completes a multipart upload by assembling previously uploaded parts | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `UploadId` (string), `MultipartUpload` (array)<br>**Output:** `Aws\Result` with `Bucket`, `Key`, `ETag` |
| **abortMultipartUpload** - Aborts a multipart upload | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `UploadId` (string)<br>**Output:** `Aws\Result` confirming abortion |
| **listMultipartUploads** - Lists in-progress multipart uploads | **Input:** `array` with keys: `Bucket` (string), `KeyMarker` (string, optional), `MaxUploads` (int, optional)<br>**Output:** `Aws\Result` with `Uploads` array |
| **listParts** - Lists the parts that have been uploaded for a specific multipart upload | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `UploadId` (string), `PartNumberMarker` (int, optional)<br>**Output:** `Aws\Result` with `Parts` array |
| **selectObjectContent** - Performs the SQL operation on your data | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `Expression` (string), `ExpressionType` (string), `InputSerialization` (array), `OutputSerialization` (array)<br>**Output:** `Aws\Result` with `Payload` stream |
| **getObjectTagging** - Returns the tag-set of an object | **Input:** `array` with keys: `Bucket` (string), `Key` (string)<br>**Output:** `Aws\Result` with `TagSet` array |
| **putObjectTagging** - Sets the supplied tag-set to an object | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `Tagging` (array)<br>**Output:** `Aws\Result` confirming tagging update |
| **deleteObjectTagging** - Removes the tag-set from an object | **Input:** `array` with keys: `Bucket` (string), `Key` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **getObjectRetention** - Returns the retention settings for an object | **Input:** `array` with keys: `Bucket` (string), `Key` (string)<br>**Output:** `Aws\Result` with retention settings |
| **putObjectRetention** - Applies a retention configuration to an object | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `Retention` (array)<br>**Output:** `Aws\Result` confirming retention update |
| **getObjectLegalHold** - Returns the legal hold information | **Input:** `array` with keys: `Bucket` (string), `Key` (string)<br>**Output:** `Aws\Result` with legal hold status |
| **putObjectLegalHold** - Applies a legal hold configuration to an object | **Input:** `array` with keys: `Bucket` (string), `Key` (string), `LegalHold` (array)<br>**Output:** `Aws\Result` confirming legal hold update |
| **getBucketEncryption** - Returns the default encryption configuration | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with encryption configuration |
| **putBucketEncryption** - Creates a new default encryption configuration | **Input:** `array` with keys: `Bucket` (string), `ServerSideEncryptionConfiguration` (array)<br>**Output:** `Aws\Result` confirming encryption update |
| **deleteBucketEncryption** - Removes the default encryption configuration | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **getPublicAccessBlock** - Returns the Public Access Block configuration | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with public access block configuration |
| **putPublicAccessBlock** - Creates or modifies the Public Access Block configuration | **Input:** `array` with keys: `Bucket` (string), `PublicAccessBlockConfiguration` (array)<br>**Output:** `Aws\Result` confirming public access block update |
| **deletePublicAccessBlock** - Removes the Public Access Block configuration | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` confirming deletion |
| **getBucketRequestPayment** - Returns the request payment configuration | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with `Payer` |
| **putBucketRequestPayment** - Sets the request payment configuration | **Input:** `array` with keys: `Bucket` (string), `RequestPaymentConfiguration` (array)<br>**Output:** `Aws\Result` confirming request payment update |
| **getBucketLogging** - Returns the logging status of a bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with logging configuration |
| **putBucketLogging** - Sets the logging parameters for a bucket | **Input:** `array` with keys: `Bucket` (string), `BucketLoggingStatus` (array)<br>**Output:** `Aws\Result` confirming logging update |
| **getBucketNotificationConfiguration** - Returns the notification configuration of a bucket | **Input:** `array` with keys: `Bucket` (string)<br>**Output:** `Aws\Result` with notification configuration |
| **putBucketNotificationConfiguration** - Enables notifications of specified events for a bucket | **Input:** `array` with keys: `Bucket` (string), `NotificationConfiguration` (array)<br>**Output:** `Aws\Result` confirming notification update |
| **deleteObjects** - This operation enables you to delete multiple objects from a bucket | **Input:** `array` with keys: `Bucket` (string), `Delete` (array of objects to delete)<br>**Output:** `Aws\Result` with `Deleted` and `Errors` arrays |
| **getObjectTorrent** - Return torrent files from a bucket | **Input:** `array` with keys: `Bucket` (string), `Key` (string)<br>**Output:** `Aws\Result` with torrent data |
| **writeGetObjectResponse** - Writes an object using a multipart upload | **Input:** `array` with keys: `RequestId` (string), `Body` (string/stream), `SSEKMSEncryptionContext` (string, optional)<br>**Output:** `Aws\Result` confirming write operation |

### Detailed method signatures

Below each method is described with clear **Input** and **Output** sections. The `array` refers to an associative PHP array where the keys are the parameter names defined by the AWS SDK for PHP.

- **putObject**
  - **Input** (`array`):
    - `Bucket` (string) – name of the bucket.
    - `Key` (string) – object key.
    - `Body` (string|resource) – object data.
    - `ACL` (string, optional) – canned ACL.
    - `ContentType` (string, optional)
    - `Metadata` (array, optional)
  - **Output** (`Aws\Result`): contains `ETag`, `VersionId`, etc.

- **getObject**
  - **Input** (`array`):
    - `Bucket` (string)
    - `Key` (string)
    - `Range` (string, optional)
    - `VersionId` (string, optional)
  - **Output** (`Aws\Result`): `Body` (stream), `ContentLength`, `ContentType`, `LastModified`, `ETag`

- **deleteObject**
  - **Input** (`array`):
    - `Bucket` (string)
    - `Key` (string)
    - `VersionId` (string, optional)
  - **Output** (`Aws\Result`): confirms deletion

- **listObjectsV2**
  - **Input** (`array`):
    - `Bucket` (string)
    - `Prefix` (string, optional)
    - `MaxKeys` (int, optional)
    - `StartAfter` (string, optional)
  - **Output** (`Aws\Result`): `Contents`, `CommonPrefixes`, `IsTruncated`, `KeyCount`

For the full list of methods and their parameters, see the official AWS SDK for PHP S3 client documentation:
https://docs.aws.amazon.com/sdk-for-php/v3/api/api-s3-2016-03-01.html
