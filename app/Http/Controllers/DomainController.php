<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DomainController extends Controller
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

    public function index()
    {
        $domains = Domain::all();
        foreach ($domains as $domain) {
            $domain->image_count = $domain->images()->count();
        }

        return response()->json([
            'success' => true,
            'domains' => $domains,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|min:4|max:60|unique:domains,name',
        ]);

        // Replace dots with dashes in the domain name
        $domainName = str_replace('.', '-', $request->input('name'));
        $domainName = strtolower($domainName);

        $bucketName = $domainName;
        // Check if the bucket already exists
        if ($this->s3->doesBucketExist($bucketName)) {
            return response()->json([
                'error' => 'S3 bucket already exists for this domain',
            ], 400);
        }

        // Create the S3 bucket
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
            return response()->json([
                'error' => 'Failed to create S3 bucket: '.$e->getMessage(),
            ], 500);
        }

        $domain = new Domain;
        $domain->name = $domainName;
        $domain->status = 'active';
        $domain->save();

        return response()->json([
            'success' => true,
            'message' => 'Domain added successfully',
            'domain' => $domain,
        ]);
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|min:4|max:60|unique:domains,name,'.$id,
        ]);

        $domain = Domain::find($id);
        if (! $domain) {
            return response()->json([
                'error' => 'Domain not found',
            ], 404);
        }

        $oldBucketName = strtolower($domain->name);
        $newBucketName = str_replace('.', '-', $request->input('name'));
        if ($oldBucketName !== $newBucketName) {
            $this->migrateBucketAndImages($oldBucketName, $newBucketName);
            $this->destoryBucketAndImages($oldBucketName);
        }

        DB::beginTransaction();
        try {
            $domain->name = $newBucketName;
            $domain->save();

            foreach ($domain->images as $image) {
                $image->url = "https://{$newBucketName}.s3.amazonaws.com/images/{$image->name}";
                $image->thumbnail = "https://{$newBucketName}.s3.amazonaws.com/images/{$image->name}";
                $image->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Domain updated successfully',
                'domain' => $domain,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to update domain: '.$e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $domain = Domain::find($id);
        if (! $domain) {
            return response()->json([
                'error' => 'Domain not found',
            ], 404);
        }
        $bucketName = strtolower($domain->name);

        $bucket = $this->s3->doesBucketExist($bucketName);
        if ($bucket) {
            // Delete the S3 bucket
            try {
                $this->s3->deleteBucket(['Bucket' => $bucketName]);
            } catch (\Exception $e) {
                return response()->json([
                    'error' => 'Failed to delete S3 bucket: '.$e->getMessage(),
                ], 500);
            }
        }

        foreach ($domain->images as $image) {
            $image->delete();
        }

        $domain->delete();

        return response()->json([
            'success' => true,
            'message' => 'Domain deleted successfully',
        ]);
    }

    public function clone(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|min:4|max:60|unique:domains,name',
        ]);

        $domain = Domain::find($id);
        if (! $domain) {
            return response()->json([
                'error' => 'Domain not found',
            ], 404);
        }

        $newDomainName = str_replace('.', '-', $request->input('name'));
        $newDomainName = strtolower($newDomainName);

        $newBucketName = $newDomainName;
        // Check if the new bucket already exists
        if ($this->s3->doesBucketExist($newBucketName)) {
            return response()->json([
                'error' => 'S3 bucket already exists for this domain',
            ], 400);
        }

        // Create the S3 bucket
        try {
            $this->s3->createBucket(['Bucket' => $newBucketName]);
            $this->s3->putPublicAccessBlock([
                'Bucket' => $newBucketName,
                'PublicAccessBlockConfiguration' => [
                    'BlockPublicAcls' => false,
                    'IgnorePublicAcls' => false,
                    'BlockPublicPolicy' => false,
                    'RestrictPublicBuckets' => false,
                ],
            ]);
            $publicPolicy = [
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Sid' => 'AllowPublicRead',
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => 's3:GetObject',
                        'Resource' => "arn:aws:s3:::$newBucketName/*",
                    ],
                ],
            ];
            $this->s3->putBucketPolicy([
                'Bucket' => $newBucketName,
                'Policy' => json_encode($publicPolicy),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create S3 bucket: '.$e->getMessage(),
            ], 500);
        }

        $oldBucketName = strtolower($domain->name);
        $this->migrateBucketAndImages($oldBucketName, $newBucketName);

        DB::beginTransaction();
        try {
            // Clone the domain
            $newDomain = $domain->replicate();
            $newDomain->name = $newDomainName;
            $newDomain->save();

            // Clone the images
            foreach ($domain->images as $image) {
                $newImage = $image->replicate();
                $newImage->url = "https://{$newBucketName}.s3.amazonaws.com/images/{$image->name}";
                $newImage->thumbnail = "https://{$newBucketName}.s3.amazonaws.com/images/{$image->name}";
                $newImage->domain_id = $newDomain->id;
                $newImage->save();
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to clone domain: '.$e->getMessage(),
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Domain copied successfully',
            'domain' => $newDomain,
        ]);
    }

    public function migrateBucketAndImages($oldBucketName, $newBucketName)
    {
        try {
            // Check if the old bucket exists
            if (! $this->s3->doesBucketExist($oldBucketName)) {
                throw new \Exception("Old bucket does not exist: {$oldBucketName}");
            }

            // Create the new bucket
            if (! $this->s3->doesBucketExist($newBucketName)) {
                $this->s3->createBucket(['Bucket' => $newBucketName]);
                $this->s3->putPublicAccessBlock([
                    'Bucket' => $newBucketName,
                    'PublicAccessBlockConfiguration' => [
                        'BlockPublicAcls' => false,
                        'IgnorePublicAcls' => false,
                        'BlockPublicPolicy' => false,
                        'RestrictPublicBuckets' => false,
                    ],
                ]);
            }

            $publicPolicy = [
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Sid' => 'AllowPublicRead',
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => 's3:GetObject',
                        'Resource' => "arn:aws:s3:::$newBucketName/*",
                    ],
                ],
            ];
            $this->s3->putBucketPolicy([
                'Bucket' => $newBucketName,
                'Policy' => json_encode($publicPolicy),
            ]);

            // Copy objects from the old bucket to the new bucket
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

            Log::info("Successfully migrated images from {$oldBucketName} to {$newBucketName}");

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to copy images to new bucket: '.$e->getMessage());

            return false;
        }
    }

    public function destoryBucketAndImages($bucketName)
    {
        try {
            // Truncate the old bucket (delete all objects)
            $objects = $this->s3->listObjectsV2(['Bucket' => $bucketName, 'Prefix' => '']);
            if (! empty($objects['Contents'])) {
                foreach ($objects['Contents'] as $obj) {
                    $this->s3->deleteObject([
                        'Bucket' => $bucketName,
                        'Key' => $obj['Key'],
                    ]);
                }
            }

            // Delete the old bucket
            $this->s3->deleteBucket(['Bucket' => $bucketName]);
            Log::info("Successfully deleted bucket: {$bucketName}");

            return true;
        } catch (\Exception $e) {

            return false;
        }
    }
}
