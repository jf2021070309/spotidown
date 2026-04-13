/**
 * SpotiDown – Frontend Logic
 * Handles UI, API calls to PHP backend, polling, and ZIP download
 */

(function () {
  'use strict';

  /* ── DOM references ── */
  const urlInput        = document.getElementById('spotifyUrl');
  const searchBtn       = document.getElementById('searchBtn');
  const pasteBtn        = document.getElementById('pasteBtn');
  const loadingOverlay  = document.getElementById('loadingOverlay');
  const loadingTitle    = document.getElementById('loadingTitle');
  const loadingSubtitle = document.getElementById('loadingSubtitle');
  const resultsSection  = document.getElementById('resultsSection');
  const stepsSection    = document.getElementById('stepsSection');
  const errorToast      = document.getElementById('errorToast');
  const errorMessage    = document.getElementById('errorMessage');

  const playlistThumb   = document.getElementById('playlistThumb');
  const playlistTypeBadge = document.getElementById('playlistTypeBadge');
  const playlistName    = document.getElementById('playlistName');
  const trackCount      = document.getElementById('trackCount');
  const playlistOwner   = document.getElementById('playlistOwner');
  const downloadZipBtn  = document.getElementById('downloadZipBtn');
  const zipSize         = document.getElementById('zipSize');

  const progressContainer = document.getElementById('progressContainer');
  const progressLabel     = document.getElementById('progressLabel');
  const progressPct       = document.getElementById('progressPct');
  const progressBarFill   = document.getElementById('progressBarFill');
  const progressStatus    = document.getElementById('progressStatus');

  const trackList = document.getElementById('trackList');

  /* ── State ── */
  let currentJobId  = null;
  let pollInterval  = null;
  let currentTracks = [];

  /* ── Background particles ── */
  (function initParticles() {
    const container = document.getElementById('bgParticles');
    for (let i = 0; i < 30; i++) {
      const span = document.createElement('span');
      const size  = Math.random() * 4 + 2;
      span.style.cssText = `
        width:${size}px; height:${size}px;
        left:${Math.random()*100}%;
        background: hsl(${Math.random() > 0.5 ? 142 : 270},70%,60%);
        animation-duration: ${Math.random()*20+15}s;
        animation-delay: -${Math.random()*20}s;
      `;
      container.appendChild(span);
    }
  })();

  /* ── Paste button ── */
  pasteBtn.addEventListener('click', async () => {
    try {
      const text = await navigator.clipboard.readText();
      urlInput.value = text.trim();
      urlInput.dispatchEvent(new Event('input'));
      pasteBtn.textContent = '✓ Pegado';
      setTimeout(() => {
        pasteBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M19 2h-4.18C14.4.84 13.3 0 12 0c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm7 18H5V4h2v3h10V4h2v16z" fill="currentColor"/></svg> Pegar`;
      }, 2000);
    } catch {
      showError('No se pudo acceder al portapapeles. Pega manualmente.');
    }
  });

  /* ── Enter key triggers search ── */
  urlInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') triggerSearch();
  });

  searchBtn.addEventListener('click', triggerSearch);

  /* ── Validate Spotify URL live ── */
  urlInput.addEventListener('input', () => {
    const val = urlInput.value.trim();
    const valid = isSpotifyUrl(val);
    document.getElementById('searchBox').style.borderColor =
      val.length > 5 ? (valid ? 'var(--green)' : '#ef4444') : '';
  });

  /* ────────────────────────────────────────── */
  /*  MAIN SEARCH FLOW                          */
  /* ────────────────────────────────────────── */
  function triggerSearch() {
    const url = urlInput.value.trim();
    if (!url) { showError('Por favor ingresa un enlace de Spotify.'); return; }
    if (!isSpotifyUrl(url)) {
      showError('El enlace no parece ser de Spotify. Ej: https://open.spotify.com/playlist/...');
      return;
    }
    startFetch(url);
  }

  async function startFetch(url) {
    showLoading('Obteniendo información...', 'Conectando con Spotify');
    resultsSection.style.display = 'none';
    stepsSection.style.display = 'none';

    try {
      const res = await fetch('api.php?action=info', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url })
      });

      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Error desconocido');

      hideLoading();
      renderPlaylistInfo(data);
    } catch (err) {
      hideLoading();
      showError('Error al obtener la playlist: ' + err.message);
      stepsSection.style.display = 'block';
    }
  }

  /* ────────────────────────────────────────── */
  /*  RENDER PLAYLIST INFO                      */
  /* ────────────────────────────────────────── */
  function renderPlaylistInfo(data) {
    currentTracks = data.tracks || [];

    // type badge
    playlistTypeBadge.textContent = (data.type || 'playlist').toUpperCase();
    playlistName.textContent = data.name || 'Playlist';
    trackCount.textContent = `${currentTracks.length} canciones`;
    playlistOwner.textContent = data.owner || '';

    // thumbnail
    if (data.image) {
      playlistThumb.innerHTML = `<img src="${data.image}" alt="cover" />`;
    }

    // render track list
    trackList.innerHTML = '';
    currentTracks.forEach((track, idx) => {
      trackList.appendChild(buildTrackItem(track, idx));
    });

    resultsSection.style.display = 'block';
    resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function buildTrackItem(track, idx) {
    const li = document.createElement('div');
    li.className = 'track-item';
    li.id = `track-${idx}`;

    const coverHtml = track.image
      ? `<img src="${track.image}" alt="cover" />`
      : `<svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" fill="currentColor"/></svg>`;

    const durStr = track.duration ? formatDuration(track.duration) : '--:--';
    const artistStr = Array.isArray(track.artists)
      ? track.artists.join(', ')
      : (track.artist || '');

    li.innerHTML = `
      <div class="track-num">${idx + 1}</div>
      <div class="track-cover">${coverHtml}</div>
      <div class="track-info">
        <div class="track-name" title="${esc(track.name)}">${esc(track.name)}</div>
        <div class="track-artist">${esc(artistStr)}</div>
      </div>
      <div class="track-duration">${durStr}</div>
      <button class="track-download-btn" id="dl-btn-${idx}" onclick="downloadSingle(${idx})">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z" fill="currentColor"/></svg>
        Download
      </button>
    `;
    return li;
  }

  /* ────────────────────────────────────────── */
  /*  DOWNLOAD SINGLE TRACK                     */
  /* ────────────────────────────────────────── */
  window.downloadSingle = async function (idx) {
    const track = currentTracks[idx];
    if (!track) return;

    const btn  = document.getElementById(`dl-btn-${idx}`);
    const item = document.getElementById(`track-${idx}`);

    btn.disabled = true;
    btn.innerHTML = `<svg class="icon-spin" width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg> Procesando...`;
    item.classList.add('downloading');

    try {
      showLoading('Descargando canción...', `${track.name}`);
      const res = await fetch('api.php?action=download_single', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: track.url || track.id, index: idx })
      });

      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'Error descargando');

      hideLoading();
      item.classList.remove('downloading');
      item.classList.add('done');

      // trigger browser download
      triggerDownload(data.download_url, data.filename);

      btn.innerHTML = `<svg class="icon-done" width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" fill="currentColor"/></svg> Descargado`;
    } catch (err) {
      hideLoading();
      item.classList.remove('downloading');
      item.classList.add('error');
      btn.disabled = false;
      btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z" fill="currentColor"/></svg> Reintentar`;
      showError('Error: ' + err.message);
    }
  };

  /* ────────────────────────────────────────── */
  /*  DOWNLOAD FULL ZIP                         */
  /* ────────────────────────────────────────── */
  downloadZipBtn.addEventListener('click', startZipDownload);

  async function startZipDownload() {
    const url = urlInput.value.trim();
    if (!url) return;

    downloadZipBtn.disabled = true;
    downloadZipBtn.querySelector('span').textContent = 'Preparando...';

    progressContainer.style.display = 'block';
    progressBarFill.style.width = '0%';
    progressPct.textContent = '0%';
    progressLabel.textContent = 'Iniciando proceso de descarga...';
    progressStatus.textContent = 'Conectando con spotdl...';

    try {
      const res = await fetch('api.php?action=start_zip', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url })
      });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const data = await res.json();
      if (!data.success) throw new Error(data.error || 'No se pudo iniciar');

      currentJobId = data.job_id;
      pollProgress();
    } catch (err) {
      showError('Error al iniciar: ' + err.message);
      downloadZipBtn.disabled = false;
      downloadZipBtn.querySelector('span').textContent = 'Descargar ZIP completo';
    }
  }

  function pollProgress() {
    if (pollInterval) clearInterval(pollInterval);

    pollInterval = setInterval(async () => {
      try {
        const res = await fetch(`api.php?action=status&job_id=${currentJobId}`);
        const data = await res.json();

        const pct = Math.min(data.progress || 0, 100);
        progressBarFill.style.width = pct + '%';
        progressPct.textContent = pct + '%';
        progressLabel.textContent = `Descargando: ${data.done || 0} / ${data.total || '?'} canciones`;
        progressStatus.textContent = data.current_track ? `🎵 ${data.current_track}` : 'Procesando...';

        // update individual track statuses
        if (data.completed_tracks) {
          data.completed_tracks.forEach(idx => {
            const item = document.getElementById(`track-${idx}`);
            const btn  = document.getElementById(`dl-btn-${idx}`);
            if (item) { item.classList.remove('downloading'); item.classList.add('done'); }
            if (btn) {
              btn.disabled = true;
              btn.innerHTML = `<svg class="icon-done" width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z" fill="currentColor"/></svg> ✓`;
            }
          });
        }

        if (data.status === 'done') {
          clearInterval(pollInterval);
          progressBarFill.style.width = '100%';
          progressPct.textContent = '100%';
          progressLabel.textContent = '✅ ¡Descarga completa!';
          progressStatus.textContent = 'Empaquetando ZIP...';

          setTimeout(() => {
            triggerDownload(data.zip_url, data.zip_filename || 'playlist.zip');
            downloadZipBtn.disabled = false;
            downloadZipBtn.querySelector('span').textContent = 'Descargar ZIP completo';
            const sizeMb = data.zip_size_mb ? `${data.zip_size_mb} MB` : '';
            zipSize.textContent = sizeMb;
            progressStatus.textContent = '🎉 ZIP listo – descargando...';
          }, 600);
        }

        if (data.status === 'error') {
          clearInterval(pollInterval);
          showError('Error en el servidor: ' + (data.error || 'desconocido'));
          downloadZipBtn.disabled = false;
          downloadZipBtn.querySelector('span').textContent = 'Descargar ZIP completo';
        }
      } catch (e) {
        // keep polling, might be transient
      }
    }, 2000);
  }

  /* ────────────────────────────────────────── */
  /*  HELPERS                                   */
  /* ────────────────────────────────────────── */
  function triggerDownload(url, filename) {
    const a = document.createElement('a');
    a.href = url;
    a.download = filename || 'download';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  function isSpotifyUrl(url) {
    try {
      const u = new URL(url);
      return u.hostname === 'open.spotify.com';
    } catch { return false; }
  }

  function formatDuration(ms) {
    const total = Math.floor(ms / 1000);
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
  }

  function esc(str) {
    if (!str) return '';
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function showLoading(title, subtitle) {
    loadingTitle.textContent   = title || '';
    loadingSubtitle.textContent = subtitle || '';
    loadingOverlay.style.display = 'flex';
  }
  function hideLoading() {
    loadingOverlay.style.display = 'none';
  }

  let errorTimer = null;
  function showError(msg) {
    errorMessage.textContent = msg;
    errorToast.classList.add('show');
    if (errorTimer) clearTimeout(errorTimer);
    errorTimer = setTimeout(() => errorToast.classList.remove('show'), 5000);
  }

})();
