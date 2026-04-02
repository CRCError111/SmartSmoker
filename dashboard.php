<?php
/**
 * Главная панель управления с телеметрией последних запусков
 * 
 * @version 2.0 - Telemetry charts per run_id
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireAuth();

// Отключаем кеширование для динамической страницы
disableCache();

$user = Auth::user();
$db = db();

$devices = $db->fetchAll(
    'SELECT * FROM devices WHERE user_id = ? AND (unbound IS NULL OR unbound = 0) ORDER BY created_at DESC',
    [$user['id']]
);

$programsCount = $db->fetchColumn(
    'SELECT COUNT(*) FROM programs WHERE user_id = ?',
    [$user['id']]
);

$activeRunsCount = $db->fetchColumn(
    'SELECT COUNT(*) FROM runs r 
     JOIN devices d ON d.device_id = r.device_id 
     WHERE d.user_id = ? AND r.status = "running"',
    [$user['id']]
);

// Для каждого устройства получаем последние 10 запусков с телеметрией
$deviceTelemetry = [];
foreach ($devices as $device) {
    $runs = $db->fetchAll(
        'SELECT r.run_id, r.program_name, r.status, r.start_time, r.end_time
         FROM runs r
         WHERE r.device_id = ?
         ORDER BY r.start_time DESC
         LIMIT 10',
        [$device['device_id']]
    );

    $runsData = [];
    foreach ($runs as $run) {
        // Берём до 60 точек телеметрии на запуск (равномерная выборка)
        $points = $db->fetchAll(
            'SELECT temp_chamber, temp_smoke, temp_product, humidity, created_at
             FROM sensor_data
             WHERE device_id = ? AND run_id = ?
             ORDER BY created_at ASC
             LIMIT 60',
            [$device['device_id'], $run['run_id']]
        );
        $runsData[] = [
            'run'    => $run,
            'points' => $points
        ];
    }

    $deviceTelemetry[$device['device_id']] = $runsData;
}

$pageTitle = 'Панель управления';
include __DIR__ . '/templates/header.php';
?>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-4 mb-3">
        <a href="<?= BASE_URL ?>/devices.php" style="text-decoration:none;display:block;background:white;padding:20px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1)">
            <div style="display:flex;align-items:center;gap:15px">
                <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:1.5rem">💻</div>
                <div>
                    <div style="font-size:2rem;font-weight:700;color:#333"><?= count($devices) ?></div>
                    <div style="color:#666">Устройств</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 mb-3">
        <a href="<?= BASE_URL ?>/programs.php" style="text-decoration:none;display:block;background:white;padding:20px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1)">
            <div style="display:flex;align-items:center;gap:15px">
                <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:1.5rem">📋</div>
                <div>
                    <div style="font-size:2rem;font-weight:700;color:#333"><?= $programsCount ?></div>
                    <div style="color:#666">Программ</div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 mb-3">
        <div style="display:block;background:white;padding:20px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.1)">
            <div style="display:flex;align-items:center;gap:15px">
                <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);display:flex;align-items:center;justify-content:center;font-size:1.5rem">🔥</div>
                <div>
                    <div style="font-size:2rem;font-weight:700;color:#333"><?= $activeRunsCount ?></div>
                    <div style="color:#666">Активных запусков</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Devices Section -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">💻 Мои устройства</h2>
    <a href="<?= BASE_URL ?>/add-device.php" class="btn btn-primary btn-sm">➕ Добавить</a>
</div>

<?php if (empty($devices)): ?>
<div class="empty-state">
    <p style="font-size:3rem;margin-bottom:20px">📦</p>
    <p>У вас пока нет устройств</p>
    <a href="<?= BASE_URL ?>/add-device.php" class="btn btn-primary">➕ Добавить первое устройство</a>
</div>
<?php else: ?>

<?php foreach ($devices as $device): ?>
<?php
    $statusInfo = formatDeviceStatus($device['status']);
    $runsData   = $deviceTelemetry[$device['device_id']] ?? [];
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>💻 <?= e($device['name']) ?> <span class="badge <?= $statusInfo[1] ?> ms-2"><?= $statusInfo[0] ?></span></span>
        <div>
            <a href="<?= BASE_URL ?>/view-device.php?id=<?= $device['id'] ?>" class="btn btn-sm btn-outline-primary">👁️ Подробнее</a>
            <?php if ($device['status'] === 'inactive'): ?>
            <a href="<?= BASE_URL ?>/bind-device.php?id=<?= $device['id'] ?>" class="btn btn-sm btn-outline-secondary ms-1">🔗 Привязать</a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <!-- Smoke ignition alert (shown dynamically via JS) -->
        <div id="smoke-alert-<?= $device['id'] ?>" style="display:none;background:#fff3cd;border:2px solid #ffc107;border-radius:8px;padding:12px;margin-bottom:12px">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <strong style="color:#856404">🔥 Подожгите дымогенератор!</strong>
                    <small class="d-block" style="color:#856404">Компрессор запущен. Подтвердите готовность.</small>
                </div>
                <a href="<?= BASE_URL ?>/view-device.php?id=<?= $device['id'] ?>" class="btn btn-warning btn-sm">✅ Подтвердить</a>
            </div>
        </div>
        <?php if (empty($runsData)): ?>
            <p class="text-muted mb-0">Нет данных телеметрии. Запустите программу копчения.</p>
        <?php else: ?>

        <!-- Сводный график (все запуски, все параметры) -->
        <h6 class="mb-2">📊 Сводный график последних <?= count($runsData) ?> запусков</h6>
        <div style="position:relative;height:260px;margin-bottom:24px">
            <canvas id="combined_<?= $device['id'] ?>"></canvas>
        </div>

        <!-- Мини-графики по каждому запуску -->
        <h6 class="mb-2">🔍 Детализация по запускам</h6>
        <div class="row g-2">
        <?php foreach ($runsData as $idx => $rd): ?>
        <?php
            $run    = $rd['run'];
            $points = $rd['points'];
            $label  = $run['program_name'] ?? ('Запуск ' . ($idx + 1));
            $label  = mb_strimwidth($label, 0, 22, '…');
            $statusBadge = match($run['status'] ?? '') {
                'running'   => 'bg-success',
                'completed' => 'bg-primary',
                'stopped'   => 'bg-danger',
                default     => 'bg-secondary'
            };
        ?>
        <div class="col-md-6 col-xl-4">
            <div class="card border-0 bg-light h-100">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <small class="fw-bold text-truncate" title="<?= e($run['program_name'] ?? '') ?>"><?= e($label) ?></small>
                        <span class="badge <?= $statusBadge ?> ms-1" style="font-size:.65rem"><?= e($run['status'] ?? '—') ?></span>
                    </div>
                    <small class="text-muted d-block mb-1"><?= e(substr($run['start_time'] ?? '', 0, 16)) ?></small>
                    <?php if (empty($points)): ?>
                        <p class="text-muted mb-0" style="font-size:.8rem">Нет точек телеметрии</p>
                    <?php else: ?>
                    <div style="position:relative;height:90px">
                        <canvas id="mini_<?= $device['id'] ?>_<?= $idx ?>"></canvas>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"
        integrity="sha256-Mh46P6mNpKqpV9EL5Xy7UU3gmJ7tj51ya10FkCzQGQQ="
        crossorigin="anonymous"></script>
<script>
// Данные телеметрии из PHP
const telemetryData = <?= json_encode($deviceTelemetry, JSON_UNESCAPED_UNICODE) ?>;
const deviceList    = <?= json_encode(array_map(fn($d) => ['id' => $d['id'], 'device_id' => $d['device_id']], $devices)) ?>;

// Периодическая проверка статуса устройств для smoke ignition alert
function checkSmokeStatus() {
    deviceList.forEach(dev => {
        fetch('<?= API_BASE_URL ?>/get-state.php?device_id=' + encodeURIComponent(dev.device_id))
            .then(r => r.json())
            .then(data => {
                const el = document.getElementById('smoke-alert-' + dev.id);
                if (el) el.style.display = (data.mode === 'WAITING_SMOKE_IGNITION') ? 'block' : 'none';
            })
            .catch(() => {});
    });
}
checkSmokeStatus();
setInterval(checkSmokeStatus, 15000);

const COLORS = {
    tempChamber:  '#e74c3c',
    tempSmoke:    '#e67e22',
    tempProduct:  '#9b59b6',
    humidity:     '#3498db'
};

function makeLabels(points) {
    return points.map(p => p.created_at ? p.created_at.substring(11, 16) : '');
}

// Мини-графики
deviceList.forEach(dev => {
    const runs = telemetryData[dev.device_id] || [];
    runs.forEach((rd, idx) => {
        const points = rd.points;
        if (!points || points.length === 0) return;
        const ctx = document.getElementById('mini_' + dev.id + '_' + idx);
        if (!ctx) return;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: makeLabels(points),
                datasets: [
                    {
                        label: 'Камера °C',
                        data: points.map(p => p.temp_chamber),
                        borderColor: COLORS.tempChamber,
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        pointRadius: 0,
                        tension: 0.3
                    },
                    {
                        label: 'Продукт °C',
                        data: points.map(p => p.temp_product),
                        borderColor: COLORS.tempProduct,
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        pointRadius: 0,
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: {
                    x: { display: false },
                    y: { display: false }
                }
            }
        });
    });
});

// Сводные графики (несколько осей Y)
deviceList.forEach(dev => {
    const ctx = document.getElementById('combined_' + dev.id);
    if (!ctx) return;

    const runs = telemetryData[dev.device_id] || [];
    if (runs.length === 0) return;

    // Объединяем все точки всех запусков в один массив с разделителями
    const allLabels = [];
    const tempChamberData = [];
    const tempSmokeData   = [];
    const tempProductData = [];
    const humidityData    = [];

    runs.slice().reverse().forEach((rd, rIdx) => {
        const points = rd.points;
        if (!points || points.length === 0) return;

        // Разделитель между запусками
        if (rIdx > 0) {
            allLabels.push('');
            tempChamberData.push(null);
            tempSmokeData.push(null);
            tempProductData.push(null);
            humidityData.push(null);
        }

        points.forEach(p => {
            allLabels.push(p.created_at ? p.created_at.substring(5, 16) : '');
            tempChamberData.push(p.temp_chamber !== null ? parseFloat(p.temp_chamber) : null);
            tempSmokeData.push(p.temp_smoke   !== null ? parseFloat(p.temp_smoke)   : null);
            tempProductData.push(p.temp_product !== null ? parseFloat(p.temp_product) : null);
            humidityData.push(p.humidity !== null ? parseFloat(p.humidity) : null);
        });
    });

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: allLabels,
            datasets: [
                {
                    label: 'Камера °C',
                    data: tempChamberData,
                    borderColor: COLORS.tempChamber,
                    backgroundColor: 'rgba(231,76,60,.08)',
                    borderWidth: 2,
                    pointRadius: 0,
                    tension: 0.3,
                    yAxisID: 'yTemp',
                    spanGaps: false
                },
                {
                    label: 'Дым °C',
                    data: tempSmokeData,
                    borderColor: COLORS.tempSmoke,
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0.3,
                    yAxisID: 'yTemp',
                    spanGaps: false
                },
                {
                    label: 'Продукт °C',
                    data: tempProductData,
                    borderColor: COLORS.tempProduct,
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0.3,
                    yAxisID: 'yTemp',
                    spanGaps: false
                },
                {
                    label: 'Влажность %',
                    data: humidityData,
                    borderColor: COLORS.humidity,
                    backgroundColor: 'rgba(52,152,219,.08)',
                    borderWidth: 1.5,
                    pointRadius: 0,
                    tension: 0.3,
                    yAxisID: 'yHum',
                    spanGaps: false
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: { boxWidth: 12, font: { size: 11 } }
                }
            },
            scales: {
                x: {
                    ticks: {
                        maxTicksLimit: 12,
                        font: { size: 10 },
                        maxRotation: 0
                    },
                    grid: { color: 'rgba(0,0,0,.05)' }
                },
                yTemp: {
                    type: 'linear',
                    position: 'left',
                    title: { display: true, text: '°C', font: { size: 11 } },
                    grid: { color: 'rgba(0,0,0,.05)' },
                    ticks: { font: { size: 10 } }
                },
                yHum: {
                    type: 'linear',
                    position: 'right',
                    title: { display: true, text: '%', font: { size: 11 } },
                    min: 0,
                    max: 100,
                    grid: { drawOnChartArea: false },
                    ticks: { font: { size: 10 } }
                }
            }
        }
    });
});
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
