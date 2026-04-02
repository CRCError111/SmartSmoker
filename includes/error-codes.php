<?php
/**
 * Структурированные коды ошибок API
 * 
 * @version 1.0
 */

if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

class ErrorCodes {
    // Аутентификация
    const AUTH_DEVICE_ID_MISSING  = 'AUTH_001';
    const AUTH_TOKEN_MISSING      = 'AUTH_002';
    const AUTH_DEVICE_NOT_FOUND   = 'AUTH_003';
    const AUTH_DEVICE_UNBOUND     = 'AUTH_004';
    const AUTH_INVALID_TOKEN      = 'AUTH_005';
    const AUTH_DEVICE_ID_MISMATCH = 'AUTH_006';

    // Привязка устройства
    const BIND_INVALID_JSON       = 'BIND_001';
    const BIND_DEVICE_ID_MISSING  = 'BIND_002';
    const BIND_RATE_LIMITED       = 'BIND_003';
    const BIND_INVALID_IP         = 'BIND_004';
    const BIND_AUTH_REQUIRED      = 'BIND_005';
    const BIND_ALREADY_BOUND      = 'BIND_006';

    // Телеметрия
    const TELEMETRY_INVALID_JSON  = 'TELE_001';
    const TELEMETRY_VALIDATION    = 'TELE_002';
    const TELEMETRY_RATE_LIMITED  = 'TELE_003';
    const TELEMETRY_STALE         = 'TELE_004';

    // Общие
    const DB_ERROR                = 'SYS_001';
    const INTERNAL_ERROR          = 'SYS_002';
}
