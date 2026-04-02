// PWA Registration Script for Smart Smoker
// Version 2.0.0

(function() {
  'use strict';

  // Проверка поддержки Service Worker
  if (!('serviceWorker' in navigator)) {
    console.warn('Service Worker not supported');
    return;
  }

  // Регистрация Service Worker
  window.addEventListener('load', () => {
    registerServiceWorker();
    checkForUpdates();
    setupInstallPrompt();
    setupPushNotifications();
  });

  // Регистрация Service Worker
  async function registerServiceWorker() {
    try {
      const registration = await navigator.serviceWorker.register('/sw.js', {
        scope: '/'
      });

      console.log('✅ Service Worker registered:', registration.scope);

      // Проверка обновлений
      registration.addEventListener('updatefound', () => {
        const newWorker = registration.installing;
        console.log('🔄 New Service Worker found');

        newWorker.addEventListener('statechange', () => {
          if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
            showUpdateNotification();
          }
        });
      });

      // Обработка сообщений от Service Worker
      navigator.serviceWorker.addEventListener('message', event => {
        console.log('📨 Message from SW:', event.data);
      });

    } catch (error) {
      console.error('❌ Service Worker registration failed:', error);
    }
  }

  // Проверка обновлений
  function checkForUpdates() {
    if (!navigator.serviceWorker.controller) return;

    navigator.serviceWorker.controller.postMessage({
      action: 'checkForUpdates'
    });
  }

  // Уведомление об обновлении
  function showUpdateNotification() {
    const notification = document.createElement('div');
    notification.className = 'pwa-update-notification';
    notification.innerHTML = `
      <div class="pwa-update-content">
        <span>🔄 Доступно обновление приложения</span>
        <button onclick="window.location.reload()" class="pwa-update-btn">Обновить</button>
        <button onclick="this.parentElement.parentElement.remove()" class="pwa-dismiss-btn">✕</button>
      </div>
    `;

    document.body.appendChild(notification);

    // Автоматическое скрытие через 10 секунд
    setTimeout(() => {
      if (notification.parentElement) {
        notification.remove();
      }
    }, 10000);
  }

  // Установка приложения
  let deferredPrompt;

  function setupInstallPrompt() {
    window.addEventListener('beforeinstallprompt', (e) => {
      console.log('💾 Install prompt available');
      e.preventDefault();
      deferredPrompt = e;
      showInstallButton();
    });

    window.addEventListener('appinstalled', () => {
      console.log('✅ PWA installed');
      deferredPrompt = null;
      hideInstallButton();
      
      // Отправляем событие на сервер
      sendAnalytics('pwa_installed');
    });
  }

  // Показать кнопку установки
  function showInstallButton() {
    const installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) {
      installBtn.style.display = 'block';
      installBtn.addEventListener('click', installApp);
    }
  }

  // Скрыть кнопку установки
  function hideInstallButton() {
    const installBtn = document.getElementById('pwa-install-btn');
    if (installBtn) {
      installBtn.style.display = 'none';
    }
  }

  // Установить приложение
  async function installApp() {
    if (!deferredPrompt) return;

    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    
    console.log(`User response: ${outcome}`);
    
    if (outcome === 'accepted') {
      sendAnalytics('pwa_install_accepted');
    } else {
      sendAnalytics('pwa_install_dismissed');
    }

    deferredPrompt = null;
  }

  // Настройка Push-уведомлений
  async function setupPushNotifications() {
    if (!('Notification' in window)) {
      console.warn('Notifications not supported');
      return;
    }

    if (!('PushManager' in window)) {
      console.warn('Push notifications not supported');
      return;
    }

    // Проверяем текущее разрешение
    if (Notification.permission === 'granted') {
      console.log('✅ Notifications already granted');
      subscribeToPush();
    }
  }

  // Подписка на Push-уведомления
  async function subscribeToPush() {
    try {
      const registration = await navigator.serviceWorker.ready;

      const publicKey = getPushPublicKey();
      if (!publicKey || publicKey === 'REPLACE_WITH_GENERATED_KEY') {
        console.warn('[PWA] VAPID public key not configured');
        return;
      }

      // Проверяем существующую подписку
      let subscription = await registration.pushManager.getSubscription();

      if (!subscription) {
        subscription = await registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(publicKey)
        });
        console.log('[PWA] Push subscription created');
      }

      await sendSubscriptionToServer(subscription);

    } catch (error) {
      console.error('[PWA] Push subscription failed:', error);
    }
  }

  // Запрос разрешения на уведомления
  window.requestNotificationPermission = async function() {
    const permission = await Notification.requestPermission();
    
    if (permission === 'granted') {
      console.log('✅ Notification permission granted');
      subscribeToPush();
      return true;
    } else {
      console.log('❌ Notification permission denied');
      return false;
    }
  };

  // Отправка подписки на сервер
  async function sendSubscriptionToServer(subscription) {
    try {
      const response = await fetch('/api/push-subscribe.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(subscription)
      });

      if (response.ok) {
        console.log('✅ Subscription sent to server');
      }
    } catch (error) {
      console.error('❌ Failed to send subscription:', error);
    }
  }

  // Получить публичный ключ для Push из мета-тега
  function getPushPublicKey() {
    const meta = document.querySelector('meta[name="vapid-public-key"]');
    return meta ? meta.getAttribute('content') : null;
  }

  // Конвертация base64 в Uint8Array
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
      .replace(/\-/g, '+')
      .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  // Отправка аналитики
  function sendAnalytics(event) {
    if (typeof gtag !== 'undefined') {
      gtag('event', event, {
        event_category: 'PWA',
        event_label: 'Smart Smoker'
      });
    }
  }

  // Проверка онлайн/оффлайн статуса
  window.addEventListener('online', () => {
    console.log('🌐 Back online');
    showConnectionStatus('online');
  });

  window.addEventListener('offline', () => {
    console.log('📡 Gone offline');
    showConnectionStatus('offline');
  });

  // Показать статус подключения
  function showConnectionStatus(status) {
    const statusBar = document.createElement('div');
    statusBar.className = `connection-status ${status}`;
    statusBar.textContent = status === 'online' 
      ? '🌐 Подключение восстановлено' 
      : '📡 Нет подключения к интернету';
    
    document.body.appendChild(statusBar);

    setTimeout(() => {
      statusBar.remove();
    }, 3000);
  }

  // Экспорт функций для глобального использования
  window.PWA = {
    install: installApp,
    requestNotifications: window.requestNotificationPermission,
    checkUpdates: checkForUpdates
  };

  console.log('🚀 PWA initialized');

})();
