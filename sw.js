// Smart Smoker Service Worker
// Version 2.2.2

const CACHE_NAME = 'smart-smoker-v2.2.2';
const RUNTIME_CACHE = 'smart-smoker-runtime';

// Файлы для кэширования при установке
const PRECACHE_URLS = [
  '/',
  '/dashboard.php',
  '/devices.php',
  '/programs.php',
  '/templates.php'
  // Убраны несуществующие файлы:
  // '/icons/icon-192x192.png',
  // '/icons/icon-512x512.png',
  // '/offline.html'
];

// Установка Service Worker
self.addEventListener('install', event => {
  console.log('[SW] Installing Service Worker...');
  
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('[SW] Precaching app shell');
        // Используем addAll с обработкой ошибок для каждого файла
        return Promise.allSettled(
          PRECACHE_URLS.map(url => 
            cache.add(url).catch(err => {
              console.warn('[SW] Failed to cache:', url, err);
              return Promise.resolve(); // Продолжаем даже если файл не закеширован
            })
          )
        );
      })
      .then(() => {
        console.log('[SW] Precaching complete');
        return self.skipWaiting();
      })
      .catch(err => {
        console.error('[SW] Precaching failed:', err);
        // Всё равно активируем Service Worker
        return self.skipWaiting();
      })
  );
});

// Активация Service Worker
self.addEventListener('activate', event => {
  console.log('[SW] Activating Service Worker...');
  
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames
          .filter(cacheName => {
            return cacheName !== CACHE_NAME && cacheName !== RUNTIME_CACHE;
          })
          .map(cacheName => {
            console.log('[SW] Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          })
      );
    }).then(() => self.clients.claim())
  );
});

// Обработка запросов
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Пропускаем запросы к API (всегда идём в сеть)
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(networkFirst(request));
    return;
  }

  // Навигационные запросы (переходы между страницами) — всегда сеть
  if (request.mode === 'navigate') {
    event.respondWith(networkFirst(request));
    return;
  }

  // Для статики используем Cache First
  event.respondWith(cacheFirst(request));
});

// Стратегия: Cache First (сначала кэш, потом сеть)
async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  
  if (cached) {
    console.log('[SW] Serving from cache:', request.url);
    return cached;
  }

  try {
    const response = await fetch(request);
    
    // Кэшируем успешные GET запросы
    if (request.method === 'GET' && response.status === 200) {
      const runtimeCache = await caches.open(RUNTIME_CACHE);
      runtimeCache.put(request, response.clone());
    }
    
    return response;
  } catch (error) {
    console.log('[SW] Fetch failed, serving offline page:', error);
    
    // Если это HTML страница, показываем offline.html
    if (request.headers.get('accept').includes('text/html')) {
      const offlinePage = await cache.match('/offline.html');
      if (offlinePage) {
        return offlinePage;
      }
    }
    
    throw error;
  }
}

// Стратегия: Network First (сначала сеть, потом кэш)
async function networkFirst(request) {
  const cache = await caches.open(RUNTIME_CACHE);
  
  try {
    const response = await fetch(request);
    
    // Кэшируем успешные ответы
    if (response.status === 200) {
      cache.put(request, response.clone());
    }
    
    return response;
  } catch (error) {
    console.log('[SW] Network failed, trying cache:', error);
    const cached = await cache.match(request);
    
    if (cached) {
      return cached;
    }
    
    throw error;
  }
}

// Push-уведомления
self.addEventListener('push', event => {
  console.log('[SW] Push notification received');

  let data = {
    title: 'Smart Smoker',
    body: 'Новое уведомление',
    icon: '/icons/icon-192x192.png',
    badge: '/icons/badge-72x72.png',
    tag: 'smart-smoker-notification',
    requireInteraction: false,
    url: '/',
    actions: []
  };

  if (event.data) {
    try {
      const parsed = event.data.json();
      data = { ...data, ...parsed };
    } catch (e) {
      data.body = event.data.text();
    }
  }

  const options = {
    body: data.body,
    icon: data.icon,
    badge: data.badge,
    tag: data.tag,
    requireInteraction: data.requireInteraction,
    data: { url: data.url, timestamp: Date.now() },
    actions: data.actions || []
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// Клик по уведомлению
self.addEventListener('notificationclick', event => {
  console.log('[SW] Notification clicked, action:', event.action);

  event.notification.close();

  const notifData = event.notification.data || {};
  let urlToOpen = notifData.url || '/';

  // Обработка действия "Подтвердить поджиг"
  if (event.action === 'confirm' && notifData.url) {
    // Извлекаем device_id из URL и отправляем подтверждение
    const match = notifData.url.match(/[?&]id=([^&]+)/);
    if (match) {
      const deviceId = decodeURIComponent(match[1]);
      event.waitUntil(
        fetch('/api/smoke-confirmed.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ device_id: deviceId })
        }).then(() => {
          return clients.openWindow(notifData.url);
        }).catch(() => {
          return clients.openWindow(notifData.url);
        })
      );
      return;
    }
  }

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(windowClients => {
        for (let client of windowClients) {
          if (client.url.includes(urlToOpen) && 'focus' in client) {
            return client.focus();
          }
        }
        if (clients.openWindow) {
          return clients.openWindow(urlToOpen);
        }
      })
  );
});

// Обработка действий уведомлений
self.addEventListener('notificationclose', event => {
  console.log('[SW] Notification closed:', event.notification.tag);
});

// Синхронизация в фоне
self.addEventListener('sync', event => {
  console.log('[SW] Background sync:', event.tag);
  
  if (event.tag === 'sync-data') {
    event.waitUntil(syncData());
  }
});

// Функция синхронизации данных
async function syncData() {
  try {
    // Здесь можно добавить логику синхронизации
    console.log('[SW] Syncing data...');
    
    // Например, отправить накопленные данные на сервер
    const cache = await caches.open(RUNTIME_CACHE);
    // ... логика синхронизации
    
    return Promise.resolve();
  } catch (error) {
    console.error('[SW] Sync failed:', error);
    return Promise.reject(error);
  }
}

// Обработка сообщений от клиента
self.addEventListener('message', event => {
  console.log('[SW] Message received:', event.data);
  
  if (event.data.action === 'skipWaiting') {
    self.skipWaiting();
  }
  
  if (event.data.action === 'clearCache') {
    event.waitUntil(
      caches.keys().then(cacheNames => {
        return Promise.all(
          cacheNames.map(cacheName => caches.delete(cacheName))
        );
      }).then(() => {
        event.ports[0].postMessage({ success: true });
      })
    );
  }
});

console.log('[SW] Service Worker loaded');
