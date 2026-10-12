<?php
// Temporary tables and uniquely named test images leave real menu items unchanged.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/menu_image_helper.php';
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running menu image checks.');
$db->exec('CREATE TEMPORARY TABLE menu_items (item_id INT PRIMARY KEY, item_name VARCHAR(100), image_url VARCHAR(255)) ENGINE=InnoDB');
$checks = 0;
$files = [];
$prefix = 'menu_cleanup_test_' . bin2hex(random_bytes(8));
function checkMenuImage(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function testMenuPhoto(string $folder, string $suffix): string {
    global $files, $prefix;
    $relative = $folder . '/' . $prefix . '_' . $suffix . '.png';
    $absolute = dirname(__DIR__) . '/' . $relative;
    $files[] = $absolute;
    file_put_contents($absolute, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRz8AAAAASUVORK5CYII='));
    return $relative;
}
function menuPhotoExists(string $path): bool {
    clearstatcache();
    return is_file(dirname(__DIR__) . '/' . $path);
}
function insertMenuPhoto(int $id, ?string $path): void {
    global $db;
    $db->prepare('INSERT INTO menu_items VALUES (?, ?, ?)')->execute([$id, 'Test meal', $path]);
}
try {
    $shared = testMenuPhoto('images/menu', 'shared');
    insertMenuPhoto(1, $shared);
    insertMenuPhoto(2, '/images/' . basename($shared));
    checkMenuImage(!removeUnusedMenuImage($db, $shared), 'Photos referenced by a menu item stay intact.');
    checkMenuImage(deleteMenuItemWithImage($db, 1)['item_id'] === 1, 'Deletion returns the removed item for auditing.');
    checkMenuImage(menuPhotoExists($shared), 'A photo shared through a legacy path is preserved.');
    deleteMenuItemWithImage($db, 2);
    checkMenuImage(!menuPhotoExists($shared), 'Deleting the last user of a photo removes the file.');
    checkMenuImage(deleteMenuItemWithImage($db, 99999) === null, 'Deleting a missing item is harmless.');
    checkMenuImage(!removeUnusedMenuImage($db, $shared), 'Removing an already missing photo is harmless.');

    $legacy = testMenuPhoto('images', 'legacy');
    insertMenuPhoto(3, '/' . $legacy);
    deleteMenuItemWithImage($db, 3);
    checkMenuImage(!menuPhotoExists($legacy), 'Older root-level menu uploads are also removed.');
    insertMenuPhoto(4, null);
    deleteMenuItemWithImage($db, 4);
    checkMenuImage((int) $db->query('SELECT COUNT(*) FROM menu_items')->fetchColumn() === 0, 'Items without images can be deleted.');

    $old = testMenuPhoto('images/menu', 'old');
    $new = testMenuPhoto('images/menu', 'new');
    insertMenuPhoto(5, $old);
    $db->prepare('UPDATE menu_items SET image_url = ? WHERE item_id = 5')->execute([$new]);
    checkMenuImage(removeUnusedMenuImage($db, $old), 'Replacing a photo cleans up the old upload.');
    checkMenuImage(menuPhotoExists($new), 'The replacement photo is retained.');
    checkMenuImage(!removeUnusedMenuImage($db, '/' . $new), 'Leading-slash references still protect active photos.');

    $profile = testMenuPhoto('images/profiles/customer', 'profile');
    $site = testMenuPhoto('images/site', 'site');
    foreach ([$profile, $site, 'https://example.invalid/' . $new, 'images/menu/../site/' . basename($site), '../config/config.php', ''] as $unsafe) {
        checkMenuImage(!removeUnusedMenuImage($db, $unsafe), 'Cleanup refuses non-menu or unsafe path: ' . $unsafe);
    }
    checkMenuImage(menuPhotoExists($profile) && menuPhotoExists($site), 'Profile and site photos are preserved.');

    $db->exec('DROP TEMPORARY TABLE menu_items');
    // Shadow the real table with an incompatible temporary table to force a failure.
    $db->exec('CREATE TEMPORARY TABLE menu_items (invalid_column INT) ENGINE=InnoDB');
    $failed = false;
    try { deleteMenuItemWithImage($db, 5); } catch (PDOException $error) { $failed = true; }
    checkMenuImage($failed && !$db->inTransaction(), 'A failed database operation rolls back.');
    checkMenuImage(menuPhotoExists($new), 'A database failure never removes the photo.');
    echo "Passed $checks menu image checks.\n";
} finally {
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
}
