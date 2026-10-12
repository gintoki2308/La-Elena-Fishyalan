<?php

// Only uploaded menu photos are eligible for automatic removal.
function uploadedMenuImageName($imagePath): ?string {
    if (!is_string($imagePath)) return null;
    $path = preg_replace('~^(?:\./|\.\./|/)+~', '', trim($imagePath));
    if (!preg_match('~^images/(?:menu/)?(menu_[a-z0-9_]+\.(?:jpg|jpeg|png|gif|webp))$~iD', $path, $match)) {
        return null;
    }
    return $match[1];
}

function removeUnusedMenuImage(PDO $db, $imagePath): bool {
    $filename = uploadedMenuImageName($imagePath);
    if ($filename === null) return false;

    try {
        // Compare both old and new folder paths, including leading slashes.
        $references = $db->query("SELECT image_url FROM menu_items WHERE image_url IS NOT NULL AND image_url <> ''");
        foreach ($references as $reference) {
            $otherName = uploadedMenuImageName($reference['image_url']);
            if ($otherName !== null && strcasecmp($otherName, $filename) === 0) return false;
        }

        $root = realpath(__DIR__ . '/..');
        $removed = false;
        foreach (['/images/menu/', '/images/'] as $folder) {
            $candidate = $root . $folder . $filename;
            $resolved = realpath($candidate);
            // Reject links or paths resolving outside their expected upload folder.
            if ($resolved === false || !is_file($resolved) || is_link($candidate)) continue;
            if (strcasecmp(str_replace('\\', '/', $resolved), str_replace('\\', '/', $candidate)) !== 0) continue;
            if (!unlink($resolved)) {
                error_log('Could not remove unused menu image: ' . $filename);
                continue;
            }
            $removed = true;
        }
        return $removed;
    } catch (Throwable $error) {
        // A cleanup failure must not report an already committed deletion as failed.
        error_log('Menu image cleanup failed: ' . $error->getMessage());
        return false;
    }
}

function deleteMenuItemWithImage(PDO $db, int $itemId): ?array {
    $db->beginTransaction();
    try {
        $statement = $db->prepare('SELECT * FROM menu_items WHERE item_id = ? FOR UPDATE');
        $statement->execute([$itemId]);
        $item = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            $db->rollBack();
            return null;
        }
        $statement = $db->prepare('DELETE FROM menu_items WHERE item_id = ?');
        $statement->execute([$itemId]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }

    // Only remove the file after the database has accepted the deletion.
    removeUnusedMenuImage($db, $item['image_url'] ?? null);
    return $item;
}
