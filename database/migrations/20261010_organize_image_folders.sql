-- Run after moving the image files to the folders documented in README.md.
-- Only legacy paths match, so this migration is safe to rerun.
START TRANSACTION;

UPDATE menu_items
SET image_url = CONCAT('images/menu/', SUBSTRING_INDEX(image_url, '/', -1))
WHERE image_url REGEXP '^/?images/menu_[^/]+$';

UPDATE customer
SET profile_picture = CONCAT('images/profiles/customer/', SUBSTRING_INDEX(profile_picture, '/', -1))
WHERE profile_picture REGEXP '^/?images/profiles/[^/]+$';

UPDATE admins
SET profile_picture = CONCAT('images/profiles/admin/', SUBSTRING_INDEX(profile_picture, '/', -1))
WHERE profile_picture REGEXP '^/?uploads/admin/avatars/[^/]+$';

UPDATE staffs
SET profile_picture = CONCAT('images/profiles/staff/', SUBSTRING_INDEX(profile_picture, '/', -1))
WHERE profile_picture REGEXP '^/?uploads/staff/avatars/[^/]+$';

COMMIT;
