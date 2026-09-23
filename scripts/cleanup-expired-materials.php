<?php

require_once __DIR__ . '/../config.php';

$today = date('Y-m-d');

$stmt = $conn->prepare("
    SELECT material_id, external_url
    FROM materials
    WHERE auto_delete_after_expiry = TRUE
      AND available_until IS NOT NULL
      AND available_until < ?
");

$stmt->execute([$today]);

$deletedFiles = 0;
$missingFiles = 0;
$deletedMaterials = 0;

while ($material = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $materialId = (int) $material['material_id'];
    $relativePath = trim($material['external_url'] ?? '');

    echo "Processing material #{$materialId}\n";

    /*
     * Delete the physical local file first.
     * If the file is already missing, continue so the DB record
     * can still be cleaned up.
     */
    if (
        $relativePath !== '' &&
        strpos($relativePath, 'uploads/materials/') === 0
    ) {
        $localPath = __DIR__ . '/../' . $relativePath;

        if (is_file($localPath)) {
            if (unlink($localPath)) {
                $deletedFiles++;
                echo "Deleted file: {$relativePath}\n";
            } else {
                echo "Failed to delete file: {$relativePath}\n";
                continue;
            }
        } else {
            $missingFiles++;
            echo "File not found: {$relativePath}\n";
        }
    } else {
        echo "No local file associated.\n";
    }

    /*
     * Delete the material itself.
     *
     * material_targets and material_progress reference materials
     * with ON DELETE CASCADE, so PostgreSQL removes those automatically.
     */
    try {
        $conn->beginTransaction();

        $delete = $conn->prepare("
            DELETE FROM materials
            WHERE material_id = ?
        ");

        $delete->execute([$materialId]);

        $conn->commit();

        if ($delete->rowCount() > 0) {
            $deletedMaterials++;
            echo "Deleted material record #{$materialId}\n";
        } else {
            echo "Material record #{$materialId} was already gone.\n";
        }

    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        echo "Failed to delete material #{$materialId}: {$e->getMessage()}\n";
    }
}

echo "Expired material cleanup complete.\n";
echo "Files deleted: {$deletedFiles}\n";
echo "Missing files: {$missingFiles}\n";
echo "Material records deleted: {$deletedMaterials}\n";
