<?php
/**
 * Страница просмотра устройства с графиками
 * 
 * @version 1.0
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
$deviceId = (int)($_GET['id'] ?? 0);

// Получение устройства
$device = getDevice($deviceId);

if (!$device) {
    redirect(BASE_URL . '/devices.php?error=device_not_found');
}

// Получение текущего запуска программы (если есть)
$db = db();
$currentRun = $db->fetchOne(
    'SELECT * FROM runs WHERE device_id = ? AND status = "running" ORDER BY started_at DESC LIMIT 1',
[$device['device_id']]
);

// Получение последних 10 запусков программ из таблицы runs
$chartData = [];
$runs = $db->fetchAll(
    'SELECT r.run_id, r.program_name, r.started_at, COALESCE(r.finished_at, NOW()) as finished_at
     FROM runs r
     WHERE r.device_id = ?
     ORDER BY r.started_at DESC
     LIMIT 10',
[$device['device_id']]
);

foreach ($runs as $run) {
    // Если run_id есть в sensor_data — используем его для точной выборки
    if (!empty($run['run_id'])) {
        $runData = $db->fetchAll(
            'SELECT * FROM sensor_data
             WHERE device_id = ? AND run_id = ?
             ORDER BY timestamp ASC',
        [$device['device_id'], $run['run_id']]
        );
    } else {
        // Fallback: выборка по временному диапазону (для старых записей без run_id)
        $runData = $db->fetchAll(
            'SELECT * FROM sensor_data
             WHERE device_id = ? 
             AND timestamp BETWEEN ? AND ?
             ORDER BY timestamp ASC',
        [$device['device_id'], $run['started_at'], $run['finished_at']]
        );
    }

    if (!empty($runData)) {
        $chartData[] = [
            'program' => $run['program_name'],
            'start_time' => $run['started_at'],
            'end_time' => $run['finished_at'],
            'data' => $runData
        ];
    }
}

// Если нет данных по программам, показываем последние 24 часа
if (empty($chartData)) {
    $last24Hours = $db->fetchAll(
        'SELECT * FROM sensor_data 
         WHERE device_id = ? 
         AND timestamp >= NOW() - INTERVAL 24 HOUR 
         ORDER BY timestamp ASC',
    [$device['device_id']]
    );

    if (!empty($last24Hours)) {
        $chartData[] = [
            'program' => 'Последние 24 часа',
            'start_time' => $last24Hours[0]['timestamp'],
            'end_time' => $last24Hours[count($last24Hours) - 1]['timestamp'],
            'data' => $last24Hours
        ];
    }
}

// Получение статистики
$programCount = $db->fetchColumn(
    'SELECT COUNT(*) FROM programs WHERE user_id = ?',
[$user['id']]
);

$runCount = $db->fetchColumn(
    'SELECT COUNT(*) FROM runs WHERE device_id = ?',
[$device['device_id']]
);

$activeRunCount = $db->fetchColumn(
    'SELECT COUNT(*) FROM runs WHERE device_id = ? AND status = "running"',
[$device['device_id']]
);

$pageTitle = 'Устройство: ' . $device['name'];
include __DIR__ . '/templates/header.php';
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>

<!-- Device Info -->
<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">ℹ️ Информация об устройстве</h5>
            <?php
$statusInfo = formatDeviceStatus($device['status']);
?>
            <span class="badge bg-<?php echo $statusInfo[1]; ?>">
                <?php echo $statusInfo[0]; ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <!-- Current Run Alert -->
        <?php if ($currentRun): ?>
        <div class="alert alert-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h6>▶️ Выполняется программа: <?php echo e($currentRun['program_name']); ?></h6>
                    <small>Запущена: <?php echo formatDate($currentRun['started_at']); ?></small>
                </div>
                <button class="btn btn-danger btn-sm" onclick="emergencyStop()">
                    ⏹️ Аварийная остановка
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Smoke Ignition Alert (shown when device is in WAITING_SMOKE_IGNITION mode) -->
        <div id="smoke-ignition-alert" style="display:none;background:#fff3cd;border:2px solid #ffc107;border-radius:8px;padding:16px;margin-bottom:16px">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 style="color:#856404;margin:0">🔥 Подожгите дымогенератор!</h6>
                    <small style="color:#856404">Компрессор запущен. Розжигайте щепу и подтвердите готовность.</small>
                </div>
                <button class="btn btn-warning btn-sm" onclick="confirmSmokeIgnition()" id="smoke-confirm-btn">
                    ✅ Дымогенератор готов
                </button>
            </div>
        </div>        
        <!-- Stats -->
        <div class="row text-center mb-3">
            <div class="col-md-3">
                <h3>🌡️</h3>
                <h4><?php
$lastData = null;
if (!empty($chartData) && !empty($chartData[0]['data'])) {
    $lastData = reset($chartData[0]['data']);
}
echo $lastData ? formatTemperature($lastData['temp_chamber']) : '—';
?></h4>
                <small class="text-muted">Температура камеры</small>
            </div>
            <div class="col-md-3">
                <h3>💧</h3>
                <h4><?php echo $lastData ? formatHumidity($lastData['humidity']) : '—'; ?></h4>
                <small class="text-muted">Влажность</small>
            </div>
            <div class="col-md-3">
                <h3>💨</h3>
                <h4><?php echo $lastData ? formatTemperature($lastData['temp_smoke']) : '—'; ?></h4>
                <small class="text-muted">Температура дыма</small>
            </div>
            <div class="col-md-3">
                <h3>🍴</h3>
                <h4><?php echo $lastData ? formatTemperature($lastData['temp_product']) : '—'; ?></h4>
                <small class="text-muted">Температура продукта</small>
            </div>
        </div>
        
        <!-- Actions -->
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>/programs.php?device_id=<?php echo $device['id']; ?>" class="btn btn-primary btn-sm">
                📋 Управление программами
            </a>
            <a href="<?php echo BASE_URL; ?>/edit-device.php?id=<?php echo $device['id']; ?>" class="btn btn-outline-primary btn-sm">
                ✏️ Настройки
            </a>
            <?php if ($device['status'] === 'pending'): ?>
            <a href="<?php echo BASE_URL; ?>/bind-device.php?id=<?php echo $device['id']; ?>" class="btn btn-outline-primary btn-sm">
                🔗 Привязать
            </a>
            <?php
else: ?>
            <span class="badge bg-success">✅ Привязано</span>
            <?php
endif; ?>
        </div>
    </div>
</div>

<!-- Temperature Chart -->
<div class="chart-container">
    <div class="chart-header">
        <div class="chart-title">
            📈 Температура (последние 10 программ)
        </div>
        <div class="chart-actions">
            <button class="btn btn-sm btn-outline-primary" onclick="refreshChart()">
                🔄 Обновить
            </button>
        </div>
    </div>
    <div class="chart-wrapper">
        <canvas id="temperatureChart"></canvas>
    </div>
</div>

<!-- Humidity Chart -->
<div class="chart-container">
    <div class="chart-header">
        <div class="chart-title">
            📈 Влажность (последние 10 программ)
        </div>
    </div>
    <div class="chart-wrapper">
        <canvas id="humidityChart"></canvas>
    </div>
</div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Подготовка данных для графиков (последние 10 программ)
        const chartData = <?php echo json_encode($chartData); ?>;
        
        // Переменные для хранения экземпляров графиков
        let tempChart = null;
        let humidityChart = null;
        
        // Функция для форматирования времени
        function formatTime(timestamp) {
            const date = new Date(timestamp);
            return date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
        }
        
        // Функция инициализации графиков
        function initCharts() {
            // Уничтожаем старые графики если они существуют
            if (tempChart) {
                tempChart.destroy();
            }
            if (humidityChart) {
                humidityChart.destroy();
            }
            
            // Подготовка данных для отображения
            const labels = [];
            const tempChamberData = [];
            const tempSmokeData = [];
            const tempProductData = [];
            const humidityData = [];
            
            // Объединяем данные из всех запусков
            chartData.forEach((run, index) => {
                run.data.forEach((point, pointIndex) => {
                    // Добавляем метку с названием программы для первой точки
                    if (pointIndex === 0) {
                        labels.push(run.program + ' (начало)');
                    } else if (pointIndex === run.data.length - 1) {
                        labels.push(run.program + ' (конец)');
                    } else {
                        labels.push(formatTime(point.timestamp));
                    }
                    
                    tempChamberData.push(point.temp_chamber !== null ? parseFloat(point.temp_chamber) : null);
                    tempSmokeData.push(point.temp_smoke !== null ? parseFloat(point.temp_smoke) : null);
                    tempProductData.push(point.temp_product !== null ? parseFloat(point.temp_product) : null);
                    humidityData.push(point.humidity !== null ? parseFloat(point.humidity) : null);
                });
                
                // Добавляем разделитель между программами (null создаёт разрыв в линии)
                if (index < chartData.length - 1) {
                    labels.push('---');
                    tempChamberData.push(null);
                    tempSmokeData.push(null);
                    tempProductData.push(null);
                    humidityData.push(null);
                }
            });
            
            // Температурный график
            const tempCtx = document.getElementById('temperatureChart').getContext('2d');
            tempChart = new Chart(tempCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Температура камеры (°C)',
                        data: tempChamberData,
                        borderColor: '#f63457',
                        backgroundColor: 'rgba(246, 52, 87, 0.1)',
                        tension: 0.4,
                        fill: true,
                        spanGaps: false
                    },
                    {
                        label: 'Температура дыма (°C)',
                        data: tempSmokeData,
                        borderColor: '#667eea',
                        backgroundColor: 'rgba(102, 126, 234, 0.1)',
                        tension: 0.4,
                        fill: true,
                        spanGaps: false
                    },
                    {
                        label: 'Температура продукта (°C)',
                        data: tempProductData,
                        borderColor: '#f39c12',
                        backgroundColor: 'rgba(243, 156, 18, 0.1)',
                        tension: 0.4,
                        fill: true,
                        spanGaps: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        title: {
                            display: true,
                            text: 'Температура (°C)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Время'
                        }
                    }
                }
            }
        });
        
        // График влажности
        const humidityCtx = document.getElementById('humidityChart').getContext('2d');
        humidityChart = new Chart(humidityCtx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Влажность (%)',
                    data: humidityData,
                    borderColor: '#4dc95f',
                    backgroundColor: 'rgba(77, 201, 95, 0.1)',
                    spanGaps: false,
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        min: 0,
                        max: 100,
                        title: {
                            display: true,
                            text: 'Влажность (%)'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Время'
                        }
                    }
                }
            }
        });
        }
        
        // Инициализация графиков при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            initCharts();
        });
        
        // Функция обновления графиков
        function refreshChart() {
            location.reload();
        }
        
        // Функция аварийной остановки
        function emergencyStop() {
            if (!confirm('Вы уверены, что хотите остановить выполнение программы?')) {
                return;
            }
            
            fetch('<?php echo API_BASE_URL; ?>/emergency-stop.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    device_id: '<?php echo $device['device_id']; ?>',
                    csrf_token: '<?php echo csrfToken(); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Программа остановлена!');
                    location.reload();
                } else {
                    alert('Ошибка: ' + data.error);
                }
            })
            .catch(error => {
                alert('Произошла ошибка при остановке программы');
            });
        }
        
        // Подтверждение розжига дымогенератора
        function confirmSmokeIgnition() {
            const btn = document.getElementById('smoke-confirm-btn');
            if (btn) btn.disabled = true;
            
            fetch('<?php echo API_BASE_URL; ?>/smoke-confirmed.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    device_id: '<?php echo $device['device_id']; ?>',
                    csrf_token: '<?php echo csrfToken(); ?>'
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('smoke-ignition-alert').style.display = 'none';
                    alert('Подтверждение отправлено устройству!');
                } else {
                    alert('Ошибка: ' + data.error);
                    if (btn) btn.disabled = false;
                }
            })
            .catch(e => {
                alert('Ошибка сети: ' + e.message);
                if (btn) btn.disabled = false;
            });
        }
        
        // Периодическая проверка статуса устройства для показа smoke alert
        function checkDeviceStatus() {
            fetch('<?php echo API_BASE_URL; ?>/get-state.php?device_id=<?php echo urlencode($device['device_id']); ?>')
                .then(r => r.json())
                .then(data => {
                    const alert = document.getElementById('smoke-ignition-alert');
                    if (alert) {
                        alert.style.display = (data.mode === 'WAITING_SMOKE_IGNITION') ? 'block' : 'none';
                    }
                })
                .catch(() => {});
        }
        checkDeviceStatus();
        setInterval(checkDeviceStatus, 10000);
        
        // Функция обновления графиков
        function refreshChart() {
            location.reload();
        }
    </script>

<?php include __DIR__ . '/templates/footer.php'; ?>