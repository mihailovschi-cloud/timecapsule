<?php
// File storage for capsule attachments.
//
// This is the only file that reads or writes uploaded files. They are kept in an
// Amazon S3 bucket (S3_BUCKET) under the "uploads/" prefix. The rest of the app only uses
// the three functions storage_save(), storage_delete() and storage_stream().
//
// The server has no access keys: the AWS SDK gets temporary credentials of the EC2 role
// (timecapsule-dev-app-role) from the instance metadata service.

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// One S3 client for the whole request.
function s3_client(): S3Client
{
    static $client = null;
    if ($client === null) {
        $client = new S3Client([
            'region' => config('AWS_REGION', 'eu-central-1'),
            'version' => 'latest',
        ]);
    }
    return $client;
}

function s3_bucket(): string
{
    $bucket = config('S3_BUCKET');
    if ($bucket === null) {
        throw new RuntimeException('S3_BUCKET is not set.');
    }
    return $bucket;
}

// Object key of a stored file. basename() makes sure the key stays under "uploads/".
function s3_key(string $storedName): string
{
    return 'uploads/' . basename($storedName);
}

// Save an uploaded file ($_FILES['...'] entry) and return its stored name, e.g. "3f9a...e1.png".
// The name is random, so users cannot guess other people's file names.
function storage_save(array $uploadedFile, string $extension): string
{
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
    $contentType = mime_content_type($uploadedFile['tmp_name']) ?: 'application/octet-stream';

    try {
        s3_client()->putObject([
            'Bucket' => s3_bucket(),
            'Key' => s3_key($storedName),
            'SourceFile' => $uploadedFile['tmp_name'],
            'ContentType' => $contentType,
        ]);
    } catch (AwsException $e) {
        error_log((string) $e);
        throw new RuntimeException('Could not save the uploaded file.');
    }
    return $storedName;
}

function storage_delete(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }

    // With versioning enabled this only adds a delete marker; old versions stay in the bucket.
    s3_client()->deleteObject([
        'Bucket' => s3_bucket(),
        'Key' => s3_key($storedName),
    ]);
}

// Send the browser to the stored file: a 302 redirect to a presigned URL valid for 5 minutes.
// $disposition: "inline" (show in the browser) or "attachment" (download).
function storage_stream(string $storedName, string $mime, string $downloadName, string $disposition): void
{
    // Keep only safe characters for the plain filename; the UTF-8 name goes in filename*.
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);

    $client = s3_client();
    $command = $client->getCommand('GetObject', [
        'Bucket' => s3_bucket(),
        'Key' => s3_key($storedName),
        'ResponseContentType' => $mime,
        'ResponseContentDisposition' => sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $asciiName,
            rawurlencode($downloadName)
        ),
    ]);
    $url = (string) $client->createPresignedRequest($command, '+5 minutes')->getUri();

    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
}
