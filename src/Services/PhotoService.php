<?php
/**
 * Photo Upload Service
 * Handles secure photo uploads with validation, resizing, and storage
 */

namespace RadioChatBox\Services;

use Pramnos\Cache\FlatCache;
use Pramnos\Database\Database as PramnosDatabase;

class PhotoService
{
    private PramnosDatabase $db;
    private string $uploadDir;
    private int $maxFileSize; // bytes
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp'
    ];
    private array $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private int $maxWidth = 1920;
    private int $maxHeight = 1080;
    private int $thumbnailSize = 300;
    
    private const CACHE_TTL_SETTINGS = 3600; // 1 hour
    private const CACHE_TTL_ATTACHMENT = 7200; // 2 hours
    private const CACHE_TTL_USER_ATTACHMENTS = 300; // 5 minutes

    public function __construct()
    {
        $this->db = PramnosDatabase::getInstance();
        $this->uploadDir = __DIR__ . '/../../public/uploads/photos';
        
        // Get max size from settings (default 5MB)
        $maxSizeMB = $this->getSetting('max_photo_size_mb', 5);
        $this->maxFileSize = $maxSizeMB * 1024 * 1024;
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
            if (!is_dir($this->uploadDir)) {
                \Pramnos\Logs\Logger::log("Failed to create upload directory: {$this->uploadDir}", 'radiochatbox');
            }
        }
    }

    /**
     * Check if photo uploads are enabled
     */
    public function isEnabled(): bool
    {
        return $this->getSetting('allow_photo_uploads', 'true') === 'true';
    }

    /**
     * Upload and process a photo
     */
    public function uploadPhoto(array $file, string $username, string $recipient, string $ipAddress): array
    {
        // Check if uploads are enabled
        if (!$this->isEnabled()) {
            throw new \RuntimeException('Photo uploads are disabled');
        }

        // Validate file
        $this->validateFile($file);
        
        // Check file upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->getUploadErrorMessage($file['error']));
        }

        // Verify actual file type (not just extension)
        $imageInfo = \getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new \RuntimeException('File is not a valid image');
        }

        $mimeType = $imageInfo['mime'];
        if (!in_array($mimeType, $this->allowedMimeTypes)) {
            throw new \RuntimeException('Invalid image type. Allowed: JPG, PNG, GIF, WebP');
        }

        // Check dimensions
        $width = $imageInfo[0];
        $height = $imageInfo[1];
        
        // Generate unique filename
        $attachmentId = 'att_' . uniqid('', true);
        $extension = $this->getExtensionFromMime($mimeType);
        $filename = $attachmentId . '.' . $extension;
        $filePath = $this->uploadDir . '/' . $filename;

        // Load and resize image if needed
        $image = $this->loadImage($file['tmp_name'], $mimeType);
        if ($width > $this->maxWidth || $height > $this->maxHeight) {
            $image = $this->resizeImage($image, $width, $height, $this->maxWidth, $this->maxHeight);
            $width = \imagesx($image);
            $height = \imagesy($image);
        }

        // Save optimized image
        $this->saveImage($image, $filePath, $mimeType);
        \imagedestroy($image);

        // Get final file size
        $fileSize = filesize($filePath);
        
        if ($fileSize === false) {
            throw new \RuntimeException('Failed to save image file');
        }

        // Save to database
        $this->db->queryBuilder()->from('attachments')->insert([
            'attachment_id'     => $attachmentId,
            'filename'          => $filename,
            'original_filename' => $file['name'],
            'file_path'         => '/uploads/photos/' . $filename,
            'file_size'         => $fileSize,
            'mime_type'         => $mimeType,
            'width'             => $width,
            'height'            => $height,
            'uploaded_by'       => $username,
            'recipient'         => $recipient,
            'ip_address'        => $ipAddress,
        ]);

        // Invalidate user cache
        FlatCache::default()->delete("user_attachments:{$username}");

        return [
            'attachment_id' => $attachmentId,
            'filename' => $filename,
            'file_path' => '/uploads/photos/' . $filename,
            'file_size' => $fileSize,
            'width' => $width,
            'height' => $height,
            'mime_type' => $mimeType
        ];
    }

    /**
     * Get attachment by ID (with Redis caching)
     */
    public function getAttachment(string $attachmentId): ?array
    {
        $cacheKey = "attachment:{$attachmentId}";

        // Try cache first (FlatCache applies the prefix + serialisation).
        $cached = FlatCache::default()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        
        // Query database
        $row = $this->db->queryBuilder()
            ->from('attachments')
            ->where('attachment_id', '=', $attachmentId)
            ->whereRaw('is_deleted = FALSE')
            ->first();
        $result = ($row && $row->numRows > 0) ? $row->fields : null;

        if ($result) {
            // Cache the result
            FlatCache::default()->set($cacheKey, $result, self::CACHE_TTL_ATTACHMENT);
            return $result;
        }
        
        return null;
    }

    /**
     * Get all attachments by user (with Redis caching)
     */
    public function getAttachmentsByUser(string $username): array
    {
        $cacheKey = "user_attachments:{$username}";

        // Try cache first.
        $cached = FlatCache::default()->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
        
        // Query database
        $result = $this->db->queryBuilder()
            ->from('attachments')
            ->where('uploaded_by', '=', $username)
            ->whereRaw('is_deleted = FALSE')
            ->orderBy('uploaded_at', 'desc')
            ->getAll();

        // Cache the result
        FlatCache::default()->set($cacheKey, $result, self::CACHE_TTL_USER_ATTACHMENTS);
        
        return $result;
    }

    /**
     * Get all attachments (for admin)
     */
    public function getAllAttachments(int $limit = 100, int $offset = 0, bool $includeDeleted = false): array
    {
        $qb = $this->db->queryBuilder()->from('attachments');
        if (!$includeDeleted) {
            $qb->whereRaw('is_deleted = FALSE');
        }
        return $qb->orderBy('uploaded_at', 'desc')
            ->limit($limit)
            ->offset($offset)
            ->getAll();
    }

    /**
     * Get total count of attachments (for admin pagination)
     */
    public function getTotalAttachmentsCount(bool $includeDeleted = false): int
    {
        $qb = $this->db->queryBuilder()->from('attachments');
        if (!$includeDeleted) {
            $qb->whereRaw('is_deleted = FALSE');
        }
        return $qb->count();
    }

    /**
     * Expire photos older than the retention window: mark them deleted but KEEP
     * the file on disk, so an admin can still review them (a "trash") until the
     * bin is emptied. Permanent removal is a deliberate manual step (emptyTrash).
     */
    public function cleanupExpiredPhotos(): int
    {
        $result = $this->db->queryBuilder()
            ->from('attachments')
            ->whereRaw('expires_at < NOW()')
            ->whereRaw('is_deleted = FALSE')
            ->returning('attachment_id')
            ->update(['is_deleted' => true]);
        $ids = $result ? array_column($result->fetchAll(), 'attachment_id') : [];

        foreach ($ids as $id) {
            FlatCache::default()->delete("attachment:{$id}");
        }

        $count = count($ids);
        if ($count > 0) {
            \Pramnos\Logs\Logger::log("Cleanup: soft-deleted {$count} expired photos (files kept until the trash is emptied)", 'radiochatbox');
        }

        return $count;
    }

    /**
     * Empty the trash: permanently remove every soft-deleted photo — unlink its
     * file and drop its row. This is the only place a photo file is deleted.
     */
    public function emptyTrash(): int
    {
        $trashed = $this->db->queryBuilder()
            ->from('attachments')
            ->select(['attachment_id', 'file_path'])
            ->whereRaw('is_deleted = TRUE')
            ->getAll();

        $count = 0;
        foreach ($trashed as $photo) {
            $fullPath = __DIR__ . '/../../public' . $photo['file_path'];
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
            $this->db->queryBuilder()
                ->from('attachments')
                ->where('attachment_id', '=', $photo['attachment_id'])
                ->delete();
            FlatCache::default()->delete("attachment:{$photo['attachment_id']}");
            $count++;
        }

        if ($count > 0) {
            \Pramnos\Logs\Logger::log("Emptied photo trash: permanently removed {$count} photos", 'radiochatbox');
        }

        return $count;
    }

    /**
     * Validate uploaded file
     */
    private function validateFile(array $file): void
    {
        if (!isset($file['tmp_name']) || !$this->isUploadedFile($file['tmp_name'])) {
            throw new \RuntimeException('No file uploaded');
        }

        if ($file['size'] > $this->maxFileSize) {
            $maxMB = $this->maxFileSize / (1024 * 1024);
            throw new \RuntimeException("File too large (max {$maxMB}MB)");
        }

        if ($file['size'] == 0) {
            throw new \RuntimeException('File is empty');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            throw new \RuntimeException('Invalid file extension');
        }
    }

    /**
     * Whether $path is a genuine HTTP file upload. Wraps is_uploaded_file() so the
     * upload pipeline stays testable: is_uploaded_file() is always false under
     * CLI/PHPUnit, so a test double overrides this to accept a real temp file.
     * Production behaviour is unchanged.
     */
    protected function isUploadedFile(string $path): bool
    {
        return is_uploaded_file($path);
    }

    /**
     * Load image from file
     */
    private function loadImage(string $path, string $mimeType)
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                return \imagecreatefromjpeg($path);
            case 'image/png':
                return \imagecreatefrompng($path);
            case 'image/gif':
                return \imagecreatefromgif($path);
            case 'image/webp':
                return \imagecreatefromwebp($path);
            default:
                throw new \RuntimeException('Unsupported image type');
        }
    }

    /**
     * Resize image maintaining aspect ratio
     */
    private function resizeImage($image, int $width, int $height, int $maxWidth, int $maxHeight)
    {
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = (int)($width * $ratio);
        $newHeight = (int)($height * $ratio);

        $resized = \imagecreatetruecolor($newWidth, $newHeight);
        
        // Preserve transparency for PNG and GIF
        \imagealphablending($resized, false);
        \imagesavealpha($resized, true);
        
        // Framework's quality resampler (multi-step downscaling for sharper
        // results; alpha-safe). Signature matches imagecopyresampled + quality.
        \Pramnos\Media\ResizeTools::fastimagecopyresampled(
            $resized, $image,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $width, $height
        );

        return $resized;
    }

    /**
     * Save image to file
     */
    private function saveImage($image, string $path, string $mimeType): void
    {
        switch ($mimeType) {
            case 'image/jpeg':
            case 'image/jpg':
                \imagejpeg($image, $path, 85); // 85% quality
                break;
            case 'image/png':
                \imagepng($image, $path, 8); // Compression level 8
                break;
            case 'image/gif':
                \imagegif($image, $path);
                break;
            case 'image/webp':
                \imagewebp($image, $path, 85);
                break;
        }
    }

    /**
     * Get file extension from MIME type
     */
    private function getExtensionFromMime(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        return $map[$mimeType] ?? 'jpg';
    }

    /**
     * Get upload error message
     */
    private function getUploadErrorMessage(int $error): string
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds server upload limit',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form upload limit',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by extension'
        ];
        return $errors[$error] ?? 'Unknown upload error';
    }

    /**
     * Get setting value (with Redis caching)
     */
    private function getSetting(string $key, $default)
    {
        $cacheKey = "setting:{$key}";

        // Try cache first.
        $cached = FlatCache::default()->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        
        // Query database
        $result = $this->db->queryBuilder()
            ->from('settings')
            ->where('setting_key', '=', $key)
            ->value('setting_value');

        $value = $result !== null ? $result : $default;
        
        // Cache the result
        FlatCache::default()->set($cacheKey, $value, self::CACHE_TTL_SETTINGS);
        
        return $value;
    }
}
