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
            'region'  => env('AWS_DEFAULT_REGION'),
            'credentials' => [
                'key'    => env('AWS_ACCESS_KEY_ID'),
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
            'name' => 'required|string|max:255|unique:domains,name',
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
                'error' => 'Failed to create S3 bucket: ' . $e->getMessage()
            ], 500);
        }

        $publicPolicy = [
            "Version" => "2012-10-17",
            "Statement" => [
                [
                    "Sid" => "AllowPublicRead",
                    "Effect" => "Allow",
                    "Principal" => "*",
                    "Action" => "s3:GetObject",
                    "Resource" => "arn:aws:s3:::$bucketName/*"
                ]
            ]
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

    public function destroy($id)
    {
        $domain = Domain::find($id);
        if (!$domain) {
            return response()->json([
                'error' => 'Domain not found'
            ], 404);
        }
        $bucketName = strtolower($domain->name);

        // Delete the S3 bucket
        try {
            $this->s3->deleteBucket(['Bucket' => $bucketName]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete S3 bucket: ' . $e->getMessage()
            ], 500);
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
}
