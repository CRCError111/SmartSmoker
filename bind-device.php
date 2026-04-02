<?php
/**
 * Страница привязки устройства к контроллеру ESP32
 * Инициируется только с сайта (Website-Only Device Binding)
 * 
 * @version 2.0
 * @author Smart Smoker Team
 */

// Определение константы для доступа к файлам
define('SMART_SMOKER', true);

// Подключение конфигурации
require_once __DIR__ . '/config.php';

// Подключение модулей
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Требуется авторизация
Auth::requireAuth();

$user = Auth::user();

$pageTitle = 'Привязка устройства';
require_once __DIR__ . '/templates/header.php';
?>

<style>
    .step-card {
        border-left: 4px solid #667eea;
        margin-bottom: 20px;
        padding-left: 20px;
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
    }
    
    .step-number {
        display: inline-block;
        width: 35px;
        height: 35px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 50%;
        text-align: center;
        line-height: 35px;
        font-weight: bold;
        margin-right: 12px;
        font-size: 1.1rem;
    }
    
    .info-box {
        background: #e7f3ff;
        border-left: 4px solid #2196F3;
        padding: 15px;
        border-radius: 8px;
        margin: 15px 0;
    }
    
    .warning-box {
        background: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 15px;
        border-radius: 8px;
        margin: 15px 0;
    }
    
    .code-block {
        background: #2d2d2d;
        color: #f8f8f2;
        border-radius: 8px;
        padding: 15px;
        font-family: 'Courier New', monospace;
        font-size: 0.9rem;
        margin: 10px 0;
        overflow-x: auto;
    }
</style>


<!-- Инструкция по привязке устройства -->
<div class="card mb-4">
    <div class="card-header">
        <i class="fas fa-book"></i> Как привязать устройство к вашей учетной записи
    </div>
    <div class="card-body">
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> <strong>Важно:</strong> Привязка устройства теперь выполняется через веб-интерфейс контроллера. Следуйте инструкциям ниже для завершения процесса привязки.
        </div>
        
        <div class="info-box">
            <strong>ℹ️ Device ID устройства:</strong>
            <div class="code-block" id="device-id-display">
                Загрузка...
            </div>
            <p class="mb-0 mt-2">
                <small>Этот Device ID хранится в энергонезависимой памяти ESP32 и сохранится после перепрошивки.</small>
            </p>
        </div>
        
        <div class="step-card">
            <h5><span class="step-number">1</span> Доступ к веб-интерфейсу контроллера</h5>
            <p>Откройте веб-браузер и перейдите по IP адресу вашего контроллера ESP32:</p>
            <div class="code-block">
                http://[IP_адрес_контроллера]
            </div>
            <p class="mt-2">Например: <code>http://192.168.1.105</code></p>
            <div class="info-box">
                <strong>ℹ️ Как узнать IP адрес контроллера:</strong>
                <ul class="mb-0 mt-2">
                    <li>Посмотрите на OLED-дисплее контроллера</li>
                    <li>Проверьте список подключенных устройств в настройках роутера</li>
                </ul>
            </div>
        </div>
        
        <div class="step-card">
            <h5><span class="step-number">2</span> Перейдите на страницу привязки</h5>
            <p>В веб-интерфейсе контроллера найдите и откройте раздел "Привязка устройства" или "Device Binding".</p>
        </div>
        
        <div class="step-card">
            <h5><span class="step-number">3</span> Введите учетные данные сайта</h5>
            <p>На странице привязки контроллера введите следующие данные:</p>
            <ul>
                <li><strong>Имя пользователя или Email:</strong> <?php echo e($user['username']); ?></li>
                <li><strong>Пароль:</strong> Ваш пароль от учетной записи на этом сайте</li>
            </ul>
            <div class="warning-box">
                <strong>⚠️ Безопасность:</strong> Убедитесь, что вы подключены к контроллеру через защищенную домашнюю сеть. Не вводите учетные данные в публичных сетях.
            </div>
        </div>
        
        <div class="step-card">
            <h5><span class="step-number">4</span> Подтвердите привязку</h5>
            <p>Нажмите кнопку "Привязать" или "Bind Device" на странице контроллера. Контроллер отправит запрос на сервер для подтверждения привязки.</p>
        </div>
        
        <div class="step-card">
            <h5><span class="step-number">5</span> Проверьте привязку на панели управления</h5>
            <p>После успешной привязки вернитесь на эту страницу и перейдите в раздел "Устройства" для просмотра вашего привязанного контроллера.</p>
            <div class="d-grid gap-2 mt-3">
                <a href="<?php echo BASE_URL; ?>/devices.php" class="btn btn-primary">
                    <i class="fas fa-list"></i> Перейти к списку устройств
                </a>
            </div>
        </div>
    </div>
</div>


<!-- Часто задаваемые вопросы -->
<div class="card mt-4">
    <div class="card-header">
        <i class="fas fa-question-circle"></i> Часто задаваемые вопросы
    </div>
    <div class="card-body">
        <div class="mb-4">
            <h6><strong>Q: Где находится веб-интерфейс контроллера?</strong></h6>
            <p class="mb-0 text-muted">A: Откройте браузер и введите IP адрес контроллера (например, http://192.168.1.105). IP адрес отображается на OLED-дисплее контроллера.</p>
        </div>
        
        <div class="mb-4">
            <h6><strong>Q: Что делать, если контроллер не подключается к сети?</strong></h6>
            <p class="mb-0 text-muted">A: Перезагрузите контроллер и проверьте настройки Wi-Fi. Убедитесь, что пароль от сети введен правильно. Если контроллер в режиме AP, подключитесь к его точке доступа и настройте подключение к домашней сети.</p>
        </div>
        
        <div class="mb-4">
            <h6><strong>Q: Можно ли привязать одно устройство к нескольким учетным записям?</strong></h6>
            <p class="mb-0 text-muted">A: Нет, каждое устройство может быть привязано только к одной учетной записи. Если вы попытаетесь привязать устройство, которое уже привязано к другому пользователю, система выдаст ошибку.</p>
        </div>
        
        <div class="mb-4">
            <h6><strong>Q: Что делать, если привязка не удается?</strong></h6>
            <p class="mb-0 text-muted">A: Убедитесь, что контроллер подключен к интернету и вы правильно ввели учетные данные. Проверьте, что вы используете правильное имя пользователя и пароль от учетной записи на сайте.</p>
        </div>
        
        <div class="mb-0">
            <h6><strong>Q: Безопасно ли вводить пароль в веб-интерфейсе контроллера?</strong></h6>
            <p class="mb-0 text-muted">A: Да, если вы подключены к контроллеру через защищенную домашнюю сеть. Контроллер использует безопасное соединение с сервером для проверки учетных данных. Не вводите пароли в публичных сетях.</p>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/templates/footer.php';

// Device ID для отображения (пока заглушка)
$deviceId = '548fd57d-c690-4a49-a20c-40a12504befc';
?>

<script>
// Загрузка Device ID из ESP32
document.addEventListener('DOMContentLoaded', function() {
    const deviceIdDisplay = document.getElementById('device-id-display');
    
    // Пока заглушка - в реальной реализации будет запрос к ESP32
    deviceIdDisplay.textContent = '<?php echo e($deviceId); ?>';
    
    // В будущем можно добавить:
    // fetch('/api/get-device-id.php', { method: 'POST', ... })
    //     .then(r => r.json())
    //     .then(data => {
    //         if (data.success) {
    //             deviceIdDisplay.textContent = data.device_id;
    //         }
    //     });
});
</script>
