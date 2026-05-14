(function () {
  'use strict';

  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
      return;
    }
    fn();
  }

  function initLoader() {
    window.addEventListener('load', function () {
      var loader = document.getElementById('appLoader');
      if (!loader) return;
      loader.classList.add('hidden');
      setTimeout(function () {
        loader.remove();
      }, 400);
    });
  }

  function initThemeToggle() {
    var body = document.body;
    var btn = document.getElementById('themeToggle');
    var icon = document.getElementById('themeToggleIcon');
    var key = 'bjn-theme';

    function syncThemeLogos(isDark) {
      document.querySelectorAll('img[data-logo-light][data-logo-dark]').forEach(function (img) {
        var light = img.getAttribute('data-logo-light');
        var dark = img.getAttribute('data-logo-dark');
        var target = isDark ? dark : light;
        if (target && img.getAttribute('src') !== target) {
          img.setAttribute('src', target);
        }
      });
    }

    function applyTheme(theme) {
      var isDark = theme === 'dark';
      body.classList.toggle('theme-dark', isDark);
      syncThemeLogos(isDark);
      if (icon) {
        icon.className = isDark ? 'ti ti-sun-high' : 'ti ti-moon-stars';
      }
    }

    var saved = localStorage.getItem(key) || 'light';
    applyTheme(saved);

    if (btn) {
      btn.addEventListener('click', function () {
        var current = body.classList.contains('theme-dark') ? 'dark' : 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem(key, next);
        applyTheme(next);
      });
    }
  }

  function initCountUp() {
    var items = document.querySelectorAll('.js-countup');
    items.forEach(function (el) {
      var target = parseFloat(el.getAttribute('data-countup') || '0');
      if (!isFinite(target)) target = 0;
      var format = el.getAttribute('data-format') || 'number';
      var duration = 900;
      var startTs;

      function formatValue(value) {
        if (format === 'currency') {
          return 'Rp ' + Math.round(value).toLocaleString('id-ID');
        }
        return Math.round(value).toLocaleString('id-ID');
      }

      function tick(ts) {
        if (!startTs) startTs = ts;
        var progress = Math.min((ts - startTs) / duration, 1);
        var value = target * progress;
        el.textContent = formatValue(value);
        if (progress < 1) {
          requestAnimationFrame(tick);
        }
      }

      el.classList.remove('skeleton');
      requestAnimationFrame(tick);
    });
  }

  function initSearchableSelect(selector, extraOptions) {
    if (typeof Choices === 'undefined') return;
    var options = Object.assign({
      searchEnabled: true,
      searchPlaceholderValue: 'Ketik untuk cari...',
      itemSelectText: '',
      shouldSort: false,
      allowHTML: false,
      noResultsText: 'Tidak ada hasil',
      noChoicesText: 'Pilihan tidak tersedia'
    }, extraOptions || {});

    document.querySelectorAll(selector).forEach(function (el) {
      if (!el || el.dataset.choicesReady === '1') return;
      new Choices(el, options);
      el.dataset.choicesReady = '1';
    });
  }

  function initMobileTableStack() {
    if (window.innerWidth > 767) return;

    document.querySelectorAll('.table-responsive').forEach(function (wrapper) {
      if (wrapper.dataset.mobileTableMode === 'scroll') return;
      var table = wrapper.querySelector('table');
      if (!table) return;
      if (table.classList.contains('dataTable') || wrapper.closest('.dataTables_wrapper')) return;

      var headers = Array.prototype.slice.call(table.querySelectorAll('thead th'));
      if (!headers.length) return;

      var labels = headers.map(function (th, idx) {
        var text = (th.textContent || '').trim();
        if (!text) {
          return th.querySelector('input[type="checkbox"]') ? 'Pilih' : (idx === 0 ? 'Item' : 'Field');
        }
        return text;
      });

      var canStack = false;
      table.querySelectorAll('tbody tr').forEach(function (tr) {
        var cells = tr.querySelectorAll('td');
        if (!cells.length) return;
        if (cells.length === 1 && cells[0].hasAttribute('colspan')) return;
        canStack = true;
        cells.forEach(function (td, idx) {
          if (td.hasAttribute('colspan')) return;
          var label = labels[idx] || 'Field';
          td.setAttribute('data-label', label);
          var lowered = label.toLowerCase();
          if (lowered.indexOf('aksi') !== -1 || lowered.indexOf('action') !== -1) td.classList.add('mobile-action-cell');
          if (lowered.indexOf('pilih') !== -1 || lowered.indexOf('select') !== -1) td.classList.add('mobile-select-cell');
        });
      });

      if (canStack) wrapper.classList.add('mobile-table-stack');
    });
  }

  function initSidebarAutoClose() {
    var sidebarEl = document.getElementById('appSidebar');
    if (!sidebarEl || typeof bootstrap === 'undefined') return;
    var sidebar = bootstrap.Offcanvas.getOrCreateInstance(sidebarEl);

    document.querySelectorAll('#appSidebar .sidebar-link').forEach(function (link) {
      link.addEventListener('click', function () {
        if (window.innerWidth < 992) sidebar.hide();
      });
    });

    document.querySelectorAll('#appSidebar [data-bs-dismiss="offcanvas"]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        sidebar.hide();
      });
    });
  }

  function initSidebarCollapse() {
    var body = document.body;
    var key = 'bjn-sidebar-collapsed';
    var desktopToggle = document.getElementById('sidebarToggleDesktop');
    var mobileToggle = document.getElementById('sidebarToggleMobile');
    var sidebarEl = document.getElementById('appSidebar');

    function applyState(collapsed) {
      body.classList.toggle('sidebar-collapsed', collapsed);
    }

    var saved = localStorage.getItem(key);
    if (saved === '1' && window.innerWidth >= 992) {
      applyState(true);
    }

    if (desktopToggle) {
      desktopToggle.addEventListener('click', function () {
        var collapsed = !body.classList.contains('sidebar-collapsed');
        applyState(collapsed);
        localStorage.setItem(key, collapsed ? '1' : '0');
      });
    }

    if (mobileToggle) {
      mobileToggle.addEventListener('click', function (e) {
        body.classList.remove('sidebar-collapsed');
        if (window.innerWidth < 992 && sidebarEl && typeof bootstrap !== 'undefined') {
          e.preventDefault();
          var sidebar = bootstrap.Offcanvas.getOrCreateInstance(sidebarEl);
          if (sidebarEl.classList.contains('show')) {
            sidebar.hide();
          } else {
            sidebar.show();
          }
        }
      });
    }
  }

  function initSweetAlertFromFlash() {
    if (typeof Swal === 'undefined') return;
    var body = document.body;
    var success = body.getAttribute('data-flash-success') || '';
    var error = body.getAttribute('data-flash-error') || '';

    if (success) {
      Swal.fire({
        icon: 'success',
        title: 'Berhasil',
        text: success,
        confirmButtonColor: '#2563eb'
      });
    } else if (error) {
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: error,
        confirmButtonColor: '#dc2626'
      });
    }
  }

  function initConfirmActions() {
    if (typeof Swal === 'undefined') return;
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
      if (el.dataset.confirmBound === '1') return;
      el.dataset.confirmBound = '1';
      el.addEventListener('click', function (e) {
        e.preventDefault();
        var text = el.getAttribute('data-confirm-text') || 'Yakin ingin melanjutkan?';
        var icon = el.getAttribute('data-confirm-icon') || 'warning';
        Swal.fire({
          icon: icon,
          title: 'Konfirmasi',
          text: text,
          showCancelButton: true,
          confirmButtonText: 'Ya',
          cancelButtonText: 'Batal',
          confirmButtonColor: '#2563eb'
        }).then(function (result) {
          if (!result.isConfirmed) return;
          if (el.tagName === 'A' && el.href) {
            window.location.href = el.href;
            return;
          }
          var form = el.closest('form');
          if (form) form.submit();
        });
      });
    });
  }

  function initManualIsolirPopup() {
    var modalEl = document.getElementById('manualIsolirGlobalModal');
    var modalBody = document.getElementById('manualIsolirGlobalModalBody');
    var body = document.body;

    if (!modalEl || !modalBody || typeof bootstrap === 'undefined' || typeof bootstrap.Modal !== 'function') {
      return;
    }

    var modal = bootstrap.Modal.getOrCreateInstance(modalEl, {
      backdrop: true,
      keyboard: true
    });

    modalEl.addEventListener('show.bs.modal', function () {
      body.classList.add('manual-isolir-popup-open');
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
      body.classList.remove('manual-isolir-popup-open');
    });

    function escapeHtml(value) {
      var div = document.createElement('div');
      div.textContent = String(value || '');
      return div.innerHTML;
    }

    function setLoading() {
      modalBody.innerHTML = '<div class="manual-isolir-loading text-muted">Memuat data...</div>';
    }

    function setError(message) {
      modalBody.innerHTML = '<div class="alert alert-danger mb-0">' + escapeHtml(message || 'Gagal memuat popup Manual Isolir.') + '</div>';
    }

    function showAlert(icon, title, text) {
      if (typeof Swal !== 'undefined') {
        Swal.fire({
          icon: icon,
          title: title,
          text: text
        });
        return;
      }
      window.alert(text || title);
    }

    function bindManualIsolirPopupContent(container) {
      if (!container) {
        return;
      }

      var config = container.querySelector('.js-manual-isolir-popup-config');
      if (!config) {
        return;
      }

      var formIsolir = container.querySelector('.js-manual-isolir-form');
      var formRelease = container.querySelector('.js-manual-release-form');
      var targetIsolir = container.querySelector('.js-manual-isolir-target');
      var targetRelease = container.querySelector('.js-manual-release-target');
      var csrfIsolir = container.querySelector('.js-csrf-isolir');
      var csrfRelease = container.querySelector('.js-csrf-release');
      var resultBox = container.querySelector('.js-manual-isolir-result');

      if (typeof window.initSearchableSelect === 'function') {
        window.initSearchableSelect('.js-manual-isolir-popup-select', {
          searchPlaceholderValue: 'Cari target PPP/STATIC...'
        });
      }

      function updateCsrf(name, hash) {
        if (!name || !hash) return;
        if (csrfIsolir) {
          csrfIsolir.name = name;
          csrfIsolir.value = hash;
        }
        if (csrfRelease) {
          csrfRelease.name = name;
          csrfRelease.value = hash;
        }
      }

      function setResult(success, message) {
        if (!resultBox) {
          return;
        }
        resultBox.className = success ? 'js-manual-isolir-result text-success' : 'js-manual-isolir-result text-danger';
        resultBox.textContent = message || (success ? 'Operation sukses.' : 'Operation gagal.');
      }

      function submitAction(formEl, targetEl, actionLabel) {
        if (!formEl || !targetEl) {
          return;
        }

        var username = String(targetEl.value || '').trim();
        if (!username) {
          showAlert('warning', 'Target kosong', 'Silakan pilih target PPP/STATIC terlebih dahulu.');
          return;
        }

        var executeRequest = function () {
          var params = new URLSearchParams(new FormData(formEl));

          fetch(formEl.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: params
          })
            .then(function (response) {
              return response.json();
            })
            .then(function (json) {
              updateCsrf(json.csrf_name, json.csrf_hash);
              if (json.success) {
                setResult(true, json.message);
                showAlert('success', 'Berhasil', json.message || 'Operation sukses.');
                return;
              }
              setResult(false, json.message);
              showAlert('error', 'Gagal', json.message || 'Operation gagal.');
            })
            .catch(function (error) {
              var msg = error && error.message ? error.message : 'Network error';
              setResult(false, msg);
              showAlert('error', 'Error', msg);
            });
        };

        if (typeof Swal !== 'undefined') {
          Swal.fire({
            icon: 'question',
            title: actionLabel + ' user?',
            text: 'Username: ' + username,
            showCancelButton: true,
            confirmButtonText: 'Ya, lanjut',
            cancelButtonText: 'Batal'
          }).then(function (result) {
            if (!result.isConfirmed) return;
            executeRequest();
          });
          return;
        }

        if (window.confirm(actionLabel + ' user ' + username + '?')) {
          executeRequest();
        }
      }

      if (formIsolir) {
        formIsolir.addEventListener('submit', function (event) {
          event.preventDefault();
          submitAction(formIsolir, targetIsolir, 'Isolir');
        });
      }

      if (formRelease) {
        formRelease.addEventListener('submit', function (event) {
          event.preventDefault();
          submitAction(formRelease, targetRelease, 'Release');
        });
      }
    }

    document.querySelectorAll('.js-open-manual-isolir-popup').forEach(function (trigger) {
      if (trigger.dataset.popupBindReady === '1') {
        return;
      }
      trigger.dataset.popupBindReady = '1';

      trigger.addEventListener('click', function (event) {
        event.preventDefault();

        var popupUrl = trigger.getAttribute('data-popup-url') || trigger.getAttribute('href');
        if (!popupUrl) {
          return;
        }

        setLoading();
        modal.show();

        fetch(popupUrl, {
          method: 'GET',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error('HTTP ' + response.status);
            }
            return response.text();
          })
          .then(function (html) {
            modalBody.innerHTML = html;
            bindManualIsolirPopupContent(modalBody);
          })
          .catch(function (error) {
            setError('Gagal memuat popup Manual Isolir. ' + (error && error.message ? error.message : ''));
          });
      });
    });
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !window.__APP_SW__) return;
    window.addEventListener('load', function () {
      navigator.serviceWorker
        .register(window.__APP_SW__.path, { scope: window.__APP_SW__.scope })
        .catch(function (error) {
          console.warn('[PWA] Service worker registration failed:', error);
        });
    });
  }

  window.initSearchableSelect = initSearchableSelect;

  onReady(function () {
    initThemeToggle();
    initSidebarCollapse();
    initCountUp();
    initSearchableSelect('select[data-searchable="1"]');
    initMobileTableStack();
    initSidebarAutoClose();
    initSweetAlertFromFlash();
    initConfirmActions();
    initManualIsolirPopup();
  });

  initLoader();
  registerServiceWorker();
})();
