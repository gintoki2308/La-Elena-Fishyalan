-- User-requested unit correction: existing kg numbers represent piece counts.
-- Keep stock quantities, recipe quantities, reorder levels, and prices unchanged.
START TRANSACTION;

UPDATE inventory_order_usage u
JOIN ingredients i ON i.ingredient_id = u.ingredient_id
SET u.unit = 'pieces'
WHERE LOWER(TRIM(i.unit)) = 'kg' AND LOWER(TRIM(u.unit)) = 'kg';

UPDATE ingredients SET unit = 'pieces' WHERE LOWER(TRIM(unit)) = 'kg';

COMMIT;
