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
