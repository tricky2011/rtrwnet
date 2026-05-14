-- Query audit cepat setelah repair multi-router

SELECT id, name, is_active, ip_address, api_port
FROM routers
ORDER BY id;

SELECT table_name, column_name, is_nullable, column_type
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND column_name = 'router_id'
  AND table_name IN (
    'customers','pppoe_secrets','ppp_profiles','ip_pools','work_orders','tickets','cashflow_transactions'
  )
ORDER BY table_name;

SELECT table_name, constraint_name, delete_rule, update_rule
FROM information_schema.referential_constraints
WHERE constraint_schema = DATABASE()
  AND referenced_table_name = 'routers'
  AND table_name IN (
    'customers','pppoe_secrets','ppp_profiles','ip_pools','work_orders','tickets','cashflow_transactions'
  )
ORDER BY table_name, constraint_name;

SELECT 'customers' AS table_name, COUNT(*) AS total_rows,
       SUM(CASE WHEN c.router_id IS NULL THEN 1 ELSE 0 END) AS null_router_rows,
       SUM(CASE WHEN c.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END) AS orphan_router_rows
FROM customers c LEFT JOIN routers r ON r.id = c.router_id
UNION ALL
SELECT 'pppoe_secrets', COUNT(*),
       SUM(CASE WHEN p.router_id IS NULL THEN 1 ELSE 0 END),
       SUM(CASE WHEN p.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END)
FROM pppoe_secrets p LEFT JOIN routers r ON r.id = p.router_id
UNION ALL
SELECT 'ppp_profiles', COUNT(*),
       SUM(CASE WHEN p.router_id IS NULL THEN 1 ELSE 0 END),
       SUM(CASE WHEN p.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END)
FROM ppp_profiles p LEFT JOIN routers r ON r.id = p.router_id
UNION ALL
SELECT 'ip_pools', COUNT(*),
       SUM(CASE WHEN i.router_id IS NULL THEN 1 ELSE 0 END),
       SUM(CASE WHEN i.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END)
FROM ip_pools i LEFT JOIN routers r ON r.id = i.router_id
UNION ALL
SELECT 'work_orders', COUNT(*),
       SUM(CASE WHEN w.router_id IS NULL THEN 1 ELSE 0 END),
       SUM(CASE WHEN w.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END)
FROM work_orders w LEFT JOIN routers r ON r.id = w.router_id
UNION ALL
SELECT 'tickets', COUNT(*),
       SUM(CASE WHEN t.router_id IS NULL THEN 1 ELSE 0 END),
       SUM(CASE WHEN t.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END)
FROM tickets t LEFT JOIN routers r ON r.id = t.router_id
UNION ALL
SELECT 'cashflow_transactions', COUNT(*),
       SUM(CASE WHEN cft.router_id IS NULL THEN 1 ELSE 0 END),
       SUM(CASE WHEN cft.router_id IS NOT NULL AND r.id IS NULL THEN 1 ELSE 0 END)
FROM cashflow_transactions cft LEFT JOIN routers r ON r.id = cft.router_id;

SHOW INDEX FROM pppoe_secrets;
SHOW INDEX FROM ppp_profiles;
SHOW INDEX FROM ip_pools;
