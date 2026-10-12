-- Rename the customer-account table without changing its records or columns.
-- MySQL keeps inbound foreign-key relationships intact when a referenced table is renamed.

RENAME TABLE `memberships` TO `customer`;
