<?php

namespace App\Services;

use Aws\S3\S3Client;

class S3Service
{
    protected $s3;

    public function __construct()
    {
        $this->s3 = new S3Client([
            'version' => 'latest',
            'region' => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);
    }

    public function checkBucketExists($bucketName)
    {
        try {
            return $this->s3->doesBucketExist($bucketName);
        } catch (\Exception $e) {
            throw new \Exception('Failed to check if bucket exists: '.$e->getMessage());
        }
    }

    public function createBucket($bucketName)
    {
        try {
            $this->s3->createBucket(['Bucket' => $bucketName]);
            $this->s3->putPublicAccessBlock([
                'Bucket' => $bucketName,
                'PublicAccessBlockConfiguration' => [
                    'BlockPublicAcls' => false,
                    'IgnorePublicAcls' => false,
                    'BlockPublicPolicy' => false,
                    'RestrictPublicBuckets' => false,
                ],
            ]);

            // Set bucket policy to allow public read access
            $publicPolicy = [
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Sid' => 'AllowPublicRead',
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => 's3:GetObject',
                        'Resource' => "arn:aws:s3:::$bucketName/*",
                    ],
                ],
            ];
            $this->s3->putBucketPolicy([
                'Bucket' => $bucketName,
                'Policy' => json_encode($publicPolicy),
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Failed to create bucket: '.$e->getMessage());
        }
    }

    public function deleteBucket($bucketName)
    {
        try {
            // Delete all objects in the bucket
            $objects = $this->s3->listObjects(['Bucket' => $bucketName]);
            if ($objects['Contents']) {
                $this->s3->deleteObjects([
                    'Bucket' => $bucketName,
                    'Delete' => [
                        'Objects' => array_map(function ($object) {
                            return ['Key' => $object['Key']];
                        }, $objects['Contents']),
                    ],
                ]);
            }

            // Delete the bucket
            $this->s3->deleteBucket(['Bucket' => $bucketName]);
        } catch (\Exception $e) {
            throw new \Exception('Failed to delete bucket: '.$e->getMessage());
        }
    }

    public function getListOfBuckets()
    {
        try {
            $buckets = $this->s3->listBuckets();

            return array_map(function ($bucket) {
                return $bucket['Name'];
            }, $buckets['Buckets']);
        } catch (\Exception $e) {
            throw new \Exception('Failed to list buckets: '.$e->getMessage());
        }
    }

    public function checkObjectExists($bucketName, $key)
    {
        try {
            return $this->s3->doesObjectExist($bucketName, $key);
        } catch (\Exception $e) {
            throw new \Exception('Failed to check if object exists: '.$e->getMessage());
        }
    }

    public function getFileUrl($bucketName, $key)
    {
        try {
            return $this->s3->getObjectUrl($bucketName, $key);
        } catch (\Exception $e) {
            throw new \Exception('Failed to get file URL: '.$e->getMessage());
        }
    }

    public function uploadFile($bucketName, $key, $filePath, $contentType)
    {
        try {
            $result = $this->s3->putObject([
                'Bucket' => $bucketName,
                'Key' => $key,
                'Body' => fopen($filePath, 'r'),
                'ContentType' => $contentType,
            ]);

            return $result['ObjectURL'];
        } catch (\Exception $e) {
            throw new \Exception('Failed to upload file to S3: '.$e->getMessage());
        }
    }

    public function copyObject($sourceBucket, $sourceKey, $destinationBucket, $destinationKey)
    {
        try {
            $result = $this->s3->copyObject([
                'Bucket' => $destinationBucket,
                'CopySource' => "{$sourceBucket}/{$sourceKey}",
                'Key' => $destinationKey,
            ]);

            return $result['ObjectURL'];
        } catch (\Exception $e) {
            throw new \Exception('Failed to copy object: '.$e->getMessage());
        }
    }

    public function copyAllObject($oldBucketName, $newBucketName)
    {
        try {
            $objects = $this->s3->listObjectsV2(['Bucket' => $oldBucketName]);
            if (! empty($objects['Contents'])) {
                foreach ($objects['Contents'] as $obj) {
                    $this->s3->copyObject([
                        'Bucket' => $newBucketName,
                        'Key' => $obj['Key'],
                        'CopySource' => "{$oldBucketName}/{$obj['Key']}",
                    ]);
                }
            }
        } catch (\Exception $e) {
            throw new \Exception('Failed to copy object: '.$e->getMessage());
        }
    }

    public function deleteObject($bucketName, $key)
    {
        try {
            $this->s3->deleteObject([
                'Bucket' => $bucketName,
                'Key' => $key,
            ]);
        } catch (\Exception $e) {
            throw new \Exception('Failed to delete object: '.$e->getMessage());
        }
    }
}
