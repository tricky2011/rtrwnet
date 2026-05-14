(function () {
  'use strict';

  var cfg = window.APP_NOTIFICATION || null;
  if (!cfg || !cfg.userId || !cfg.endpoints) {
    return;
  }

  var root = document.getElementById('notificationRealtimeRoot');
  var listEl = document.getElementById('notificationList');
  var badgeEl = document.getElementById('notificationBadge');
  var unreadLabelEl = document.getElementById('notificationUnreadLabel');
  var markAllBtn = document.getElementById('notificationMarkAllBtn');
  var dropdownBtn = document.getElementById('notificationDropdownBtn');
  if (!root || !listEl || !badgeEl || !unreadLabelEl || !dropdownBtn) {
    return;
  }

  var pusher = null;
  var state = {
    items: [],
    unreadCount: 0,
    maxItems: 10,
    initialized: false
  };
  var realtimeLogPrefix = '[RTRWNet Notification]';

  function logRealtime(level, message, meta) {
    if (!window.console) return;
    var method = typeof console[level] === 'function' ? console[level] : console.log;
    if (typeof meta !== 'undefined') {
      method.call(console, realtimeLogPrefix + ' ' + message, meta);
      return;
    }
    method.call(console, realtimeLogPrefix + ' ' + message);
  }

  function getRealtimeDetail(detail) {
    if (!detail) return '';
    if (typeof detail === 'string') return detail;
    if (typeof detail.error === 'string') return detail.error;
    if (typeof detail.message === 'string') return detail.message;
    if (typeof detail.type === 'string') return detail.type;
    if (typeof detail.status !== 'undefined') return 'status:' + detail.status;
    return '';
  }

  function setRealtimeStatus(status, detail) {
    root.setAttribute('data-realtime-status', String(status || 'idle'));

    detail = getRealtimeDetail(detail);
    if (detail) {
      root.setAttribute('data-realtime-detail', detail);
      return;
    }

    root.removeAttribute('data-realtime-detail');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function ucfirst(text) {
    text = String(text || '').trim();
    if (!text) return '';
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  function categoryClass(type, category) {
    var t = String(type || '').toLowerCase();
    var c = String(category || '').toLowerCase();
    if (t === 'critical' || c === 'critical') return 'critical';
    if (t === 'warning' || c === 'warning') return 'warning';
    if (t === 'success' || c === 'success') return 'success';
    return 'info';
  }

  function formatDateTime(value) {
    if (!value) return '-';
    var normalized = String(value).replace(' ', 'T');
    var date = new Date(normalized);
    if (isNaN(date.getTime())) return String(value);
    try {
      return date.toLocaleString('id-ID', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
      });
    } catch (e) {
      return String(value);
    }
  }

  function updateCsrf(csrf) {
    if (!csrf || !csrf.name || !csrf.hash) return;
    cfg.csrf = cfg.csrf || {};
    cfg.csrf.name = csrf.name;
    cfg.csrf.hash = csrf.hash;

    if (pusher && pusher.config && pusher.config.auth) {
      pusher.config.auth.params = pusher.config.auth.params || {};
      pusher.config.auth.params[cfg.csrf.name] = cfg.csrf.hash;
    }
  }

  function buildPostBody(payload) {
    var formData = new FormData();
    var data = payload || {};
    Object.keys(data).forEach(function (key) {
      if (typeof data[key] === 'undefined' || data[key] === null) return;
      formData.append(key, data[key]);
    });
    if (cfg.csrf && cfg.csrf.name && cfg.csrf.hash) {
      formData.append(cfg.csrf.name, cfg.csrf.hash);
    }
    return formData;
  }

  function requestJson(url, options) {
    return fetch(url, Object.assign({
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    }, options || {})).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) {
          data = data || {};
          data.success = false;
        }
        if (data && data.csrf) {
          updateCsrf(data.csrf);
        }
        return data;
      }).catch(function () {
        return { success: false, message: 'Response server tidak valid.' };
      });
    }).catch(function () {
      return { success: false, message: 'Koneksi server gagal.' };
    });
  }

  function setBadge(count) {
    var c = parseInt(count, 10);
    if (!isFinite(c) || c < 0) c = 0;
    state.unreadCount = c;
    badgeEl.textContent = String(c);
    unreadLabelEl.textContent = c + ' unread';
    if (c > 0) {
      badgeEl.classList.remove('d-none');
    } else {
      badgeEl.classList.add('d-none');
    }
  }

  function incrementBadge() {
    setBadge(state.unreadCount + 1);
  }

  function renderList() {
    if (!state.items.length) {
      listEl.innerHTML = '<div class="notif-empty">Belum ada notifikasi.</div>';
      return;
    }

    var html = state.items.slice(0, state.maxItems).map(function (item) {
      var cls = categoryClass(item.type, item.category);
      var unread = Number(item.is_read || 0) === 0;
      var title = escapeHtml(item.title || 'Notifikasi');
      var message = escapeHtml(item.message || '');
      var category = escapeHtml(ucfirst(item.category || item.type || 'info'));
      var createdAt = escapeHtml(formatDateTime(item.created_at));
      var id = Number(item.id || 0);
      return '' +
        '<div class="notif-item ' + (unread ? 'is-unread ' : '') + 'cat-' + cls + '" data-id="' + id + '">' +
          '<div class="notif-head">' +
            '<span class="notif-title">' + title + '</span>' +
            '<span class="notif-time">' + createdAt + '</span>' +
          '</div>' +
          '<div class="notif-message">' + message + '</div>' +
          '<div class="notif-meta">' +
            '<span class="notif-category badge-' + cls + '">' + category + '</span>' +
            (unread ? '<button type="button" class="notif-read-btn" data-id="' + id + '">Mark read</button>' : '') +
          '</div>' +
        '</div>';
    }).join('');

    listEl.innerHTML = html;
  }

  function addNotificationToUI(data, asUnread) {
    if (!data) return;
    var item = {
      id: Number(data.id || 0),
      user_id: data.user_id ? Number(data.user_id) : null,
      title: String(data.title || 'Notifikasi'),
      message: String(data.message || ''),
      category: String(data.category || 'general'),
      type: String(data.type || 'info'),
      created_at: data.created_at || new Date().toISOString(),
      is_read: asUnread ? 0 : Number(data.is_read || 0)
    };

    state.items = state.items.filter(function (x) {
      return Number(x.id || 0) !== item.id || item.id === 0;
    });
    state.items.unshift(item);
    if (state.items.length > state.maxItems) {
      state.items = state.items.slice(0, state.maxItems);
    }
    renderList();
  }

  function markRead(id) {
    id = Number(id || 0);
    if (id <= 0) return;

    requestJson(cfg.endpoints.markReadBase + '/' + id, {
      method: 'POST',
      body: buildPostBody({ id: id })
    }).then(function (res) {
      if (!res || !res.success) return;
      state.items = state.items.map(function (it) {
        if (Number(it.id || 0) === id) {
          it.is_read = 1;
        }
        return it;
      });
      renderList();
      if (typeof res.unread_count !== 'undefined') {
        setBadge(res.unread_count);
      } else {
        setBadge(Math.max(0, state.unreadCount - 1));
      }
    });
  }

  function markAllRead() {
    requestJson(cfg.endpoints.markAll, {
      method: 'POST',
      body: buildPostBody({ all: 1 })
    }).then(function (res) {
      if (!res || !res.success) return;
      state.items = state.items.map(function (it) {
        it.is_read = 1;
        return it;
      });
      renderList();
      setBadge(typeof res.unread_count !== 'undefined' ? res.unread_count : 0);
    });
  }

  function playNotifSound() {
    try {
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      var ctx = new Ctx();
      var oscillator = ctx.createOscillator();
      var gainNode = ctx.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.setValueAtTime(880, ctx.currentTime);
      gainNode.gain.setValueAtTime(0.0001, ctx.currentTime);
      gainNode.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.01);
      gainNode.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.22);
      oscillator.connect(gainNode);
      gainNode.connect(ctx.destination);
      oscillator.start();
      oscillator.stop(ctx.currentTime + 0.23);
    } catch (e) {
      // Ignore audio errors.
    }
  }

  function fetchLatest() {
    return requestJson(cfg.endpoints.latest + '?limit=' + state.maxItems, { method: 'GET' }).then(function (res) {
      if (!res || !res.success) return;
      state.items = Array.isArray(res.items) ? res.items : [];
      renderList();
      setBadge(typeof res.unread_count !== 'undefined' ? res.unread_count : 0);
    });
  }

  function handleIncomingNotification(data) {
    if (!data) return;
    if (data.user_id && Number(data.user_id) !== Number(cfg.userId)) {
      return;
    }
    addNotificationToUI(data, true);
    incrementBadge();
    playNotifSound();
  }

  function initPusher() {
    if (typeof Pusher === 'undefined') {
      setRealtimeStatus('disabled', 'pusher-js-missing');
      logRealtime('info', 'Pusher JS tidak tersedia, realtime dinonaktifkan.');
      return;
    }
    if (!cfg.pusher || !cfg.pusher.key) {
      setRealtimeStatus('disabled', 'pusher-config-missing');
      logRealtime('info', 'Kredensial Pusher belum lengkap, realtime dinonaktifkan.');
      return;
    }

    var authParams = {};
    if (cfg.csrf && cfg.csrf.name && cfg.csrf.hash) {
      authParams[cfg.csrf.name] = cfg.csrf.hash;
    }

    try {
      pusher = new Pusher(cfg.pusher.key, {
        cluster: cfg.pusher.cluster || 'ap1',
        forceTLS: true,
        authEndpoint: cfg.endpoints.auth,
        auth: {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          },
          params: authParams
        }
      });
    } catch (err) {
      setRealtimeStatus('init-error', err);
      logRealtime('error', 'Inisialisasi Pusher gagal.', err);
      return;
    }

    setRealtimeStatus('connecting');
    pusher.connection.bind('state_change', function (states) {
      if (!states || !states.current) return;
      setRealtimeStatus(states.current);
    });
    pusher.connection.bind('error', function (err) {
      setRealtimeStatus('connection-error', err);
      logRealtime('warn', 'Koneksi Pusher bermasalah.', err);
    });

    var privateChannelName = String(cfg.pusher.privatePrefix || 'private-user-') + String(cfg.userId);
    var privateChannel = pusher.subscribe(privateChannelName);
    privateChannel.bind('pusher:subscription_succeeded', function () {
      setRealtimeStatus('subscribed');
    });
    privateChannel.bind('pusher:subscription_error', function (err) {
      setRealtimeStatus('subscription-error', err);
      logRealtime('warn', 'Private channel gagal subscribe/auth.', {
        channel: privateChannelName,
        error: err
      });
    });
    privateChannel.bind(cfg.pusher.eventName || 'new-notification', handleIncomingNotification);

    if (cfg.pusher.allowPublic) {
      var publicChannel = pusher.subscribe(cfg.pusher.publicChannel || 'superapps-channel');
      publicChannel.bind('pusher:subscription_error', function (err) {
        logRealtime('warn', 'Public channel gagal subscribe.', {
          channel: cfg.pusher.publicChannel || 'superapps-channel',
          error: err
        });
      });
      publicChannel.bind(cfg.pusher.eventName || 'new-notification', handleIncomingNotification);
    }
  }

  listEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.notif-read-btn');
    if (!btn) return;
    e.preventDefault();
    markRead(btn.getAttribute('data-id'));
  });

  if (markAllBtn) {
    markAllBtn.addEventListener('click', function (e) {
      e.preventDefault();
      markAllRead();
    });
  }

  dropdownBtn.addEventListener('show.bs.dropdown', function () {
    fetchLatest();
  });

  window.addNotificationToUI = addNotificationToUI;
  window.incrementBadge = incrementBadge;
  window.playNotifSound = playNotifSound;

  fetchLatest();
  setRealtimeStatus('idle');
  initPusher();
})();
