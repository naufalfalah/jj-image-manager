<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\S3Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DomainController extends Controller
{
    protected $s3Service;

    public function __construct(S3Service $s3Service)
    {
        $this->s3Service = $s3Service;
    }

    public function index()
    {
        $domains = Domain::withCount('images')->get();

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

        $domainName = formatBucketName($request->input('name'));

        if (! $this->s3Service->checkBucketExists($domainName)) {
            $this->s3Service->createBucket($domainName);
        }

        $domain = Domain::create([
            'name' => $domainName,
            'status' => 'active',
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

        $oldBucketName = $domain->name;
        $newBucketName = formatBucketName($request->input('name'));
        if ($oldBucketName !== $newBucketName) {
            $this->s3Service->copyAllObject($oldBucketName, $newBucketName);
            $this->s3Service->deleteBucket($oldBucketName);
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

        if ($this->s3Service->checkBucketExists($domain->name)) {
            $this->s3Service->deleteBucket($domain->name);
        }

        $domain->images()->delete();
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

        $newDomainName = formatBucketName($request->input('name'));

        if (! $this->s3Service->checkBucketExists($newDomainName)) {
            $this->s3Service->createBucket($newDomainName);
        }

        $this->s3Service->copyAllObject($domain->name, $newDomainName);

        DB::beginTransaction();
        try {
            // Clone the domain
            $newDomain = $domain->replicate();
            $newDomain->name = $newDomainName;
            $newDomain->push();

            // Clone the images
            foreach ($domain->images as $image) {
                $newImage = $image->replicate();
                $newImage->fill([
                    'domain_id' => $newDomain->id,
                    'url' => getFileUrl($newDomain->name, $image->name),
                    'thumbnail' => getFileUrl($newDomain->name, $image->name),
                ])->save();
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
}
