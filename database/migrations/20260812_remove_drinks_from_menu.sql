-- Retire drink menu items while preserving order and audit history.
UPDATE menu_items
SET is_available = FALSE,
    updated_at = NOW()
WHERE LOWER(TRIM(category)) IN (
    'cocktails', 'cocktail', 'wine', 'non-alcoholic',
    'after dinner', 'coffee', 'tea', 'juice', 'smoothie'
);
