<?php
/**
 * Photo Upload Service
 * Handles secure photo uploads with validation, resizing, and storage
 */

namespace RadioChatBox;

use PDO;
use Redis;

class PhotoService
{
    private PDO $pdo;
    private Redis $redis;
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
        $this->pdo = Database::getPDO();
        $this->redis = Database::getRedis();
        $this->uploadDir = __DIR__ . '/../public/uploads/photos';
        
        // Get max size from settings (default 5MB)
        $maxSizeMB = $this->getSetting('max_photo_size_mb', 5);
        $this->maxFileSize = $maxSizeMB * 1024 * 1024;
        
        // Create upload directory if it doesn't exist
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
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

        // Save to database
        $stmt = $this->pdo->prepare("
            INSERT INTO attachments 
            (attachment_id, filename, original_filename, file_path, file_size, mime_type, 
             width, height, uploaded_by, recipient, ip_address)
            VALUES 
            (:attachment_id, :filename, :original_filename, :file_path, :file_size, :mime_type,
             :width, :height, :uploaded_by, :recipient, :ip_address)
        ");

        $stmt->execute([
            'attachment_id' => $attachmentId,
            'filename' => $filename,
            'original_filename' => $file['name'],
            'file_path' => '/uploads/photos/' . $filename,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'width' => $width,
            'height' => $height,
            'uploaded_by' => $username,
            'recipient' => $recipient,
            'ip_address' => $ipAddress
        ]);

        // Invalidate user cache
        $this->redis->del("user_attachments:{$username}");

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
        
        // Try cache first
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }
        
        // Query database
        $stmt = $this->pdo->prepare("
            SELECT * FROM attachments 
            WHERE attachment_id = :id AND is_deleted = FALSE
        ");
        $stmt->execute(['id' => $attachmentId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            // Cache the result
            $this->redis->setex($cacheKey, self::CACHE_TTL_ATTACHMENT, json_encode($result));
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
        
        // Try cache first
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return json_decode($cached, true);
        }
        
        // Query database
        $stmt = $this->pdo->prepare("
            SELECT * FROM attachments 
            WHERE uploaded_by = :username AND is_deleted = FALSE
            ORDER BY uploaded_at DESC
        ");
        $stmt->execute(['username' => $username]);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cache the result
        $this->redis->setex($cacheKey, self::CACHE_TTL_USER_ATTACHMENTS, json_encode($result));
        
        return $result;
    }

    /**
     * Get all attachments (for admin)
     */
    public function getAllAttachments(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM attachments 
            WHERE is_deleted = FALSE
            ORDER BY uploaded_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get total count of attachments (for admin pagination)
     */
    public function getTotalAttachmentsCount(): int
    {
        $stmt = $this->pdo->query("
            SELECT COUNT(*) FROM attachments 
            WHERE is_deleted = FALSE
        ");
        return (int)$stmt->fetchColumn();
    }

    /**
     * Delete expired photos (>48 hours)
     */
    public function cleanupExpiredPhotos(): int
    {
        // Get expired photos
        $stmt = $this->pdo->query("
            SELECT attachment_id, file_path FROM attachments 
            WHERE expires_at < NOW() AND is_deleted = FALSE
        ");
        $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $count = 0;
        foreach ($expired as $photo) {
            // Delete physical file
            $fullPath = __DIR__ . '/../public' . $photo['file_path'];
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }
            
            // Mark as deleted in database
            $stmt = $this->pdo->prepare("
                UPDATE attachments SET is_deleted = TRUE 
                WHERE attachment_id = :id
            ");
            $stmt->execute(['id' => $photo['attachment_id']]);
            
            // Invalidate cache
            $this->redis->del("attachment:{$photo['attachment_id']}");
            
            $count++;
        }

        if ($count > 0) {
            error_log("Cleanup: Deleted {$count} expired photos");
        }

        return $count;
    }

    /**
     * Validate uploaded file
     */
    private function validateFile(array $file): void
    {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
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
        
        \imagecopyresampled(
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
        
        // Try cache first
        $cached = $this->redis->get($cacheKey);
        if ($cached !== false) {
            return $cached;
        }
        
        // Query database
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        
        $value = $result !== false ? $result : $default;
        
        // Cache the result
        $this->redis->setex($cacheKey, self::CACHE_TTL_SETTINGS, $value);
        
        return $value;
    }
}
