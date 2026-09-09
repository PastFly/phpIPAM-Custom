<?php
/** Reuse the exact Phase 5F.3.3 provider, including session/admin/CSRF guards. */
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
require(__DIR__ . '/../../integrations/vmware/enriched-search-result.php');
