<?php
/**
 * Валидатор данных телеметрии от ESP32
 * Проверяет диапазоны значений для температуры, влажности и PWM
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

class TelemetryValidator {
    // Диапазоны значений
    const TEMP_MIN = -50.0;
    const TEMP_MAX = 300.0;
    const HUMIDITY_MIN = 0.0;
    const HUMIDITY_MAX = 100.0;
    const PWM_MIN = 0;
    const PWM_MAX = 100;
    const PERCENT_MIN = 0;
    const PERCENT_MAX = 100;
    
    /**
     * Валидация данных телеметрии
     * 
     * @param array $data Данные от контроллера
     * @return array Валидированные данные
     * @throws Exception При ошибке валидации
     */
    public static function validate($data) {
        $errors = [];
        
        // Валидация температур (новый формат)
        if (isset($data['sensors'])) {
            // Температура камеры
            if (isset($data['sensors']['temp_chamber'])) {
                $temp = (float)$data['sensors']['temp_chamber'];
                if (!self::isValidTemperature($temp)) {
                    $errors[] = sprintf(
                        'temp_chamber вне диапазона (%.1f до %.1f°C): %.1f',
                        self::TEMP_MIN,
                        self::TEMP_MAX,
                        $temp
                    );
                }
            }
            
            // Температура дыма
            if (isset($data['sensors']['temp_smoke'])) {
                $temp = (float)$data['sensors']['temp_smoke'];
                if (!self::isValidTemperature($temp)) {
                    $errors[] = sprintf(
                        'temp_smoke вне диапазона (%.1f до %.1f°C): %.1f',
                        self::TEMP_MIN,
                        self::TEMP_MAX,
                        $temp
                    );
                }
            }
            
            // Температура продукта
            if (isset($data['sensors']['temp_product'])) {
                $temp = (float)$data['sensors']['temp_product'];
                if (!self::isValidTemperature($temp)) {
                    $errors[] = sprintf(
                        'temp_product вне диапазона (%.1f до %.1f°C): %.1f',
                        self::TEMP_MIN,
                        self::TEMP_MAX,
                        $temp
                    );
                }
            }
            
            // Влажность
            if (isset($data['sensors']['humidity'])) {
                $humidity = (float)$data['sensors']['humidity'];
                if (!self::isValidHumidity($humidity)) {
                    $errors[] = sprintf(
                        'humidity вне диапазона (%.1f до %.1f%%): %.1f',
                        self::HUMIDITY_MIN,
                        self::HUMIDITY_MAX,
                        $humidity
                    );
                }
            }
        }
        
        // Валидация температур (старый формат для обратной совместимости)
        if (isset($data['temp_chamber'])) {
            $temp = (float)$data['temp_chamber'];
            if (!self::isValidTemperature($temp)) {
                $errors[] = sprintf(
                    'temp_chamber вне диапазона (%.1f до %.1f°C): %.1f',
                    self::TEMP_MIN,
                    self::TEMP_MAX,
                    $temp
                );
            }
        }
        
        if (isset($data['temp_smoke'])) {
            $temp = (float)$data['temp_smoke'];
            if (!self::isValidTemperature($temp)) {
                $errors[] = sprintf(
                    'temp_smoke вне диапазона (%.1f до %.1f°C): %.1f',
                    self::TEMP_MIN,
                    self::TEMP_MAX,
                    $temp
                );
            }
        }
        
        if (isset($data['temp_product'])) {
            $temp = (float)$data['temp_product'];
            if (!self::isValidTemperature($temp)) {
                $errors[] = sprintf(
                    'temp_product вне диапазона (%.1f до %.1f°C): %.1f',
                    self::TEMP_MIN,
                    self::TEMP_MAX,
                    $temp
                );
            }
        }
        
        if (isset($data['humidity'])) {
            $humidity = (float)$data['humidity'];
            if (!self::isValidHumidity($humidity)) {
                $errors[] = sprintf(
                    'humidity вне диапазона (%.1f до %.1f%%): %.1f',
                    self::HUMIDITY_MIN,
                    self::HUMIDITY_MAX,
                    $humidity
                );
            }
        }
        
        // Валидация исполнительных механизмов (новый формат)
        if (isset($data['actuators'])) {
            // PWM дымогенератора
            if (isset($data['actuators']['smoke_pwm'])) {
                $pwm = (int)$data['actuators']['smoke_pwm'];
                if (!self::isValidPWM($pwm)) {
                    $errors[] = sprintf(
                        'smoke_pwm вне диапазона (%d до %d): %d',
                        self::PWM_MIN,
                        self::PWM_MAX,
                        $pwm
                    );
                }
            }
            
            // Позиция заслонки
            if (isset($data['actuators']['damper_position'])) {
                $position = (int)$data['actuators']['damper_position'];
                if (!self::isValidPercent($position)) {
                    $errors[] = sprintf(
                        'damper_position вне диапазона (%d до %d): %d',
                        self::PERCENT_MIN,
                        self::PERCENT_MAX,
                        $position
                    );
                }
            }
        }
        
        // Валидация исполнительных механизмов (старый формат)
        if (isset($data['damper_percent'])) {
            $percent = (int)$data['damper_percent'];
            if (!self::isValidPercent($percent)) {
                $errors[] = sprintf(
                    'damper_percent вне диапазона (%d до %d): %d',
                    self::PERCENT_MIN,
                    self::PERCENT_MAX,
                    $percent
                );
            }
        }
        
        // Валидация системной информации
        if (isset($data['system'])) {
            // Прогресс этапа
            if (isset($data['system']['stage_progress'])) {
                $progress = (int)$data['system']['stage_progress'];
                if (!self::isValidPercent($progress)) {
                    $errors[] = sprintf(
                        'stage_progress вне диапазона (%d до %d): %d',
                        self::PERCENT_MIN,
                        self::PERCENT_MAX,
                        $progress
                    );
                }
            }
        }
        
        // Если есть ошибки валидации, выбрасываем исключение
        if (!empty($errors)) {
            throw new Exception('Validation failed: ' . implode('; ', $errors));
        }
        
        return $data;
    }
    
    /**
     * Проверка температуры на валидность
     * 
     * @param float $temp Температура
     * @return bool
     */
    private static function isValidTemperature($temp) {
        // Проверяем, что это число
        if (!is_numeric($temp)) {
            return false;
        }
        
        // Проверяем диапазон
        return $temp >= self::TEMP_MIN && $temp <= self::TEMP_MAX;
    }
    
    /**
     * Проверка влажности на валидность
     * 
     * @param float $humidity Влажность
     * @return bool
     */
    private static function isValidHumidity($humidity) {
        // Проверяем, что это число
        if (!is_numeric($humidity)) {
            return false;
        }
        
        // Проверяем диапазон
        return $humidity >= self::HUMIDITY_MIN && $humidity <= self::HUMIDITY_MAX;
    }
    
    /**
     * Проверка PWM значения на валидность
     * 
     * @param int $pwm PWM значение
     * @return bool
     */
    private static function isValidPWM($pwm) {
        // Проверяем, что это целое число
        if (!is_numeric($pwm)) {
            return false;
        }
        
        $pwm = (int)$pwm;
        
        // Проверяем диапазон
        return $pwm >= self::PWM_MIN && $pwm <= self::PWM_MAX;
    }
    
    /**
     * Проверка процентного значения на валидность
     * 
     * @param int $percent Процентное значение
     * @return bool
     */
    private static function isValidPercent($percent) {
        // Проверяем, что это целое число
        if (!is_numeric($percent)) {
            return false;
        }
        
        $percent = (int)$percent;
        
        // Проверяем диапазон
        return $percent >= self::PERCENT_MIN && $percent <= self::PERCENT_MAX;
    }
    
    /**
     * Санитизация данных телеметрии
     * Приводит значения к допустимым диапазонам вместо отклонения
     * 
     * @param array $data Данные от контроллера
     * @return array Санитизированные данные
     */
    public static function sanitize($data) {
        // Санитизация температур (новый формат)
        if (isset($data['sensors'])) {
            if (isset($data['sensors']['temp_chamber'])) {
                $data['sensors']['temp_chamber'] = self::clampTemperature((float)$data['sensors']['temp_chamber']);
            }
            if (isset($data['sensors']['temp_smoke'])) {
                $data['sensors']['temp_smoke'] = self::clampTemperature((float)$data['sensors']['temp_smoke']);
            }
            if (isset($data['sensors']['temp_product'])) {
                $data['sensors']['temp_product'] = self::clampTemperature((float)$data['sensors']['temp_product']);
            }
            if (isset($data['sensors']['humidity'])) {
                $data['sensors']['humidity'] = self::clampHumidity((float)$data['sensors']['humidity']);
            }
        }
        
        // Санитизация температур (старый формат)
        if (isset($data['temp_chamber'])) {
            $data['temp_chamber'] = self::clampTemperature((float)$data['temp_chamber']);
        }
        if (isset($data['temp_smoke'])) {
            $data['temp_smoke'] = self::clampTemperature((float)$data['temp_smoke']);
        }
        if (isset($data['temp_product'])) {
            $data['temp_product'] = self::clampTemperature((float)$data['temp_product']);
        }
        if (isset($data['humidity'])) {
            $data['humidity'] = self::clampHumidity((float)$data['humidity']);
        }
        
        // Санитизация исполнительных механизмов (новый формат)
        if (isset($data['actuators'])) {
            if (isset($data['actuators']['smoke_pwm'])) {
                $data['actuators']['smoke_pwm'] = self::clampPWM((int)$data['actuators']['smoke_pwm']);
            }
            if (isset($data['actuators']['damper_position'])) {
                $data['actuators']['damper_position'] = self::clampPercent((int)$data['actuators']['damper_position']);
            }
        }
        
        // Санитизация исполнительных механизмов (старый формат)
        if (isset($data['damper_percent'])) {
            $data['damper_percent'] = self::clampPercent((int)$data['damper_percent']);
        }
        
        // Санитизация системной информации
        if (isset($data['system']['stage_progress'])) {
            $data['system']['stage_progress'] = self::clampPercent((int)$data['system']['stage_progress']);
        }
        
        return $data;
    }
    
    /**
     * Ограничение температуры диапазоном
     */
    private static function clampTemperature($temp) {
        return max(self::TEMP_MIN, min(self::TEMP_MAX, $temp));
    }
    
    /**
     * Ограничение влажности диапазоном
     */
    private static function clampHumidity($humidity) {
        return max(self::HUMIDITY_MIN, min(self::HUMIDITY_MAX, $humidity));
    }
    
    /**
     * Ограничение PWM диапазоном
     */
    private static function clampPWM($pwm) {
        return max(self::PWM_MIN, min(self::PWM_MAX, $pwm));
    }
    
    /**
     * Ограничение процентов диапазоном
     */
    private static function clampPercent($percent) {
        return max(self::PERCENT_MIN, min(self::PERCENT_MAX, $percent));
    }
}
