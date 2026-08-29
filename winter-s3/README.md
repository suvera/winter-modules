# WinterBoot Module - S3

Winter S3 is a module that provides easy configuration and access to S3 Object Storage or similar services from Winter
Boot applications.

- [`S3Template`](src/S3Template.php)

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

The S3Template provides access to all AWS S3 operations through magic method calls. The methods accept an **associative array** of parameters where the keys match the AWS SDK for PHP S3 client method signatures.

### Understanding the Input Format

The "Input" column shows an **associative PHP array** where keys are parameter names defined by the AWS SDK. For example:

```php
// Example: putObject with required and optional parameters
$result = $s3->putObject([
    'Bucket' => 'my-bucket',           // Required: The bucket name
    'Key' => 'path/to/file.txt',       // Required: The object key
    'Body' => 'file content',          // Required: The object data
    'ACL' => 'public-read',            // Optional: Canned ACL
    'ContentType' => 'text/plain',     // Optional: MIME type
    'Metadata' => ['key' => 'value']   // Optional: Custom metadata
]);
```

### Commonly Used Methods

| Method | Description | Input Parameters | Output |
|--------|-------------|------------------|--------|
| [`putObject()`](#putobject) | Uploads an object to a S3 bucket | `Bucket`, `Key`, `Body`, `ACL?`, `ContentType?`, `Metadata?` | [`Aws\Result`](#result-structure) |
| [`getObject()`](#getobject) | Retrieves an object from a S3 bucket | `Bucket`, `Key`, `Range?`, `VersionId?` | [`Aws\Result`](#result-structure) |
| [`headObject()`](#headobject) | Gets object metadata without returning the object | `Bucket`, `Key`, `VersionId?` | [`Aws\Result`](#result-structure) |
| [`deleteObject()`](#deleteobject) | Removes an object from a S3 bucket | `Bucket`, `Key`, `VersionId?` | [`Aws\Result`](#result-structure) |
| [`listObjects()`](#listobjects) | Lists objects in a S3 bucket (v1) | `Bucket`, `Prefix?`, `MaxKeys?`, `Delimiter?` | [`Aws\Result`](#result-structure) |
| [`listObjectsV2()`](#listobjectsv2) | Lists objects in a S3 bucket (v2) | `Bucket`, `Prefix?`, `MaxKeys?`, `StartAfter?` | [`Aws\Result`](#result-structure) |
| [`copyObject()`](#copyobject) | Creates a copy of an object in S3 | `Bucket`, `Key`, `CopySource`, `ACL?` | [`Aws\Result`](#result-structure) |
| [`createBucket()`](#createbucket) | Creates a new S3 bucket | `Bucket`, `ACL?`, `CreateBucketConfiguration?` | [`Aws\Result`](#result-structure) |
| [`deleteBucket()`](#deletebucket) | Deletes the S3 bucket | `Bucket` | [`Aws\Result`](#result-structure) |
| [`headBucket()`](#headbucket) | Checks if bucket exists and is accessible | `Bucket` | [`Aws\Result`](#result-structure) |
| [`listBuckets()`](#listbuckets) | Lists all buckets under the account | *(none)* | [`Aws\Result`](#result-structure) |
| [`getObjectAcl()`](#getobjectacl) | Returns ACL of an object | `Bucket`, `Key` | [`Aws\Result`](#result-structure) |
| [`putObjectAcl()`](#putobjectacl) | Sets ACL for an object | `Bucket`, `Key`, `ACL` or `AccessControlPolicy` | [`Aws\Result`](#result-structure) |
| [`getBucketAcl()`](#getbucketacl) | Returns ACL of a bucket | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketAcl()`](#putbucketacl) | Sets ACL for a bucket | `Bucket`, `ACL` or `AccessControlPolicy` | [`Aws\Result`](#result-structure) |
| [`getBucketLocation()`](#getbucketlocation) | Returns the bucket's region | `Bucket` | [`Aws\Result`](#result-structure) |
| [`getBucketVersioning()`](#getbucketversioning) | Returns versioning state of a bucket | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketVersioning()`](#putbucketversioning) | Sets versioning state of a bucket | `Bucket`, `VersioningConfiguration` | [`Aws\Result`](#result-structure) |
| [`getBucketLifecycle()`](#getbucketlifecycle) | Returns lifecycle configuration | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketLifecycle()`](#putbucketlifecycle) | Sets lifecycle configuration | `Bucket`, `LifecycleConfiguration` | [`Aws\Result`](#result-structure) |
| [`deleteBucketLifecycle()`](#deletebucketlifecycle) | Removes lifecycle configuration | `Bucket` | [`Aws\Result`](#result-structure) |
| [`getBucketPolicy()`](#getbucketpolicy) | Returns bucket policy | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketPolicy()`](#putbucketpolicy) | Sets bucket policy | `Bucket`, `Policy` | [`Aws\Result`](#result-structure) |
| [`deleteBucketPolicy()`](#deletebucketpolicy) | Removes bucket policy | `Bucket` | [`Aws\Result`](#result-structure) |
| [`getBucketCors()`](#getbucketcors) | Returns CORS configuration | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketCors()`](#putbucketcors) | Sets CORS configuration | `Bucket`, `CORSConfiguration` | [`Aws\Result`](#result-structure) |
| [`deleteBucketCors()`](#deletebucketcors) | Removes CORS configuration | `Bucket` | [`Aws\Result`](#result-structure) |
| [`getBucketWebsite()`](#getbucketwebsite) | Returns website configuration | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketWebsite()`](#putbucketwebsite) | Sets website configuration | `Bucket`, `WebsiteConfiguration` | [`Aws\Result`](#result-structure) |
| [`deleteBucketWebsite()`](#deletebucketwebsite) | Removes website configuration | `Bucket` | [`Aws\Result`](#result-structure) |
| [`restoreObject()`](#restoreobject) | Restores an archived object | `Bucket`, `Key`, `RestoreRequest` | [`Aws\Result`](#result-structure) |
| [`createMultipartUpload()`](#createmultipartupload) | Initiates a multipart upload | `Bucket`, `Key`, `ACL?`, `ContentType?` | [`Aws\Result`](#result-structure) |
| [`uploadPart()`](#uploadpart) | Uploads a part in multipart upload | `Bucket`, `Key`, `PartNumber`, `UploadId`, `Body` | [`Aws\Result`](#result-structure) |
| [`uploadPartCopy()`](#uploadpartcopy) | Uploads a part by copying from existing object | `Bucket`, `Key`, `PartNumber`, `UploadId`, `CopySource` | [`Aws\Result`](#result-structure) |
| [`completeMultipartUpload()`](#completemultipartupload) | Completes a multipart upload | `Bucket`, `Key`, `UploadId`, `MultipartUpload` | [`Aws\Result`](#result-structure) |
| [`abortMultipartUpload()`](#abortmultipartupload) | Aborts a multipart upload | `Bucket`, `Key`, `UploadId` | [`Aws\Result`](#result-structure) |
| [`listMultipartUploads()`](#listmultipartuploads) | Lists in-progress multipart uploads | `Bucket`, `KeyMarker?`, `MaxUploads?` | [`Aws\Result`](#result-structure) |
| [`listParts()`](#listparts) | Lists uploaded parts for a multipart upload | `Bucket`, `Key`, `UploadId`, `PartNumberMarker?` | [`Aws\Result`](#result-structure) |
| [`selectObjectContent()`](#selectobjectcontent) | Performs SQL operation on data | `Bucket`, `Key`, `Expression`, `ExpressionType`, `InputSerialization`, `OutputSerialization` | [`Aws\Result`](#result-structure) |
| [`getObjectTagging()`](#getobjecttagging) | Returns object tags | `Bucket`, `Key` | [`Aws\Result`](#result-structure) |
| [`putObjectTagging()`](#putobjecttagging) | Sets object tags | `Bucket`, `Key`, `Tagging` | [`Aws\Result`](#result-structure) |
| [`deleteObjectTagging()`](#deleteobjecttagging) | Removes object tags | `Bucket`, `Key` | [`Aws\Result`](#result-structure) |
| [`getObjectRetention()`](#getobjectretention) | Returns object retention settings | `Bucket`, `Key` | [`Aws\Result`](#result-structure) |
| [`putObjectRetention()`](#putobjectretention) | Sets object retention | `Bucket`, `Key`, `Retention` | [`Aws\Result`](#result-structure) |
| [`getObjectLegalHold()`](#getobjectlegalhold) | Returns legal hold information | `Bucket`, `Key` | [`Aws\Result`](#result-structure) |
| [`putObjectLegalHold()`](#putobjectlegalhold) | Sets legal hold | `Bucket`, `Key`, `LegalHold` | [`Aws\Result`](#result-structure) |
| [`getBucketEncryption()`](#getbucketencryption) | Returns default encryption config | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketEncryption()`](#putbucketencryption) | Sets default encryption config | `Bucket`, `ServerSideEncryptionConfiguration` | [`Aws\Result`](#result-structure) |
| [`deleteBucketEncryption()`](#deletebucketencryption) | Removes encryption config | `Bucket` | [`Aws\Result`](#result-structure) |
| [`getPublicAccessBlock()`](#getpublicaccessblock) | Returns Public Access Block config | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putPublicAccessBlock()`](#putpublicaccessblock) | Sets Public Access Block config | `Bucket`, `PublicAccessBlockConfiguration` | [`Aws\Result`](#result-structure) |
| [`deletePublicAccessBlock()`](#deletepublicaccessblock) | Removes Public Access Block config | `Bucket` | [`Aws\Result`](#result-structure) |
| [`getBucketRequestPayment()`](#getbucketrequestpayment) | Returns request payment config | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketRequestPayment()`](#putbucketrequestpayment) | Sets request payment config | `Bucket`, `RequestPaymentConfiguration` | [`Aws\Result`](#result-structure) |
| [`getBucketLogging()`](#getbucketlogging) | Returns logging status | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketLogging()`](#putbucketlogging) | Sets logging parameters | `Bucket`, `BucketLoggingStatus` | [`Aws\Result`](#result-structure) |
| [`getBucketNotificationConfiguration()`](#getbucketnotificationconfiguration) | Returns notification config | `Bucket` | [`Aws\Result`](#result-structure) |
| [`putBucketNotificationConfiguration()`](#putbucketnotificationconfiguration) | Enables notifications | `Bucket`, `NotificationConfiguration` | [`Aws\Result`](#result-structure) |
| [`deleteObjects()`](#deleteobjects) | Deletes multiple objects | `Bucket`, `Delete` (array of objects) | [`Aws\Result`](#result-structure) |
| [`getObjectTorrent()`](#getobjecttorrent) | Returns torrent file | `Bucket`, `Key` | [`Aws\Result`](#result-structure) |
| [`writeGetObjectResponse()`](#writegetobjectresponse) | Writes an object using multipart upload | `RequestId`, `Body`, `SSEKMSEncryptionContext?` | [`Aws\Result`](#result-structure) |

### Detailed Method Documentation

#### `putObject()`

Uploads an object to a S3 bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `Body` (**string\|resource\|StreamInterface**, required) – The object data or stream
- `ACL` (**string**, optional) – Canned ACL (e.g., `private`, `public-read`, `public-read-write`)
- `ContentType` (**string**, optional) – MIME type of the object
- `Metadata` (**array**, optional) – Custom metadata key-value pairs

**Output** ([`Aws\Result`](#result-structure)):
- `ETag` – Entity tag of the object
- `VersionId` – Version ID if versioning is enabled
- `ServerSideEncryption` – Server-side encryption algorithm used

**Example**:
```php
$result = $s3->putObject([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.txt',
    'Body' => 'Hello, World!',
    'ContentType' => 'text/plain',
    'ACL' => 'private'
]);
echo "ETag: " . $result['ETag'];
```

---

#### `getObject()`

Retrieves an object from a S3 bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `Range` (**string**, optional) – Byte range to retrieve (e.g., `bytes=0-999`)
- `VersionId` (**string**, optional) – Specific version to retrieve

**Output** ([`Aws\Result`](#result-structure)):
- `Body` (**StreamInterface**) – The object data stream
- `ContentLength` – Size of the object in bytes
- `ContentType` – MIME type of the object
- `LastModified` – Last modification timestamp
- `ETag` – Entity tag of the object

**Example**:
```php
$result = $s3->getObject([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.txt'
]);

// Read the object content
$content = $result['Body']->getContents();
echo $content;
```

---

#### `headObject()`

Gets object metadata without returning the object itself.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `VersionId` (**string**, optional) – Specific version to check

**Output** ([`Aws\Result`](#result-structure)):
- `ContentLength` – Size of the object in bytes
- `ContentType` – MIME type of the object
- `LastModified` – Last modification timestamp
- `ETag` – Entity tag of the object
- `Metadata` – Custom metadata

**Example**:
```php
$result = $s3->headObject([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.txt'
]);

echo "Content-Type: " . $result['ContentType'];
echo "Size: " . $result['ContentLength'] . " bytes";
```

---

#### `deleteObject()`

Removes an object from a S3 bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `VersionId` (**string**, optional) – Specific version to delete

**Output** ([`Aws\Result`](#result-structure)):
- `DeleteMarker` – Boolean indicating if a delete marker was created
- `VersionId` – Version ID of the delete marker

**Example**:
```php
$result = $s3->deleteObject([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.txt'
]);

echo "Deleted: " . ($result['DeleteMarker'] ? 'yes' : 'no');
```

---

#### `listObjects()`

Lists objects in a S3 bucket (v1 API).

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Prefix` (**string**, optional) – Filter keys starting with this prefix
- `MaxKeys` (**int**, optional) – Maximum number of keys to return
- `Delimiter` (**string**, optional) – Character to group keys (e.g., `/`)

**Output** ([`Aws\Result`](#result-structure)):
- `Contents` (**array**) – Array of object metadata
- `CommonPrefixes` (**array**) – Common prefixes (when using Delimiter)
- `IsTruncated` (**bool**) – Whether more objects exist

**Example**:
```php
$result = $s3->listObjects([
    'Bucket' => 'my-bucket',
    'Prefix' => 'images/',
    'MaxKeys' => 100
]);

foreach ($result['Contents'] as $object) {
    echo $object['Key'] . " - " . $object['Size'] . " bytes\n";
}
```

---

#### `listObjectsV2()`

Lists objects in a S3 bucket (v2 API - more efficient).

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Prefix` (**string**, optional) – Filter keys starting with this prefix
- `MaxKeys` (**int**, optional) – Maximum number of keys to return
- `StartAfter` (**string**, optional) – Start after this key

**Output** ([`Aws\Result`](#result-structure)):
- `Contents` (**array**) – Array of object metadata
- `CommonPrefixes` (**array**) – Common prefixes
- `IsTruncated` (**bool**) – Whether more objects exist
- `KeyCount` (**int**) – Number of keys returned

**Example**:
```php
$result = $s3->listObjectsV2([
    'Bucket' => 'my-bucket',
    'Prefix' => 'logs/',
    'MaxKeys' => 1000
]);

echo "Found " . $result['KeyCount'] . " objects\n";
```

---

#### `copyObject()`

Creates a copy of an object that is already stored in S3.

**Input** (`array`):
- `Bucket` (**string**, required) – The destination bucket name
- `Key` (**string**, required) – The destination object key
- `CopySource` (**string**, required) – Source bucket/key (e.g., `/bucket/key` or `/bucket/key?versionId=xxx`)
- `ACL` (**string**, optional) – Canned ACL for the copy

**Output** ([`Aws\Result`](#result-structure)):
- `ETag` – Entity tag of the copied object
- `LastModified` – Copy completion timestamp
- `VersionId` – Version ID if versioning is enabled

**Example**:
```php
$result = $s3->copyObject([
    'Bucket' => 'my-bucket',
    'Key' => 'backup/file.txt',
    'CopySource' => 'my-bucket/original/file.txt'
]);

echo "Copy complete: " . $result['CopyObjectResult']['ETag'];
```

---

#### `createBucket()`

Creates a new S3 bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `ACL` (**string**, optional) – Canned ACL
- `CreateBucketConfiguration` (**array**, optional) – Location constraint for region

**Output** ([`Aws\Result`](#result-structure)):
- `Location` – The URI of the created bucket

**Example**:
```php
$result = $s3->createBucket([
    'Bucket' => 'my-unique-bucket-name',
    'CreateBucketConfiguration' => [
        'LocationConstraint' => 'us-west-2'
    ]
]);

echo "Bucket created at: " . $result['Location'];
```

---

#### `deleteBucket()`

Deletes the S3 bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deleteBucket([
    'Bucket' => 'my-bucket'
]);
echo "Bucket deleted";
```

---

#### `headBucket()`

Determines if a bucket exists and you have permission to access it.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- Bucket headers (status code 200 on success)

**Example**:
```php
try {
    $result = $s3->headBucket(['Bucket' => 'my-bucket']);
    echo "Bucket exists and is accessible\n";
} catch (Exception $e) {
    echo "Bucket not accessible\n";
}
```

---

#### `listBuckets()`

Lists all buckets under the authenticated sender's account.

**Input** (`array`):
- *(none required)*

**Output** ([`Aws\Result`](#result-structure)):
- `Buckets` (**array**) – Array of bucket information
  - `Name` – Bucket name
  - `CreationDate` – Creation timestamp

**Example**:
```php
$result = $s3->listBuckets();

foreach ($result['Buckets'] as $bucket) {
    echo $bucket['Name'] . " (created: " . $bucket['CreationDate'] . ")\n";
}
```

---

#### `getObjectAcl()`

Returns the access control list (ACL) of an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key

**Output** ([`Aws\Result`](#result-structure)):
- `Owner` (**array**) – Owner information
  - `ID` – Owner ID
  - `DisplayName` – Owner display name
- `Grants` (**array**) – Array of grant information
  - `Grantee` – Grantee information
  - `Permission` – Access permission

**Example**:
```php
$result = $s3->getObjectAcl([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.txt'
]);

foreach ($result['Grants'] as $grant) {
    echo $grant['Grantee']['DisplayName'] . " has " . $grant['Permission'] . "\n";
}
```

---

#### `putObjectAcl()`

Sets the access control list (ACL) permissions for an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `ACL` (**string**, optional) – Canned ACL (e.g., `private`, `public-read`)
- `AccessControlPolicy` (**array**, optional) – Full ACL policy

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putObjectAcl([
    'Bucket' => 'my-bucket',
    'Key' => 'path/to/file.txt',
    'ACL' => 'public-read'
]);
echo "ACL updated to public-read";
```

---

#### `getBucketAcl()`

Returns the access control list (ACL) of a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `Owner` (**array**) – Owner information
- `Grants` (**array**) – Array of grant information

**Example**:
```php
$result = $s3->getBucketAcl(['Bucket' => 'my-bucket']);
print_r($result['Grants']);
```

---

#### `putBucketAcl()`

Sets the access control list (ACL) permissions for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `ACL` (**string**, optional) – Canned ACL
- `AccessControlPolicy` (**array**, optional) – Full ACL policy

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketAcl([
    'Bucket' => 'my-bucket',
    'ACL' => 'private'
]);
echo "Bucket ACL updated";
```

---

#### `getBucketLocation()`

Returns the Region the bucket resides in.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `LocationConstraint` (**string**) – The bucket region

**Example**:
```php
$result = $s3->getBucketLocation(['Bucket' => 'my-bucket']);
echo "Bucket is in: " . $result['LocationConstraint'] . "\n";
```

---

#### `getBucketVersioning()`

Returns the versioning state of a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `Status` (**string**) – `Enabled` or `Suspended`
- `MFADelete` (**string**) – MFA delete status

**Example**:
```php
$result = $s3->getBucketVersioning(['Bucket' => 'my-bucket']);
echo "Versioning: " . $result['Status'] . "\n";
```

---

#### `putBucketVersioning()`

Sets the versioning state of an existing bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `VersioningConfiguration` (**array**, required)
  - `Status` (**string**) – `Enabled` or `Suspended`

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketVersioning([
    'Bucket' => 'my-bucket',
    'VersioningConfiguration' => [
        'Status' => 'Enabled'
    ]
]);
echo "Versioning enabled";
```

---

#### `getBucketLifecycle()`

Returns the lifecycle configuration information for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `Rules` (**array**) – Array of lifecycle rules

**Example**:
```php
$result = $s3->getBucketLifecycle(['Bucket' => 'my-bucket']);
print_r($result['Rules']);
```

---

#### `putBucketLifecycle()`

Sets lifecycle configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `LifecycleConfiguration` (**array**, required)
  - `Rules` (**array**) – Array of lifecycle rules

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketLifecycle([
    'Bucket' => 'my-bucket',
    'LifecycleConfiguration' => [
        'Rules' => [
            [
                'ID' => 'Archive to Glacier',
                'Status' => 'Enabled',
                'Prefix' => 'logs/',
                'Transitions' => [
                    [
                        'Days' => 90,
                        'StorageClass' => 'GLACIER'
                    ]
                ]
            ]
        ]
    ]
]);
echo "Lifecycle rule created";
```

---

#### `deleteBucketLifecycle()`

Removes lifecycle configuration from a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deleteBucketLifecycle(['Bucket' => 'my-bucket']);
echo "Lifecycle configuration removed";
```

---

#### `getBucketPolicy()`

Returns the policy of a specified bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `Policy` (**string**) – The bucket policy as JSON string

**Example**:
```php
$result = $s3->getBucketPolicy(['Bucket' => 'my-bucket']);
echo $result['Policy'];
```

---

#### `putBucketPolicy()`

Applies an Amazon S3 bucket policy to a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Policy` (**string**, required) – The policy as JSON string

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$policy = json_encode([
    'Version' => '2012-10-17',
    'Statement' => [
        [
            'Effect' => 'Allow',
            'Principal' => '*',
            'Action' => ['s3:GetObject'],
            'Resource' => 'arn:aws:s3:::my-bucket/*'
        ]
    ]
]);

$s3->putBucketPolicy([
    'Bucket' => 'my-bucket',
    'Policy' => $policy
]);
echo "Bucket policy updated";
```

---

#### `deleteBucketPolicy()`

Removes the policy from the bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deleteBucketPolicy(['Bucket' => 'my-bucket']);
echo "Bucket policy deleted";
```

---

#### `getBucketCors()`

Returns the CORS configuration for the bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `CORSRules` (**array**) – Array of CORS rules

**Example**:
```php
$result = $s3->getBucketCors(['Bucket' => 'my-bucket']);
print_r($result['CORSRules']);
```

---

#### `putBucketCors()`

Sets the CORS configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `CORSConfiguration` (**array**, required)
  - `CORSRules` (**array**) – Array of CORS rules

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketCors([
    'Bucket' => 'my-bucket',
    'CORSConfiguration' => [
        'CORSRules' => [
            [
                'AllowedOrigins' => ['https://example.com'],
                'AllowedMethods' => ['GET', 'PUT'],
                'AllowedHeaders' => ['*'],
                'MaxAgeSeconds' => 3000
            ]
        ]
    ]
]);
echo "CORS configuration set";
```

---

#### `deleteBucketCors()`

Deletes the CORS configuration for the bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deleteBucketCors(['Bucket' => 'my-bucket']);
echo "CORS configuration deleted";
```

---

#### `getBucketWebsite()`

Returns the website configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- Website configuration array

**Example**:
```php
$result = $s3->getBucketWebsite(['Bucket' => 'my-bucket']);
print_r($result);
```

---

#### `putBucketWebsite()`

Sets the configuration of the website that is to be enabled from the bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `WebsiteConfiguration` (**array**, required)
  - `IndexDocument` (**array**) – Index document settings
  - `ErrorDocument` (**array**, optional) – Error document settings

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketWebsite([
    'Bucket' => 'my-bucket',
    'WebsiteConfiguration' => [
        'IndexDocument' => ['Suffix' => 'index.html'],
        'ErrorDocument' => ['Key' => 'error.html']
    ]
]);
echo "Website configuration set";
```

---

#### `deleteBucketWebsite()`

Removes the website configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deleteBucketWebsite(['Bucket' => 'my-bucket']);
echo "Website configuration removed";
```

---

#### `restoreObject()`

Restores an archived copy of an object back into Amazon S3.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `RestoreRequest` (**array**, required)
  - `Days` (**int**) – Number of days to keep the restored object
  - `GlacierJobParameters` (**array**) – Job parameters

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->restoreObject([
    'Bucket' => 'my-bucket',
    'Key' => 'archived-file.tar.gz',
    'RestoreRequest' => [
        'Days' => 7
    ]
]);
echo "Restore request submitted";
```

---

#### `createMultipartUpload()`

Initiates a multipart upload.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `ACL` (**string**, optional) – Canned ACL
- `ContentType` (**string**, optional) – MIME type

**Output** ([`Aws\Result`](#result-structure)):
- `UploadId` (**string**) – The upload ID
- `Bucket` (**string**) – The bucket name
- `Key` (**string**) – The object key

**Example**:
```php
$result = $s3->createMultipartUpload([
    'Bucket' => 'my-bucket',
    'Key' => 'large-file.zip'
]);

$uploadId = $result['UploadId'];
echo "Upload ID: " . $uploadId . "\n";
```

---

#### `uploadPart()`

Uploads a part in a multipart upload.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `PartNumber` (**int**, required) – Part number (1-based)
- `UploadId` (**string**, required) – The upload ID
- `Body` (**string\|resource\|StreamInterface**, required) – The part data

**Output** ([`Aws\Result`](#result-structure)):
- `ETag` (**string**) – Entity tag of the part

**Example**:
```php
$part1 = $s3->uploadPart([
    'Bucket' => 'my-bucket',
    'Key' => 'large-file.zip',
    'PartNumber' => 1,
    'UploadId' => $uploadId,
    'Body' => fopen('part1.dat', 'r')
]);

echo "Part 1 ETag: " . $part1['ETag'] . "\n";
```

---

#### `uploadPartCopy()`

Uploads a part by copying data from an existing object as data source.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `PartNumber` (**int**, required) – Part number
- `UploadId` (**string**, required) – The upload ID
- `CopySource` (**string**, required) – Source bucket/key

**Output** ([`Aws\Result`](#result-structure)):
- `ETag` (**string**) – Entity tag of the copied part
- `CopySourceVersionId` (**string**) – Version ID if applicable

**Example**:
```php
$result = $s3->uploadPartCopy([
    'Bucket' => 'my-bucket',
    'Key' => 'copy-of-file.zip',
    'PartNumber' => 1,
    'UploadId' => $uploadId,
    'CopySource' => 'my-bucket/original-file.zip'
]);

echo "Copied part ETag: " . $result['ETag'] . "\n";
```

---

#### `completeMultipartUpload()`

Completes a multipart upload by assembling previously uploaded parts.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `UploadId` (**string**, required) – The upload ID
- `MultipartUpload` (**array**, required)
  - `Parts` (**array**) – Array of part information
    - `ETag` (**string**) – Part ETag
    - `PartNumber` (**int**) – Part number

**Output** ([`Aws\Result`](#result-structure)):
- `Bucket` (**string**) – The bucket name
- `Key` (**string**) – The object key
- `ETag` (**string**) – Entity tag of the complete object

**Example**:
```php
$parts = [
    ['ETag' => $part1['ETag'], 'PartNumber' => 1],
    ['ETag' => $part2['ETag'], 'PartNumber' => 2],
];

$result = $s3->completeMultipartUpload([
    'Bucket' => 'my-bucket',
    'Key' => 'large-file.zip',
    'UploadId' => $uploadId,
    'MultipartUpload' => ['Parts' => $parts]
]);

echo "Upload complete: " . $result['ETag'] . "\n";
```

---

#### `abortMultipartUpload()`

Aborts a multipart upload.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `UploadId` (**string**, required) – The upload ID

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->abortMultipartUpload([
    'Bucket' => 'my-bucket',
    'Key' => 'large-file.zip',
    'UploadId' => $uploadId
]);
echo "Upload aborted";
```

---

#### `listMultipartUploads()`

Lists in-progress multipart uploads.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `KeyMarker` (**string**, optional) – Key marker for pagination
- `MaxUploads` (**int**, optional) – Maximum number of uploads to return

**Output** ([`Aws\Result`](#result-structure)):
- `Uploads` (**array**) – Array of multipart upload information

**Example**:
```php
$result = $s3->listMultipartUploads([
    'Bucket' => 'my-bucket',
    'MaxUploads' => 100
]);

foreach ($result['Uploads'] as $upload) {
    echo "Upload ID: " . $upload['UploadId'] . " for " . $upload['Key'] . "\n";
}
```

---

#### `listParts()`

Lists the parts that have been uploaded for a specific multipart upload.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `UploadId` (**string**, required) – The upload ID
- `PartNumberMarker` (**int**, optional) – Part number marker for pagination

**Output** ([`Aws\Result`](#result-structure)):
- `Parts` (**array**) – Array of part information
  - `PartNumber` (**int**) – Part number
  - `ETag` (**string**) – Part ETag
  - `Size` (**int**) – Part size in bytes

**Example**:
```php
$result = $s3->listParts([
    'Bucket' => 'my-bucket',
    'Key' => 'large-file.zip',
    'UploadId' => $uploadId
]);

foreach ($result['Parts'] as $part) {
    echo "Part " . $part['PartNumber'] . ": " . $part['Size'] . " bytes\n";
}
```

---

#### `selectObjectContent()`

Performs the SQL operation on your data stored in S3.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `Expression` (**string**, required) – SQL expression
- `ExpressionType` (**string**, required) – `SQL` or `DQL`
- `InputSerialization` (**array**, required) – Input format configuration
- `OutputSerialization` (**array**, required) – Output format configuration

**Output** ([`Aws\Result`](#result-structure)):
- `Payload` (**StreamInterface**) – Event stream containing results

**Example**:
```php
$result = $s3->selectObjectContent([
    'Bucket' => 'my-bucket',
    'Key' => 'data.csv',
    'Expression' => "SELECT * FROM s3object s WHERE s.column1 = 'value'",
    'ExpressionType' => 'SQL',
    'InputSerialization' => [
        'CSV' => ['FileHeaderInfo' => 'USE']
    ],
    'OutputSerialization' => [
        'CSV' => []
    ]
]);

foreach ($result['Payload'] as $event) {
    if (isset($event['Records'])) {
        echo $event['Records']['Payload'] . "\n";
    }
}
```

---

#### `getObjectTagging()`

Returns the tag-set of an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key

**Output** ([`Aws\Result`](#result-structure)):
- `TagSet` (**array**) – Array of tag key-value pairs
  - `Key` (**string**) – Tag key
  - `Value` (**string**) – Tag value

**Example**:
```php
$result = $s3->getObjectTagging([
    'Bucket' => 'my-bucket',
    'Key' => 'file.txt'
]);

foreach ($result['TagSet'] as $tag) {
    echo $tag['Key'] . ": " . $tag['Value'] . "\n";
}
```

---

#### `putObjectTagging()`

Sets the supplied tag-set to an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `Tagging` (**array**, required)
  - `TagSet` (**array**) – Array of tags
    - `Key` (**string**) – Tag key
    - `Value` (**string**) – Tag value

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putObjectTagging([
    'Bucket' => 'my-bucket',
    'Key' => 'file.txt',
    'Tagging' => [
        'TagSet' => [
            ['Key' => 'Environment', 'Value' => 'Production'],
            ['Key' => 'Owner', 'Value' => 'TeamA']
        ]
    ]
]);
echo "Tags applied";
```

---

#### `deleteObjectTagging()`

Removes the tag-set from an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deleteObjectTagging([
    'Bucket' => 'my-bucket',
    'Key' => 'file.txt'
]);
echo "Tags removed";
```

---

#### `getObjectRetention()`

Returns the retention settings for an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key

**Output** ([`Aws\Result`](#result-structure)):
- Retention configuration

**Example**:
```php
$result = $s3->getObjectRetention(['Bucket' => 'my-bucket', 'Key' => 'file.txt']);
print_r($result);
```

---

#### `putObjectRetention()`

Applies a retention configuration to an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `Retention` (**array**, required)
  - `RetainUntilDate` (**string**) – Retention expiration date (ISO 8601)
  - `Mode` (**string**) – `GOVERNANCE` or `COMPLIANCE`

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putObjectRetention([
    'Bucket' => 'my-bucket',
    'Key' => 'file.txt',
    'Retention' => [
        'Mode' => 'GOVERNANCE',
        'RetainUntilDate' => '2025-12-31T23:59:59Z'
    ]
]);
echo "Retention applied";
```

---

#### `getObjectLegalHold()`

Returns the legal hold information for an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key

**Output** ([`Aws\Result`](#result-structure)):
- `LegalHold` (**array**) – Legal hold status
  - `Status` (**string**) – `ON` or `OFF`

**Example**:
```php
$result = $s3->getObjectLegalHold(['Bucket' => 'my-bucket', 'Key' => 'file.txt']);
echo "Legal hold: " . $result['LegalHold']['Status'] . "\n";
```

---

#### `putObjectLegalHold()`

Applies a legal hold configuration to an object.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key
- `LegalHold` (**array**, required)
  - `Status` (**string**) – `ON` or `OFF`

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putObjectLegalHold([
    'Bucket' => 'my-bucket',
    'Key' => 'file.txt',
    'LegalHold' => ['Status' => 'ON']
]);
echo "Legal hold enabled";
```

---

#### `getBucketEncryption()`

Returns the default encryption configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `ServerSideEncryptionConfiguration` (**array**) – Encryption configuration

**Example**:
```php
$result = $s3->getBucketEncryption(['Bucket' => 'my-bucket']);
print_r($result['ServerSideEncryptionConfiguration']);
```

---

#### `putBucketEncryption()`

Creates a new default encryption configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `ServerSideEncryptionConfiguration` (**array**, required)
  - `Rules` (**array**) – Array of encryption rules
    - `ApplyServerSideEncryptionByDefault` (**array**) – Default encryption settings
      - `SSEAlgorithm` (**string**) – `AES256` or `aws:kms`
      - `KMSMasterKeyID` (**string**, optional) – KMS key ID

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketEncryption([
    'Bucket' => 'my-bucket',
    'ServerSideEncryptionConfiguration' => [
        'Rules' => [
            [
                'ApplyServerSideEncryptionByDefault' => [
                    'SSEAlgorithm' => 'AES256'
                ]
            ]
        ]
    ]
]);
echo "Default encryption enabled";
```

---

#### `deleteBucketEncryption()`

Removes the default encryption configuration from a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deleteBucketEncryption(['Bucket' => 'my-bucket']);
echo "Default encryption removed";
```

---

#### `getPublicAccessBlock()`

Returns the Public Access Block configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `PublicAccessBlockConfiguration` (**array**) – Configuration settings

**Example**:
```php
$result = $s3->getPublicAccessBlock(['Bucket' => 'my-bucket']);
print_r($result['PublicAccessBlockConfiguration']);
```

---

#### `putPublicAccessBlock()`

Creates or modifies the Public Access Block configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `PublicAccessBlockConfiguration` (**array**, required)
  - `BlockPublicAcls` (**bool**) – Block public ACLs
  - `IgnorePublicAcls` (**bool**) – Ignore public ACLs
  - `BlockPublicPolicy` (**bool**) – Block public policies
  - `RestrictPublicBuckets` (**bool**) – Restrict public buckets

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putPublicAccessBlock([
    'Bucket' => 'my-bucket',
    'PublicAccessBlockConfiguration' => [
        'BlockPublicAcls' => true,
        'IgnorePublicAcls' => true,
        'BlockPublicPolicy' => true,
        'RestrictPublicBuckets' => true
    ]
]);
echo "Public access blocked";
```

---

#### `deletePublicAccessBlock()`

Removes the Public Access Block configuration from a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->deletePublicAccessBlock(['Bucket' => 'my-bucket']);
echo "Public access block removed";
```

---

#### `getBucketRequestPayment()`

Returns the request payment configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `Payer` (**string**) – `Requester` or `BucketOwner`

**Example**:
```php
$result = $s3->getBucketRequestPayment(['Bucket' => 'my-bucket']);
echo "Payer: " . $result['Payer'] . "\n";
```

---

#### `putBucketRequestPayment()`

Sets the request payment configuration for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `RequestPaymentConfiguration` (**array**, required)
  - `Payer` (**string**) – `Requester` or `BucketOwner`

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketRequestPayment([
    'Bucket' => 'my-bucket',
    'RequestPaymentConfiguration' => ['Payer' => 'Requester']
]);
echo "Request payment set to Requester";
```

---

#### `getBucketLogging()`

Returns the logging status of a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- `BucketLoggingStatus` (**array**) – Logging configuration

**Example**:
```php
$result = $s3->getBucketLogging(['Bucket' => 'my-bucket']);
print_r($result['BucketLoggingStatus']);
```

---

#### `putBucketLogging()`

Sets the logging parameters for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `BucketLoggingStatus` (**array**, required)
  - `LoggingEnabled` (**array**) – Logging settings
    - `TargetBucket` (**string**) – Target bucket for logs
    - `TargetPrefix` (**string**) – Log file prefix

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketLogging([
    'Bucket' => 'my-bucket',
    'BucketLoggingStatus' => [
        'LoggingEnabled' => [
            'TargetBucket' => 'my-logs-bucket',
            'TargetPrefix' => 'access-logs/'
        ]
    ]
]);
echo "Logging enabled";
```

---

#### `getBucketNotificationConfiguration()`

Returns the notification configuration of a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name

**Output** ([`Aws\Result`](#result-structure)):
- Notification configuration

**Example**:
```php
$result = $s3->getBucketNotificationConfiguration(['Bucket' => 'my-bucket']);
print_r($result);
```

---

#### `putBucketNotificationConfiguration()`

Enables notifications of specified events for a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `NotificationConfiguration` (**array**, required)
  - `TopicConfigurations` (**array**) – SNS topic configurations
  - `QueueConfigurations` (**array**) – SQS queue configurations
  - `LambdaFunctionConfigurations` (**array**) – Lambda function configurations

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->putBucketNotificationConfiguration([
    'Bucket' => 'my-bucket',
    'NotificationConfiguration' => [
        'TopicConfigurations' => [
            [
                'TopicArn' => 'arn:aws:sns:us-east-1:123456789012:my-topic',
                'Events' => ['s3:ObjectCreated:*']
            ]
        ]
    ]
]);
echo "Notification configuration set";
```

---

#### `deleteObjects()`

Enables you to delete multiple objects from a bucket in a single request.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Delete` (**array**, required)
  - `Objects` (**array**) – Array of objects to delete
    - `Key` (**string**) – Object key
    - `VersionId` (**string**, optional) – Version to delete
  - `Quiet` (**bool**, optional) – If true, only returns deleted objects

**Output** ([`Aws\Result`](#result-structure)):
- `Deleted` (**array**) – Array of deleted objects
- `Errors` (**array**) – Array of errors

**Example**:
```php
$s3->deleteObjects([
    'Bucket' => 'my-bucket',
    'Delete' => [
        'Objects' => [
            ['Key' => 'file1.txt'],
            ['Key' => 'file2.txt'],
            ['Key' => 'file3.txt']
        ],
        'Quiet' => true
    ]
]);
echo "Objects deleted";
```

---

#### `getObjectTorrent()`

Return torrent files from a bucket.

**Input** (`array`):
- `Bucket` (**string**, required) – The bucket name
- `Key` (**string**, required) – The object key

**Output** ([`Aws\Result`](#result-structure)):
- `Body` (**StreamInterface**) – The torrent file content

**Example**:
```php
$result = $s3->getObjectTorrent([
    'Bucket' => 'my-bucket',
    'Key' => 'file.iso.torrent'
]);

$torrentContent = $result['Body']->getContents();
file_put_contents('downloaded.torrent', $torrentContent);
```

---

#### `writeGetObjectResponse()`

Writes an object using a multipart upload (used with S3 Select).

**Input** (`array`):
- `RequestId` (**string**, required) – The request ID
- `Body` (**string\|resource\|StreamInterface**, required) – The object data
- `SSEKMSEncryptionContext` (**string**, optional) – KMS encryption context

**Output** ([`Aws\Result`](#result-structure)):
- *(empty result on success)*

**Example**:
```php
$s3->writeGetObjectResponse([
    'RequestId' => 'request-id-123',
    'Body' => 'response data'
]);
echo "Response written";
```

---

### Result Structure

All S3Template methods return an [`Aws\Result`](https://docs.aws.amazon.com/sdk-for-php/v3/api/class-Aws.Result.html) object that acts like an associative array. You can access results using array syntax:

```php
$result = $s3->getObject(['Bucket' => 'my-bucket', 'Key' => 'file.txt']);

// Access as array
echo $result['ContentLength'];
echo $result['ContentType'];

// Access as object
echo $result->get('ContentLength');
echo $result->get('ContentType');
```

The `Body` key contains a [`StreamInterface`](https://docs.aws.amazon.com/sdk-for-php/v3/api/class-GuzzleHttp.Psr7.StreamInterface.html) that you can read from:

```php
$body = $result['Body'];
$content = $body->getContents();  // Read entire content
$first100 = $body->read(100);     // Read first 100 bytes
```

---

### Full Method List

For the complete list of all available methods, see the official AWS SDK for PHP S3 client documentation:

[https://docs.aws.amazon.com/sdk-for-php/v3/api/api-s3-2016-03-01.html](https://docs.aws.amazon.com/sdk-for-php/v3/api/api-s3-2016-03-01.html)

The S3Template supports all methods from the AWS SDK S3Client, including both synchronous methods (e.g., `putObject()`) and asynchronous methods (e.g., `putObjectAsync()`).
