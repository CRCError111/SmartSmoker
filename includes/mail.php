<?php
/**
 * Система отправки электронных писем через PHPMailer с резервным вариантом
 * ИСПРАВЛЕНО: Безопасная загрузка файлов + резервный вариант через mail()
 * ИСПРАВЛЕНО: Правильные заголовки From и Return-Path для доставки
 * 
 * @version 1.2
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

/**
 * Класс для отправки писем (с поддержкой PHPMailer и резервного варианта)
 */
class Mailer
{
    private static $instance = null;
    private $mailer = null;
    private $usePhpMailer = false;
    private $initialized = false;

    private function __construct()
    {
        $this->initialize();
    }

    private function __clone()
    {
    }
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initialize()
    {
        $mailEnabled = ($_ENV['MAIL_ENABLED'] ?? 'false') === 'true';

        if (!$mailEnabled) {
            logWarning('Почта отключена в настройках (MAIL_ENABLED=false), используется резервный вариант mail()', 'MAIL');
            $this->initialized = true;
            return;
        }

        // Проверка наличия ВСЕХ необходимых файлов PHPMailer
        $requiredFiles = [
            __DIR__ . '/PHPMailer.php',
            __DIR__ . '/SMTP.php',
            __DIR__ . '/Exception.php'
        ];

        $allFilesExist = true;
        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                logError("Отсутствует файл PHPMailer: " . basename($file), 'MAIL');
                $allFilesExist = false;
            }
        }

        if ($allFilesExist) {
            // Безопасная загрузка файлов PHPMailer
            try {
                require_once __DIR__ . '/Exception.php';
                require_once __DIR__ . '/PHPMailer.php';
                require_once __DIR__ . '/SMTP.php';

                $this->mailer = new \PHPMailer\PHPMailer\PHPMailer(true);

                // Настройки из .env
                $this->mailer->isSMTP();
                $this->mailer->Host = $_ENV['MAIL_HOST'] ?? 'smtp.gmail.com';
                $this->mailer->SMTPAuth = true;
                $this->mailer->Username = $_ENV['MAIL_USERNAME'] ?? '';
                $this->mailer->Password = $_ENV['MAIL_PASSWORD'] ?? '';

                // Определение типа шифрования
                $encryption = $_ENV['MAIL_ENCRYPTION'] ?? 'tls';
                if ($encryption === 'ssl') {
                    $this->mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                }
                else {
                    $this->mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }

                $this->mailer->Port = (int)($_ENV['MAIL_PORT'] ?? 587);

                // Настройки отправителя
                $from = $_ENV['MAIL_FROM'] ?? 'noreply@crcerror.ru';
                $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Smart Smoker';
                $this->mailer->setFrom($from, $fromName);

                // Кодировка
                $this->mailer->CharSet = 'UTF-8';
                $this->mailer->isHTML(true);

                $this->usePhpMailer = true;
                logInfo('PHPMailer инициализирован успешно', 'MAIL');

            }
            catch (Exception $e) {
                logException($e, 'MAIL');
                logWarning('Ошибка инициализации PHPMailer, используется резервный вариант mail()', 'MAIL');
            }
        }
        else {
            logWarning('Не все файлы PHPMailer найдены, используется резервный вариант mail()', 'MAIL');
        }

        $this->initialized = true;
    }

    /**
     * Отправить письмо с подтверждением регистрации
     */
    public function sendVerificationEmail($email, $name, $verificationUrl)
    {
        if (!$this->initialized)
            return false;

        $subject = 'Подтверждение регистрации в Smart Smoker';
        $htmlBody = $this->buildVerificationEmail($name, $verificationUrl);
        $textBody = $this->buildVerificationEmailText($name, $verificationUrl);

        return $this->send($email, $name, $subject, $htmlBody, $textBody);
    }

    /**
     * Отправить письмо с восстановлением пароля
     */
    public function sendPasswordResetEmail($email, $name, $resetUrl)
    {
        if (!$this->initialized)
            return false;

        $subject = 'Восстановление пароля в Smart Smoker';
        $htmlBody = $this->buildPasswordResetEmail($name, $resetUrl);
        $textBody = $this->buildPasswordResetEmailText($name, $resetUrl);

        return $this->send($email, $name, $subject, $htmlBody, $textBody);
    }

    /**
     * Отправить уведомление о новом устройстве
     */
    public function sendDeviceNotification($email, $name, $deviceName)
    {
        if (!$this->initialized)
            return false;

        $subject = 'Новое устройство добавлено в Smart Smoker';
        $htmlBody = $this->buildDeviceNotificationEmail($name, $deviceName);
        $textBody = $this->buildDeviceNotificationEmailText($name, $deviceName);

        return $this->send($email, $name, $subject, $htmlBody, $textBody);
    }

    /**
     * Отправить уведомление о завершении программы
     */
    public function sendProgramCompletionNotification($email, $name, $programName, $deviceName, $finalTemp)
    {
        if (!$this->initialized)
            return false;

        $subject = 'Программа копчения завершена';
        $htmlBody = $this->buildProgramCompletionEmail($name, $programName, $deviceName, $finalTemp);
        $textBody = $this->buildProgramCompletionEmailText($name, $programName, $deviceName, $finalTemp);

        return $this->send($email, $name, $subject, $htmlBody, $textBody);
    }

    /**
     * Отправить уведомление об аварийной остановке
     */
    public function sendEmergencyStopNotification($email, $name, $deviceName, $reason)
    {
        if (!$this->initialized)
            return false;

        $subject = '⚠️ АВАРИЙНАЯ ОСТАНОВКА — Smart Smoker';
        $htmlBody = $this->buildEmergencyStopEmail($name, $deviceName, $reason);
        $textBody = $this->buildEmergencyStopEmailText($name, $deviceName, $reason);

        return $this->send($email, $name, $subject, $htmlBody, $textBody);
    }

    /**
     * Универсальный метод отправки
     */
    private function send($email, $name, $subject, $htmlBody, $textBody)
    {
        try {
            if ($this->usePhpMailer && $this->mailer) {
                $this->mailer->clearAddresses();
                $this->mailer->addAddress($email, $name);
                $this->mailer->Subject = $subject;
                $this->mailer->Body = $htmlBody;
                $this->mailer->AltBody = $textBody;
                $this->mailer->send();
                logInfo("Письмо отправлено через PHPMailer на $email", 'MAIL');
                return true;
            }
            else {
                // Резервный вариант через встроенную функцию mail()
                return $this->sendViaMailFunction($email, $subject, $htmlBody);
            }
        }
        catch (Exception $e) {
            logException($e, 'MAIL');
            // Попытка отправить через резервный вариант
            if ($this->usePhpMailer) {
                logWarning('Повторная попытка отправки через mail()', 'MAIL');
                return $this->sendViaMailFunction($email, $subject, $htmlBody);
            }
            return false;
        }
    }

    /**
     * Отправка через встроенную функцию mail() с правильными заголовками
     */
    private function sendViaMailFunction($email, $subject, $body)
    {
        // Используем запасной адрес, если не задан в .env
        $from = $_ENV['MAIL_FROM'] ?? 'noreply@crcerror.ru';
        $fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Smart Smoker';

        // Формируем корректный заголовок From с именем
        $fromHeader = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>\r\n";

        // Дополнительные заголовки
        $headers = $fromHeader;
        $headers .= "Reply-To: $from\r\n";
        $headers .= "Return-Path: $from\r\n"; // КРИТИЧНО для доставки!
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $headers .= "X-Originating-IP: " . ($_SERVER['SERVER_ADDR'] ?? 'unknown') . "\r\n";

        // Устанавливаем обратный путь через параметр -f (envelope sender)
        $additionalParams = "-f$from -oi";

        // Логирование для отладки
        logInfo("Отправка письма через mail(): From=$from, To=$email", 'MAIL');

        // Отправка с дополнительными параметрами
        $result = mail($email, $subject, $body, $headers, $additionalParams);

        if ($result) {
            logInfo("Письмо успешно передано MTA: $email", 'MAIL');
        }
        else {
            logError("Ошибка передачи письма MTA: $email", 'MAIL');
        }

        return $result;
    }

    /**
     * Построить HTML-письмо для подтверждения регистрации
     */
    private function buildVerificationEmail($name, $verificationUrl)
    {
        return '
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение регистрации</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0;">Smart Smoker</h1>
        <p style="color: rgba(255, 255, 255, 0.9); margin: 10px 0 0 0;">Умная коптильня</p>
    </div>
    
    <div style="background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);">
        <h2 style="color: #333; margin-top: 0;">Здравствуйте, ' . htmlspecialchars($name) . '!</h2>
        
        <p>Спасибо за регистрацию в системе управления умной коптильней <strong>Smart Smoker</strong>!</p>
        
        <p>Для завершения регистрации и активации вашего аккаунта, пожалуйста, подтвердите ваш email по ссылке ниже:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="' . htmlspecialchars($verificationUrl) . '" 
               style="display: inline-block; padding: 15px 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">
                Подтвердить email
            </a>
        </div>
        
        <p style="text-align: center; margin: 20px 0; color: #666; font-size: 14px;">
            Или скопируйте и вставьте эту ссылку в адресную строку браузера:<br>
            <a href="' . htmlspecialchars($verificationUrl) . '" style="color: #667eea; word-break: break-all;">' . htmlspecialchars($verificationUrl) . '</a>
        </p>
        
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <p style="margin: 0; color: #856404;">
                <strong>Важно:</strong> Ссылка действительна в течение <strong>7 дней</strong>. 
                Если вы не подтвердите регистрацию в течение этого времени, ваш аккаунт будет автоматически удалён.
            </p>
        </div>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 14px;">
            Если вы не регистрировались в системе <strong>Smart Smoker</strong>, просто проигнорируйте это письмо.
        </p>
        
        <p style="color: #666; font-size: 14px; margin-top: 20px;">
            С уважением,<br>
            <strong>Команда Smart Smoker</strong>
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px; font-size: 12px; color: #666;">
        <p style="margin: 0;">
            Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.
        </p>
        <p style="margin: 5px 0 0 0;">
            &copy; ' . date('Y') . ' Smart Smoker. Все права защищены.
        </p>
    </div>
</body>
</html>
';
    }

    /**
     * Построить текстовую версию письма для подтверждения регистрации
     */
    private function buildVerificationEmailText($name, $verificationUrl)
    {
        return "Здравствуйте, $name!\n\n" .
            "Спасибо за регистрацию в системе управления умной коптильней Smart Smoker!\n\n" .
            "Для завершения регистрации и активации вашего аккаунта, пожалуйста, подтвердите ваш email по ссылке:\n" .
            "$verificationUrl\n\n" .
            "Важно: Ссылка действительна в течение 7 дней.\n" .
            "Если вы не подтвердите регистрацию в течение этого времени, ваш аккаунт будет автоматически удалён.\n\n" .
            "Если вы не регистрировались в системе Smart Smoker, просто проигнорируйте это письмо.\n\n" .
            "С уважением,\n" .
            "Команда Smart Smoker\n\n" .
            "Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.";
    }

    /**
     * Построить письмо для восстановления пароля
     */
    private function buildPasswordResetEmail($name, $resetUrl)
    {
        return '
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0;">Smart Smoker</h1>
        <p style="color: rgba(255, 255, 255, 0.9); margin: 10px 0 0 0;">Умная коптильня</p>
    </div>
    
    <div style="background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);">
        <h2 style="color: #333; margin-top: 0;">Здравствуйте, ' . htmlspecialchars($name) . '!</h2>
        
        <p>Мы получили запрос на восстановление пароля для вашего аккаунта в системе <strong>Smart Smoker</strong>.</p>
        
        <p>Если это были вы, пожалуйста, перейдите по ссылке ниже для создания нового пароля:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="' . htmlspecialchars($resetUrl) . '" 
               style="display: inline-block; padding: 15px 40px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">
                Восстановить пароль
            </a>
        </div>
        
        <p style="text-align: center; margin: 20px 0; color: #666; font-size: 14px;">
            Или скопируйте и вставьте эту ссылку в адресную строку браузера:<br>
            <a href="' . htmlspecialchars($resetUrl) . '" style="color: #667eea; word-break: break-all;">' . htmlspecialchars($resetUrl) . '</a>
        </p>
        
        <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 5px;">
            <p style="margin: 0; color: #856404;">
                <strong>Важно:</strong> Ссылка действительна в течение <strong>1 часа</strong>. 
                Если вы не воспользуетесь ей в течение этого времени, запрос будет отменён.
            </p>
        </div>
        
        <p style="color: #666;">
            Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо. 
            Ваш пароль останется неизменным.
        </p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 14px; margin-top: 20px;">
            С уважением,<br>
            <strong>Команда Smart Smoker</strong>
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px; font-size: 12px; color: #666;">
        <p style="margin: 0;">
            Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.
        </p>
        <p style="margin: 5px 0 0 0;">
            &copy; ' . date('Y') . ' Smart Smoker. Все права защищены.
        </p>
    </div>
</body>
</html>
';
    }

    private function buildPasswordResetEmailText($name, $resetUrl)
    {
        return "Здравствуйте, $name!\n\n" .
            "Мы получили запрос на восстановление пароля для вашего аккаунта в системе Smart Smoker.\n\n" .
            "Если это были вы, пожалуйста, перейдите по ссылке ниже для создания нового пароля:\n" .
            "$resetUrl\n\n" .
            "Важно: Ссылка действительна в течение 1 часа.\n" .
            "Если вы не воспользуетесь ей в течение этого времени, запрос будет отменён.\n\n" .
            "Если вы не запрашивали восстановление пароля, просто проигнорируйте это письмо.\n" .
            "Ваш пароль останется неизменным.\n\n" .
            "С уважением,\n" .
            "Команда Smart Smoker\n\n" .
            "Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.";
    }

    /**
     * Построить письмо уведомления о новом устройстве
     */
    private function buildDeviceNotificationEmail($name, $deviceName)
    {
        return '
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новое устройство добавлено</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0;">Smart Smoker</h1>
        <p style="color: rgba(255, 255, 255, 0.9); margin: 10px 0 0 0;">Умная коптильня</p>
    </div>
    
    <div style="background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);">
        <h2 style="color: #333; margin-top: 0;">Здравствуйте, ' . htmlspecialchars($name) . '!</h2>
        
        <p>В ваш аккаунт было добавлено новое устройство:</p>
        
        <div style="background: #e7f3ff; border-left: 4px solid #2196F3; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <p style="margin: 0; font-size: 18px; font-weight: bold; color: #1976d2;">
                ' . htmlspecialchars($deviceName) . '
            </p>
        </div>
        
        <p>Теперь вы можете управлять этим устройством через веб-интерфейс или мобильное приложение.</p>
        
        <p style="color: #666; margin-top: 20px;">
            <a href="' . BASE_URL . '/devices.php" style="color: #667eea;">Перейти к управлению устройствами</a>
        </p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 14px; margin-top: 20px;">
            С уважением,<br>
            <strong>Команда Smart Smoker</strong>
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px; font-size: 12px; color: #666;">
        <p style="margin: 0;">
            Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.
        </p>
        <p style="margin: 5px 0 0 0;">
            &copy; ' . date('Y') . ' Smart Smoker. Все права защищены.
        </p>
    </div>
</body>
</html>
';
    }

    private function buildDeviceNotificationEmailText($name, $deviceName)
    {
        return "Здравствуйте, $name!\n\n" .
            "В ваш аккаунт было добавлено новое устройство:\n" .
            "$deviceName\n\n" .
            "Теперь вы можете управлять этим устройством через веб-интерфейс или мобильное приложение.\n\n" .
            "Перейти к управлению устройствами: " . BASE_URL . "/devices.php\n\n" .
            "С уважением,\n" .
            "Команда Smart Smoker\n\n" .
            "Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.";
    }

    /**
     * Построить письмо уведомления о завершении программы
     */
    private function buildProgramCompletionEmail($name, $programName, $deviceName, $finalTemp)
    {
        return '
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Программа копчения завершена</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0;">Smart Smoker</h1>
        <p style="color: rgba(255, 255, 255, 0.9); margin: 10px 0 0 0;">Умная коптильня</p>
    </div>
    
    <div style="background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);">
        <h2 style="color: #333; margin-top: 0;">Здравствуйте, ' . htmlspecialchars($name) . '!</h2>
        
        <p style="font-size: 18px; font-weight: bold; color: #28a745;">
            Программа копчения успешно завершена!
        </p>
        
        <div style="background: #d4edda; border-left: 4px solid #28a745; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #155724;">Программа:</td>
                    <td style="padding: 8px 0; color: #155724;">' . htmlspecialchars($programName) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #155724;">Устройство:</td>
                    <td style="padding: 8px 0; color: #155724;">' . htmlspecialchars($deviceName) . '</td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-weight: bold; color: #155724;">Температура продукта:</td>
                    <td style="padding: 8px 0; color: #155724; font-size: 18px; font-weight: bold;">' . number_format($finalTemp, 1) . ' °C</td>
                </tr>
            </table>
        </div>
        
        <p>Ваш продукт готов! Пожалуйста, проверьте устройство и извлеките готовый продукт.</p>
        
        <p style="color: #666; margin-top: 20px;">
            <a href="' . BASE_URL . '/view-device.php" style="color: #28a745;">Посмотреть детали программы</a>
        </p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 14px; margin-top: 20px;">
            С уважением,<br>
            <strong>Команда Smart Smoker</strong>
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px; font-size: 12px; color: #666;">
        <p style="margin: 0;">
            Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.
        </p>
        <p style="margin: 5px 0 0 0;">
            &copy; ' . date('Y') . ' Smart Smoker. Все права защищены.
        </p>
    </div>
</body>
</html>
';
    }

    private function buildProgramCompletionEmailText($name, $programName, $deviceName, $finalTemp)
    {
        return "Здравствуйте, $name!\n\n" .
            "Программа копчения успешно завершена!\n\n" .
            "Детали:\n" .
            "Программа: $programName\n" .
            "Устройство: $deviceName\n" .
            "Температура продукта: " . number_format($finalTemp, 1) . " °C\n\n" .
            "Ваш продукт готов! Пожалуйста, проверьте устройство и извлеките готовый продукт.\n\n" .
            "С уважением,\n" .
            "Команда Smart Smoker\n\n" .
            "Это письмо отправлено автоматически. Пожалуйста, не отвечайте на него.";
    }

    /**
     * Построить HTML-письмо для аварийной остановки
     */
    private function buildEmergencyStopEmail($name, $deviceName, $reason)
    {
        return '
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Аварийная остановка</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
        <h1 style="color: white; margin: 0;">Smart Smoker</h1>
        <p style="color: rgba(255, 255, 255, 0.9); margin: 10px 0 0 0;">КРИТИЧЕСКОЕ УВЕДОМЛЕНИЕ</p>
    </div>
    
    <div style="background: white; padding: 30px; border-radius: 0 0 10px 10px; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);">
        <h2 style="color: #333; margin-top: 0;">Здравствуйте, ' . htmlspecialchars($name) . '!</h2>
        
        <p style="font-size: 18px; font-weight: bold; color: #e74c3c;">
            ⚠️ Внимание! Произошла аварийная остановка устройства.
        </p>
        
        <div style="background: #fdeaea; border-left: 4px solid #e74c3c; padding: 20px; margin: 20px 0; border-radius: 5px;">
            <p style="margin: 0 0 10px 0;"><strong>Устройство:</strong> ' . htmlspecialchars($deviceName) . '</p>
            <p style="margin: 0 0 10px 0;"><strong>Причина:</strong> ' . htmlspecialchars($reason) . '</p>
            <p style="margin: 0;"><strong>Время:</strong> ' . date('d.m.Y H:i:s') . '</p>
        </div>
        
        <p>Для вашей безопасности все исполнительные механизмы коптильни были немедленно отключены.</p>
        
        <p style="text-align: center; margin: 30px 0;">
            <a href="' . BASE_URL . '/view-device.php" 
               style="display: inline-block; padding: 15px 40px; background: #e74c3c; color: white; text-decoration: none; border-radius: 8px; font-weight: 600; font-size: 16px;">
                Панель управления
            </a>
        </p>
        
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        
        <p style="color: #666; font-size: 14px; margin-top: 20px;">
            С уважением,<br>
            <strong>Команда Smart Smoker</strong>
        </p>
    </div>
    
    <div style="text-align: center; margin-top: 20px; padding: 20px; background: #f8f9fa; border-radius: 10px; font-size: 12px; color: #666;">
        <p style="margin: 0;">Это письмо отправлено в связи с критическим событием безопасности.</p>
        <p style="margin: 5px 0 0 0;">&copy; ' . date('Y') . ' Smart Smoker. Все права защищены.</p>
    </div>
</body>
</html>
';
    }

    /**
     * Построить текстовую версию письма для аварийной остановки
     */
    private function buildEmergencyStopEmailText($name, $deviceName, $reason)
    {
        return "Здравствуйте, $name!\n\n" .
            "⚠️ ВНИМАНИЕ! Произошла аварийная остановка устройства Smart Smoker.\n\n" .
            "Детали происшествия:\n" .
            "Устройство: $deviceName\n" .
            "Причина: $reason\n" .
            "Время: " . date('d.m.Y H:i:s') . "\n\n" .
            "Для вашей безопасности все исполнительные механизмы коптильни были немедленно отключены.\n\n" .
            "Панель управления: " . BASE_URL . "/view-device.php\n\n" .
            "С уважением,\n" .
            "Команда Smart Smoker";
    }

    /**
     * Получить статус почтовой системы
     */
    public function getStatus()
    {
        return [
            'enabled' => $this->initialized,
            'use_phpmailer' => $this->usePhpMailer,
            'configured' => ($_ENV['MAIL_ENABLED'] ?? 'false') === 'true',
            'from' => $_ENV['MAIL_FROM'] ?? 'not configured'
        ];
    }
}

