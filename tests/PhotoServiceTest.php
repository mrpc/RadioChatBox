<?php

namespace RadioChatBox\Tests;

use Pramnos\Framework\Testing\TestDatabase;

use PDO;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use RadioChatBox\Services\PhotoService;

/**
 * Covers the photo "trash": on expiry a photo is soft-deleted but its file is
 * kept so an admin can still review it, and it only shows up when deleted ones
 * are explicitly requested. Permanent removal (emptyTrash) is not exercised
 * here because it purges every trashed photo globally and would touch shared
 * data — its logic mirrors the file-unlink already covered by the kept-file
 * assertion.
 */
class PhotoServiceTest extends TestCase
{
    private PDO $pdo;
    private string $id;
    private string $fullPath;

    /** Run the cleanup with its error_log line diverted to a file, so the test's output stays clean. */
    private function cleanupQuietly(PhotoService $service): void
    {
        $previous = ini_set('error_log', tempnam(sys_get_temp_dir(), 'ptest'));
        try {
            $service->cleanupExpiredPhotos();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
    }

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::connection();

        $suffix = substr(bin2hex(random_bytes(5)), 0, 8);
        $this->id = 'phototest_' . $suffix;
        $filename = 'phototest_' . $suffix . '.jpg';

        $dir = dirname(__DIR__) . '/public/uploads/photos';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->fullPath = $dir . '/' . $filename;
        file_put_contents($this->fullPath, 'x');

        // An already-expired photo, still live (not yet trashed).
        $this->pdo->prepare(
            "INSERT INTO attachments
                (attachment_id, filename, original_filename, file_path, file_size, mime_type, uploaded_by, ip_address, expires_at, is_deleted)
             VALUES (?, ?, ?, ?, 1, 'image/jpeg', 'phototester', '127.0.0.1', NOW() - INTERVAL '1 hour', FALSE)"
        )->execute([$this->id, $filename, $filename, '/uploads/photos/' . $filename]);
    }

