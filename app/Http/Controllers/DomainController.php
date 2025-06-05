<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use Aws\S3\S3Client;
use Illuminate\Http\Request;

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

        $domainName = $request->input('name');
        $domain = new Domain;
        $domain->name = $domainName;
        $domain->status = 'active';
        $domain->save();

        $bucketName = strtolower($domainName);

        // Create the S3 bucket
        try {
            $this->s3->createBucket(['Bucket' => $bucketName]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create S3 bucket: '.$e->getMessage(),
            ], 500);
        }

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
    
        return response()->json([
            'success' => true,
            'message' => 'Domain updated successfully',
            'domain' => $domain,
        ]);
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

    public function migrateBucketAndImages($domainId, $newBucketName)
    {
        $domain = Domain::find($domainId);
        if (!$domain) {
            return response()->json(['error' => 'Domain not found'], 404);
        }

        $oldBucket = strtolower($domain->name);
        $newBucket = strtolower($newBucketName);

        // 1. Buat bucket baru
        try {
            $this->s3->createBucket(['Bucket' => $newBucket]);
            $this->s3->putPublicAccessBlock([
                'Bucket' => $newBucket,
                'PublicAccessBlockConfiguration' => [
                    'BlockPublicAcls'       => false,
                    'IgnorePublicAcls'      => false,
                    'BlockPublicPolicy'     => false,
                    'RestrictPublicBuckets' => false,
                ],
            ]);
            $publicPolicy = [
                "Version" => "2012-10-17",
                "Statement" => [
                    [
                        "Sid" => "AllowPublicRead",
                        "Effect" => "Allow",
                        "Principal" => "*",
                        "Action" => "s3:GetObject",
                        "Resource" => "arn:aws:s3:::$newBucket/*"
                    ]
                ]
            ];
            $this->s3->putBucketPolicy([
                'Bucket' => $newBucket,
                'Policy' => json_encode($publicPolicy),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to create new bucket: ' . $e->getMessage()], 500);
        }

        // 2. Upload semua gambar dari folder lokal ke bucket baru
        $images = $domain->images;
        foreach ($images as $image) {
            // Ambil path file lokal (misal: storage/app/images/namafile.jpg)
            $localPath = storage_path('app/images/' . $image->name);
            if (!File::exists($localPath)) continue;

            try {
                $result = $this->s3->putObject([
                    'Bucket' => $newBucket,
                    'Key'    => 'images/' . $image->name,
                    'Body'   => fopen($localPath, 'r'),
                    'ContentType' => File::mimeType($localPath),
                ]);
                // Update URL di database jika perlu
                $image->url = $result['ObjectURL'];
                $image->save();
            } catch (\Exception $e) {
                // Optional: log error, lanjutkan ke file berikutnya
            }
        }

        // 3. Hapus bucket lama beserta seluruh isinya
        try {
            // List objects in old bucket
            $objects = $this->s3->listObjectsV2(['Bucket' => $oldBucket]);
            if (!empty($objects['Contents'])) {
                foreach ($objects['Contents'] as $obj) {
                    $this->s3->deleteObject([
                        'Bucket' => $oldBucket,
                        'Key'    => $obj['Key'],
                    ]);
                }
            }
            $this->s3->deleteBucket(['Bucket' => $oldBucket]);
        } catch (\Exception $e) {
            // Optional: log error
        }

        // Update nama domain di database
        $domain->name = $newBucketName;
        $domain->save();

        return response()->json([
            'success' => true,
            'message' => 'Bucket migrated and images uploaded successfully',
            'domain' => $domain,
        ]);
    }
}