/**
 * Удобная функция для получения экземпляра почтовой системы
 */
function mailer()
{
    return Mailer::getInstance();
}

/**
 * Отправить письмо с подтверждением регистрации
 */
function sendVerificationEmail($email, $name, $verificationUrl)
{
    try {
        return mailer()->sendVerificationEmail($email, $name, $verificationUrl);
    }
    catch (Exception $e) {
        logException($e, 'MAIL');
        return false;
    }
}

/**
 * Отправить письмо с восстановлением пароля
 */
function sendPasswordResetEmail($email, $name, $resetUrl)
{
    try {
        return mailer()->sendPasswordResetEmail($email, $name, $resetUrl);
    }
    catch (Exception $e) {
        logException($e, 'MAIL');
        return false;
    }
}

/**
 * Отправить уведомление о новом устройстве
 */
function sendDeviceNotification($email, $name, $deviceName)
{
    try {
        return mailer()->sendDeviceNotification($email, $name, $deviceName);
    }
    catch (Exception $e) {
        logException($e, 'MAIL');
        return false;
    }
}

/**
 * Отправить уведомление об аварийной остановке
 */
function sendEmergencyStopNotification($email, $name, $deviceName, $reason)
{
    try {
        return mailer()->sendEmergencyStopNotification($email, $name, $deviceName, $reason);
    }
    catch (Exception $e) {
        logException($e, 'MAIL');
        return false;
    }
}

/**
 * Отправить уведомление о завершении программы
 */
function sendProgramCompletionNotification($email, $name, $programName, $deviceName, $finalTemp)
{
    try {
        return mailer()->sendProgramCompletionNotification($email, $name, $programName, $deviceName, $finalTemp);
    }
    catch (Exception $e) {
        logException($e, 'MAIL');
        return false;
    }
}

// =====================================================
// Завершение файла
// =====================================================