    protected function tearDown(): void
    {
        $this->pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$this->id]);
        if (is_file($this->fullPath)) {
            @unlink($this->fullPath);
        }
    }

    /**
     * Expiry moves a photo to the trash (soft-deleted) but must KEEP its file on
     * disk, so it stays viewable until the trash is emptied by hand.
     */
    public function testCleanupSoftDeletesButKeepsTheFile(): void
    {
        $this->cleanupQuietly(new PhotoService());

        $stmt = $this->pdo->prepare('SELECT is_deleted FROM attachments WHERE attachment_id = ?');
        $stmt->execute([$this->id]);

        $this->assertTrue((bool) $stmt->fetchColumn(), 'the expired photo is soft-deleted');
        $this->assertFileExists($this->fullPath, 'its file is kept until the trash is emptied');
    }

    /**
     * getAttachment() returns a live (not soft-deleted) row by id, and returns
     * null once the photo is trashed — exercising the converted first()/
     * whereRaw('is_deleted = FALSE') read and its cache invalidation on cleanup.
     */
    public function testGetAttachmentReturnsLiveRowThenNullAfterTrash(): void
    {
        $service = new PhotoService();

        $live = $service->getAttachment($this->id);
        $this->assertIsArray($live, 'a live photo is returned by id');
        $this->assertSame($this->id, $live['attachment_id']);
        $this->assertSame('/uploads/photos/' . basename($this->fullPath), $live['file_path']);

        // Trashing it invalidates the cached row; the next read must miss the
        // is_deleted = FALSE filter and return null.
        $this->cleanupQuietly($service);
        $this->assertNull($service->getAttachment($this->id), 'a trashed photo is no longer returned');
    }

    /**
     * A trashed photo is hidden from the normal listing and appears only when
     * deleted photos are explicitly included.
     */
    public function testDeletedPhotosAreListedOnlyWhenIncluded(): void
    {
        $service = new PhotoService();
        $this->cleanupQuietly($service);

        $visible = array_column($service->getAllAttachments(500, 0, false), 'attachment_id');
        $this->assertNotContains($this->id, $visible, 'trashed photos are hidden by default');

        $withTrash = array_column($service->getAllAttachments(500, 0, true), 'attachment_id');
        $this->assertContains($this->id, $withTrash, 'they appear when deleted ones are included');
    }

    /**
     * getAttachmentsByUser lists a live photo for its uploader (the converted
     * uploaded_by / is_deleted = FALSE read, cached per user).
     */
    public function testGetAttachmentsByUserListsLivePhoto(): void
    {
        $service = new PhotoService();

        $ids = array_column($service->getAttachmentsByUser('phototester'), 'attachment_id');
        $this->assertContains($this->id, $ids);
    }

    /**
     * getTotalAttachmentsCount counts live photos, and counts more (or equal)
     * when soft-deleted ones are included — covering both branches.
     */
    public function testTotalAttachmentsCountBranches(): void
    {
        $service = new PhotoService();

        $liveBefore = $service->getTotalAttachmentsCount(false);
        $this->assertGreaterThanOrEqual(1, $liveBefore, 'our live photo is counted');

        $this->cleanupQuietly($service); // trashes our photo

        $withDeleted = $service->getTotalAttachmentsCount(true);
        $live        = $service->getTotalAttachmentsCount(false);
        $this->assertGreaterThanOrEqual($live, $withDeleted, 'including deleted never counts fewer');
    }

    /**
     * A legacy row with a NULL is_deleted must still be listed and counted: the
     * read uses "is_deleted IS NOT TRUE", not "= FALSE" (which drops NULLs).
     */
    public function testNullIsDeletedRowsAreTreatedAsLive(): void
    {
        $service = new PhotoService();
        $nid = 'phototest_null_' . substr(bin2hex(random_bytes(4)), 0, 6);
        $this->pdo->prepare(
            "INSERT INTO attachments
                (attachment_id, filename, original_filename, file_path, file_size, mime_type, uploaded_by, ip_address, expires_at, is_deleted)
             VALUES (?, ?, ?, '/uploads/photos/x.jpg', 1, 'image/jpeg', 'phototester', '127.0.0.1', NOW() + INTERVAL '1 hour', NULL)"
        )->execute([$nid, $nid . '.jpg', $nid . '.jpg']);

        try {
            $ids = array_column($service->getAllAttachments(500, 0, false), 'attachment_id');
            $this->assertContains($nid, $ids, 'a NULL-is_deleted row is listed as live');
        } finally {
            $this->pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$nid]);
        }
    }

    /** A PhotoService that accepts a CLI temp file as an upload. */
    private function acceptingService(): PhotoService
    {
        return new class extends PhotoService {
            protected function isUploadedFile(string $path): bool
            {
                return is_file($path);
            }
        };
    }

    /** Build a $_FILES-style entry for $tmp. */
    private function fileEntry(string $tmp, string $name = 'p.jpg', ?int $size = null): array
    {
        return [
            'tmp_name' => $tmp,
            'name'     => $name,
            'size'     => $size ?? (int) filesize($tmp),
            'error'    => UPLOAD_ERR_OK,
            'type'     => 'image/jpeg',
        ];
    }

    /**
     * uploadPhoto's validation branches each throw their specific message: a bad
     * extension, an empty file, an over-size file, and a non-image payload (valid
     * extension but not a real image).
     */
    public function testUploadValidationRejections(): void
    {
        $service = $this->acceptingService();

        // Real JPEG for the extension/size checks.
        $img = imagecreatetruecolor(20, 20);
        $jpg = tempnam(sys_get_temp_dir(), 'okj') . '.jpg';
        imagejpeg($img, $jpg);
        imagedestroy($img);

        // A text file with a .jpg name (passes extension, fails getimagesize).
        $fake = tempnam(sys_get_temp_dir(), 'fake') . '.jpg';
        file_put_contents($fake, 'not an image');

        try {
            $this->assertUploadThrows($service, $this->fileEntry($jpg, 'p.txt'), 'Invalid file extension');
            $this->assertUploadThrows($service, $this->fileEntry($jpg, 'p.jpg', 0), 'File is empty');
            $this->assertUploadThrows($service, $this->fileEntry($jpg, 'p.jpg', 999999999), 'File too large');
            $this->assertUploadThrows($service, $this->fileEntry($fake, 'p.jpg'), 'File is not a valid image');
        } finally {
            @unlink($jpg);
            @unlink($fake);
        }
    }

    private function assertUploadThrows(PhotoService $service, array $file, string $expectedMessage): void
    {
        try {
            $service->uploadPhoto($file, 'u', 'r', '127.0.0.1');
            $this->fail("expected upload to throw: {$expectedMessage}");
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($expectedMessage, $e->getMessage());
        }
    }

    /**
     * A non-OK upload error code is mapped to its human message via
     * getUploadErrorMessage (checked after validateFile passes).
     */
    public function testUploadErrorCodeIsMapped(): void
    {
        $img = imagecreatetruecolor(20, 20);
        $jpg = tempnam(sys_get_temp_dir(), 'errj') . '.jpg';
        imagejpeg($img, $jpg);
        imagedestroy($img);

        try {
            $file = $this->fileEntry($jpg);
            $file['error'] = UPLOAD_ERR_INI_SIZE;
            $this->assertUploadThrows($this->acceptingService(), $file, 'exceeds server upload limit');
        } finally {
            @unlink($jpg);
        }
    }

    /**
     * A PNG upload is stored with a .png extension (getExtensionFromMime's png
     * branch) and its real dimensions.
     */
    public function testUploadPngStoresWithPngExtension(): void
    {
        $img = imagecreatetruecolor(64, 48);
        $png = tempnam(sys_get_temp_dir(), 'okp') . '.png';
        imagepng($img, $png);
        imagedestroy($img);

        $uploaded = null;
        try {
            $uploaded = $this->acceptingService()->uploadPhoto(
                ['tmp_name' => $png, 'name' => 'pic.png', 'size' => (int) filesize($png), 'error' => UPLOAD_ERR_OK, 'type' => 'image/png'],
                'u', 'r', '127.0.0.1'
            );
            $this->assertStringEndsWith('.png', $uploaded['filename']);
            $this->assertSame('image/png', $uploaded['mime_type']);
        } finally {
            @unlink($png);
            if ($uploaded !== null) {
                $disk = dirname(__DIR__) . '/public' . $uploaded['file_path'];
                if (is_file($disk)) {
                    @unlink($disk);
                }
                $this->pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$uploaded['attachment_id']]);
            }
        }
    }

    /**
     * uploadPhoto refuses when photo uploads are disabled by setting
     * (allow_photo_uploads=false). The setting is snapshotted/restored.
     */
    public function testUploadRejectedWhenDisabled(): void
    {
        $settings = new \RadioChatBox\Services\SettingsService();
        $prev = (string) $settings->get('allow_photo_uploads', 'true');
        try {
            $settings->setMultiple(['allow_photo_uploads' => 'false']);
            // PhotoService caches the setting per-key; bust it so the write is seen.
            \Pramnos\Cache\FlatCache::default()->delete('setting:allow_photo_uploads');
            $this->assertUploadThrows(
                new PhotoService(),
                ['tmp_name' => '/x', 'name' => 'p.jpg', 'size' => 1, 'error' => UPLOAD_ERR_OK],
                'Photo uploads are disabled'
            );
        } finally {
            $settings->setMultiple(['allow_photo_uploads' => $prev]);
            \Pramnos\Cache\FlatCache::default()->delete('setting:allow_photo_uploads');
        }
    }

    /**
     * emptyTrash permanently removes soft-deleted photos — unlinks the file and
     * drops the row. Safe to exercise now that the suite runs on an isolated DB.
     */
    public function testEmptyTrashRemovesTrashedPhotos(): void
    {
        $id  = 'trash_' . substr(bin2hex(random_bytes(5)), 0, 8);
        $dir = dirname(__DIR__) . '/public/uploads/photos';
        $fn  = $id . '.jpg';
        $disk = $dir . '/' . $fn;
        file_put_contents($disk, 'x');
        $this->pdo->prepare(
            "INSERT INTO attachments
                (attachment_id, filename, original_filename, file_path, file_size, mime_type, uploaded_by, ip_address, is_deleted)
             VALUES (?, ?, ?, ?, 1, 'image/jpeg', 'trashtester', '127.0.0.1', TRUE)"
        )->execute([$id, $fn, $fn, '/uploads/photos/' . $fn]);

        try {
            $removed = (new PhotoService())->emptyTrash();
            $this->assertGreaterThanOrEqual(1, $removed);
            $this->assertFileDoesNotExist($disk, 'the trashed file must be unlinked');

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM attachments WHERE attachment_id = ?');
            $stmt->execute([$id]);
            $this->assertSame(0, (int) $stmt->fetchColumn(), 'the row must be dropped');
        } finally {
            @unlink($disk);
            $this->pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')->execute([$id]);
        }
    }

    /**
     * uploadPhoto processes a real (test-double-accepted) upload end to end:
     * validates it, decodes + resizes an oversized image via GD, stores the
     * optimised file and a DB row, and returns the attachment metadata. The
     * is_uploaded_file() gate is bypassed by the test double so the pipeline runs
     * under CLI. The stored file and row are cleaned up.
     */
    public function testUploadPhotoStoresAnOptimisedImageAndRow(): void
    {
        // A real oversized JPEG on disk, to also drive the resize branch.
        $img = imagecreatetruecolor(2200, 400);
        imagefilledrectangle($img, 0, 0, 2200, 400, imagecolorallocate($img, 30, 90, 160));
        $tmp = tempnam(sys_get_temp_dir(), 'upl') . '.jpg';
        imagejpeg($img, $tmp);
        imagedestroy($img);

        $service = new class extends PhotoService {
            protected function isUploadedFile(string $path): bool
            {
                return is_file($path); // accept a real temp file under CLI
            }
        };

        $uploaded = null;
        try {
            $uploaded = $service->uploadPhoto(
                [
                    'tmp_name' => $tmp,
                    'name'     => 'holiday.jpg',
                    'size'     => filesize($tmp),
                    'error'    => UPLOAD_ERR_OK,
                    'type'     => 'image/jpeg',
                ],
                'uploader_' . substr($this->id, 10),
                'recipient_' . substr($this->id, 10),
                '127.0.0.1'
            );

            $this->assertArrayHasKey('attachment_id', $uploaded);
            $this->assertSame('image/jpeg', $uploaded['mime_type']);
            // Oversized width was scaled down to the 1920 cap.
            $this->assertLessThanOrEqual(1920, $uploaded['width']);

            // The optimised file really exists, and a live DB row was written.
            $disk = dirname(__DIR__) . '/public' . $uploaded['file_path'];
            $this->assertFileExists($disk);
            $this->assertIsArray($service->getAttachment($uploaded['attachment_id']));
        } finally {
            @unlink($tmp);
            if ($uploaded !== null) {
                $disk = dirname(__DIR__) . '/public' . ($uploaded['file_path'] ?? '');
                if ($uploaded['file_path'] ?? null && is_file($disk)) {
                    @unlink($disk);
                }
                $this->pdo->prepare('DELETE FROM attachments WHERE attachment_id = ?')
                    ->execute([$uploaded['attachment_id']]);
            }
        }
    }
}
