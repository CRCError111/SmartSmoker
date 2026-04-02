<?php
// Защита от прямого доступа к директории /api
header('HTTP/1.0 403 Forbidden');
echo json_encode([
    'success' => false,
    'error' => 'Доступ запрещен. Используйте конкретные эндпоинты API.'
]);
exit;
?>