<?php
/**
 * Страница экстренного сброса привязки контроллера
 * Используется когда устройство было удалено с сайта, но на контроллере осталась привязка
 * 
 * @version 1.0
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireAuth();

$user = Auth::user();

$pageTitle = 'Экстренный сброс привязки';
include __DIR__ . '/templates/header.php';
?>

<style>
.emergency-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.1);
    padding: 30px;
    max-width: 600px;
    margin: 0 auto;
}

.warning-box {
    background: linear-gradient(135deg, #f093fb, #f5576c);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.info-box {
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.result-box {
    padding: 20px;
    border-radius: 8px;
    margin-top: 20px;
    display: none;
}

.result-box.success {
    background: linear-gradient(135deg, rgba(17, 153, 142, 0.1), rgba(56, 239, 125, 0.1));
    border: 2px solid #11998e;
}

.result-box.error {
    background: linear-gradient(135deg, rgba(240, 147, 251, 0.1), rgba(245, 87, 108, 0.1));
    border: 2px solid #f5576c;
}
</style>

<div class="emergency-card">
    <h2 class="mb-4">⚠️ Экстренный сброс привязки</h2>
    
    <div class="warning-box">
        <h5>⚠️ Внимание!</h5>
        <p class="mb-0">Эта функция используется только в экстренных случаях, когда устройство было удалено с сайта, но на контроллере осталась привязка.</p>
    </div>
    
    <div class="info-box">
        <h6>📋 Когда использовать:</h6>
        <ul class="mb-0">
            <li>Устройство было удалено с сайта до завершения процесса отвязки</li>
            <li>На контроллере остался файл binding.json</li>
            <li>Контроллер не может завершить отвязку (устройство не найдено на сервере)</li>
        </ul>
    </div>
    
    <form id="emergencyUnbindForm">
        <div class="mb-3">
            <label for="esp32_ip" class="form-label">🌐 IP адрес контроллера *</label>
            <input 
                type="text" 
                class="form-control" 
                id="esp32_ip" 
                name="esp32_ip" 
                placeholder="Например: 192.168.1.100"
                required
                pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$"
            >
            <div class="form-text">Введите IP адрес вашего контроллера в локальной сети</div>
        </div>
        
        <div class="alert alert-info">
            <strong>ℹ️ Как узнать IP адрес контроллера:</strong>
            <ul class="mb-0 mt-2">
                <li>Посмотрите на дисплее контроллера (если есть)</li>
                <li>Проверьте в настройках роутера (список подключенных устройств)</li>
                <li>Используйте приложение для сканирования сети (например, Fing)</li>
            </ul>
        </div>
        
        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-danger btn-lg" id="submitBtn">
                🚨 Отправить команду сброса
            </button>
            <a href="<?= BASE_URL ?>/devices.php" class="btn btn-secondary">
                ← Назад к устройствам
            </a>
        </div>
    </form>
    
    <div id="resultBox" class="result-box">
        <div id="resultContent"></div>
    </div>
</div>

<script>
document.getElementById('emergencyUnbindForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = document.getElementById('submitBtn');
    const resultBox = document.getElementById('resultBox');
    const resultContent = document.getElementById('resultContent');
    const esp32Ip = document.getElementById('esp32_ip').value;
    
    // Валидация IP
    const ipPattern = /^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/;
    if (!ipPattern.test(esp32Ip)) {
        alert('Неверный формат IP адреса');
        return;
    }
    
    // Подтверждение
    if (!confirm(`Вы уверены, что хотите отправить команду экстренного сброса на контроллер ${esp32Ip}?\n\nЭто удалит файл binding.json на контроллере.`)) {
        return;
    }
    
    // Отключаем кнопку
    submitBtn.disabled = true;
    submitBtn.innerHTML = '⏳ Отправка команды...';
    
    // Скрываем предыдущий результат
    resultBox.style.display = 'none';
    
    // Отправка запроса
    fetch('<?= BASE_URL ?>/api/emergency-unbind.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            esp32_ip: esp32Ip
        })
    })
    .then(response => response.json())
    .then(data => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '🚨 Отправить команду сброса';
        
        resultBox.style.display = 'block';
        
        if (data.success) {
            resultBox.className = 'result-box success';
            resultContent.innerHTML = `
                <h5>✅ Успешно!</h5>
                <p>${data.message}</p>
                ${data.esp32_response ? `<p class="mb-0"><small>Ответ контроллера: ${JSON.stringify(data.esp32_response, null, 2)}</small></p>` : ''}
            `;
        } else {
            resultBox.className = 'result-box error';
            resultContent.innerHTML = `
                <h5>❌ Ошибка</h5>
                <p>${data.error}</p>
                <p class="mb-0"><small>Убедитесь, что:</small></p>
                <ul class="mb-0">
                    <li>IP адрес указан правильно</li>
                    <li>Контроллер включен и подключен к сети</li>
                    <li>Контроллер доступен из вашей сети</li>
                </ul>
            `;
        }
    })
    .catch(error => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '🚨 Отправить команду сброса';
        
        resultBox.style.display = 'block';
        resultBox.className = 'result-box error';
        resultContent.innerHTML = `
            <h5>❌ Ошибка сети</h5>
            <p>Не удалось отправить запрос: ${error.message}</p>
        `;
    });
});
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
