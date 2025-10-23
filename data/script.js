// script.js — общий скрипт для всех страниц

// Функция для отображения уведомлений
function showStatus(text, isSuccess = true) {
  const statusDiv = document.createElement('div');
  statusDiv.className = `status ${isSuccess ? 'success' : 'error'}`;
  statusDiv.textContent = text;
  document.body.appendChild(statusDiv);
  setTimeout(() => statusDiv.remove(), 3000);
}

// Если на странице есть форма Wi-Fi
if (document.getElementById('wifiForm')) {
  document.getElementById('wifiForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = { ssid: formData.get('ssid'), pass: formData.get('pass') };

    try {
      const res = await fetch('/api/wifi', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      });
      if (res.ok) {
        showStatus('Настройки сохранены. Перезагрузка...', true);
        setTimeout(() => window.location.href = '/', 2500);
      } else {
        const err = await res.text();
        showStatus('Ошибка: ' + err, false);
      }
    } catch (e) {
      showStatus('Не удалось подключиться к устройству', false);
    }
  });

  document.getElementById('resetBtn')?.addEventListener('click', async () => {
    if (!confirm('Сбросить настройки Wi-Fi и перейти в режим точки доступа?')) return;
    try {
      await fetch('/api/wifi/reset', { method: 'POST' });
      showStatus('Настройки сброшены. Перезагрузка...', true);
      setTimeout(() => window.location.href = '/', 2500);
    } catch (e) {
      showStatus('Ошибка сброса', false);
    }
  });
}

// Если на странице есть список программ (index.html)
if (document.getElementById('programsList')) {
  async function fetchState() {
    try {
      const res = await fetch('/api/state');
      if (!res.ok) return;
      const data = await res.json();

      // Сеть
      const netBadge = document.getElementById('networkStatus');
      if (netBadge) {
        if (data.networkMode === 'STA') {
          netBadge.innerHTML = `📶 ${data.ssid} (${data.ip})`;
          netBadge.style.background = '#27ae60';
        } else {
          netBadge.innerHTML = `📡 ${data.ssid}`;
          netBadge.style.background = '#2980b9';
        }
      }

      // Состояние
      const statusEl = document.getElementById('systemStatus');
      if (statusEl) {
        if (data.mode === 'RUNNING') {
          statusEl.textContent = `Выполняется: ${data.currentProgramName} — Этап ${data.currentStepIndex + 1} (осталось ${data.stepTimeLeft})`;
        } else {
          statusEl.textContent = 'Ожидание запуска программы';
        }
      }

      // Датчики
      document.getElementById('tempChamber')?.textContent = data.tempChamber.toFixed(1);
      document.getElementById('tempSmoke')?.textContent = data.tempSmoke.toFixed(1);
      document.getElementById('tempProduct')?.textContent = data.tempProduct.toFixed(1);
      document.getElementById('humidity')?.textContent = data.humidity.toFixed(0);

      // Актюаторы
      document.getElementById('heaterStatus')?.textContent = data.heaterOn ? 'Вкл' : 'Выкл';
      document.getElementById('smokeStatus')?.textContent = `${data.smokePWM}%`;

      // Программы
      const programsList = document.getElementById('programsList');
      if (programsList && data.programs) {
        let html = '';
        data.programs.forEach(prog => {
          html += `
            <div class="program-card">
              <div>
                <strong>${prog.name}</strong>
                <small>${prog.steps} этапов</small>
              </div>
              <button onclick="startProgram('${prog.name.replace(/'/g, "\\'")}')">Запустить</button>
            </div>
          `;
        });
        programsList.innerHTML = html;
      }
    } catch (e) {
      console.warn('Failed to fetch state:', e);
    }
  }

  window.startProgram = async function(name) {
    try {
      const res = await fetch('/api/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ program: name })
      });
      if (res.ok) {
        showStatus('Программа запущена!', true);
        fetchState();
      } else {
        showStatus('Ошибка запуска', false);
      }
    } catch (e) {
      showStatus('Ошибка сети', false);
    }
  };

  // Обновление каждые 2 секунды
  setInterval(fetchState, 2000);
  fetchState();
}