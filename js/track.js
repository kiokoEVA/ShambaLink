/*
 * track.js : live tracking.
 *   Driver page : shares the phone's GPS position every 10 seconds while a trip is in transit.
 *                 Add ?demo=1 to the dashboard address to get a "Simulate drive (demo)" button
 *                 that walks the truck from pickup to drop-off without any GPS (for testing and demos).
 *   Farmer page : asks api_track.php every 8 seconds and moves the truck on a map (Leaflet + OpenStreetMap).
 */
(function () {

  // ---------------- Driver: share location ----------------
  var shareForm = document.getElementById('shareForm');
  if (shareForm) {
    var SEND_EVERY = 10000;
    var sessions = {};   // load id -> { watchId, timer, latest, statusEl, btn, lock }

    var setText = function (el, text) { if (el) el.textContent = text; };

    // One place that sends a position to the server (used by real GPS and by the demo)
    var postPoint = function (loadId, lat, lng, speedKmh, statusEl, label) {
      var fd = new FormData(shareForm);               // carries the CSRF token
      fd.append('action', 'share');
      fd.append('load_id', loadId);
      fd.append('lat', lat);
      fd.append('lng', lng);
      if (speedKmh != null && !isNaN(speedKmh)) fd.append('speed', Math.round(speedKmh));
      return fetch('api_track.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { setText(statusEl, d.ok ? label + ' Last sent ' + new Date().toLocaleTimeString() : (d.error || 'Could not send.')); })
        .catch(function () { setText(statusEl, 'No connection. Trying again.'); });
    };

    var send = function (loadId) {
      var s = sessions[loadId];
      if (!s || !s.latest) return;
      var c = s.latest.coords;
      postPoint(loadId, c.latitude, c.longitude, c.speed != null ? c.speed * 3.6 : null, s.statusEl, 'Sharing.');
    };

    var start = function (loadId, btn, statusEl) {
      if (!navigator.geolocation) { setText(statusEl, 'This browser cannot share location.'); return; }
      var s = sessions[loadId] = { latest: null, statusEl: statusEl, btn: btn };
      setText(statusEl, 'Waiting for GPS...');
      s.watchId = navigator.geolocation.watchPosition(
        function (pos) { var first = !s.latest; s.latest = pos; if (first) send(loadId); },
        function (err) { setText(statusEl, err.code === 1 ? 'Location permission denied. Allow it in the browser settings.' : 'Could not get your location.'); },
        { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 }
      );
      s.timer = setInterval(function () { send(loadId); }, SEND_EVERY);
      try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (l) { s.lock = l; }).catch(function () {}); } catch (e) {}
      btn.textContent = 'Stop sharing';
      try { localStorage.setItem('share_' + loadId, '1'); } catch (e) {}
    };

    var stop = function (loadId) {
      var s = sessions[loadId];
      if (!s) return;
      navigator.geolocation.clearWatch(s.watchId);
      clearInterval(s.timer);
      try { if (s.lock) s.lock.release(); } catch (e) {}
      s.btn.textContent = 'Start sharing location';
      setText(s.statusEl, 'Not sharing.');
      try { localStorage.removeItem('share_' + loadId); } catch (e) {}
      delete sessions[loadId];
    };

    document.querySelectorAll('[data-share-load]').forEach(function (btn) {
      var id = btn.getAttribute('data-share-load');
      var statusEl = document.querySelector('[data-share-status="' + id + '"]');
      btn.addEventListener('click', function () { if (sessions[id]) stop(id); else start(id, btn, statusEl); });
      var remembered = false;
      try { remembered = localStorage.getItem('share_' + id) === '1'; } catch (e) {}
      if (remembered) start(id, btn, statusEl);       // carry on after a page reload
    });

    // ----- Demo: walk the truck from pickup to drop-off in 12 steps, 5 seconds apart (about 1 minute) -----
    document.querySelectorAll('[data-sim-load]').forEach(function (btn) {
      var id = btn.getAttribute('data-sim-load');
      var statusEl = document.querySelector('[data-share-status="' + id + '"]');
      var a = [parseFloat(btn.getAttribute('data-o-lat')), parseFloat(btn.getAttribute('data-o-lng'))];
      var b = [parseFloat(btn.getAttribute('data-d-lat')), parseFloat(btn.getAttribute('data-d-lng'))];
      var STEPS = 12, step = 0, timer = null;

      var finish = function (text) {
        clearInterval(timer); timer = null;
        btn.textContent = 'Simulate drive (demo)';
        setText(statusEl, text);
      };

      btn.addEventListener('click', function () {
        if (timer) { finish('Simulation stopped.'); return; }
        step = 0;
        btn.textContent = 'Stop simulation';
        var tick = function () {
          var f = Math.min(1, step / STEPS);
          postPoint(id, a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f, 40 + Math.round(Math.random() * 20), statusEl, 'Demo drive ' + Math.round(f * 100) + '%.');
          step++;
          if (step > STEPS) finish('Demo finished. Mark the load as delivered.');
        };
        tick();
        timer = setInterval(tick, 5000);
      });
    });
  }

  // ---------------- Farmer: watch the truck ----------------
  var box = document.getElementById('trackBox');
  if (box && typeof L !== 'undefined') {
    var loadId = box.getAttribute('data-load-id');
    var info = document.getElementById('trackInfo');
    var map = null, truck = null, trail = null, fitted = false;

    var label = function (text) { var s = document.createElement('span'); s.textContent = text; return s; };

    var ensureMap = function (d) {
      if (map) return;
      map = L.map('trackMap').setView([d.origin.lat, d.origin.lng], 8);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
      L.circleMarker([d.origin.lat, d.origin.lng], { radius: 7, color: '#1b7f4b', fillColor: '#1b7f4b', fillOpacity: 1 }).bindTooltip(label('Pickup: ' + d.origin.name)).addTo(map);
      L.circleMarker([d.dest.lat, d.dest.lng], { radius: 7, color: '#c0392b', fillColor: '#c0392b', fillOpacity: 1 }).bindTooltip(label('Drop-off: ' + d.dest.name)).addTo(map);
      trail = L.polyline([], { color: '#f0b429', weight: 4 }).addTo(map);
    };

    var ago = function (s) {
      if (s < 10) return 'just now';
      if (s < 60) return s + ' seconds ago';
      if (s < 3600) return Math.round(s / 60) + ' min ago';
      return Math.round(s / 3600) + ' h ago';
    };

    var render = function (d) {
      if (d.error || d.status === 'open' || d.status === 'cancelled') { box.style.display = 'none'; return; }
      box.style.display = '';
      ensureMap(d);
      map.invalidateSize();
      trail.setLatLngs(d.trail);

      var bounds = [[d.origin.lat, d.origin.lng], [d.dest.lat, d.dest.lng]];
      if (d.last) {
        var ll = [d.last.lat, d.last.lng];
        if (!truck) truck = L.circleMarker(ll, { radius: 10, color: '#0b3d2e', weight: 3, fillColor: '#f0b429', fillOpacity: 1 }).bindTooltip(label('Your produce')).addTo(map);
        else truck.setLatLng(ll);
        bounds.push(ll);
      }
      if (!fitted) { map.fitBounds(bounds, { padding: [30, 30] }); fitted = true; }

      var text;
      if (d.status === 'delivered') text = 'Delivered.';
      else if (d.status === 'matched') text = 'A truck is confirmed. Tracking starts when the driver begins the trip.';
      else if (!d.last) text = 'The trip has started. Waiting for the driver to share their location.';
      else {
        var mins = Math.max(1, Math.round(d.eta_hours * 60));
        var inText = mins < 60 ? mins + ' min' : Math.floor(mins / 60) + ' h ' + (mins % 60) + ' min';
        var at = new Date(Date.now() + mins * 60000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        text = (d.last.speed != null ? 'Moving at ' + d.last.speed + ' km/h. ' : '') + d.remaining_km + ' km to go' +
               (d.progress != null ? ' (' + d.progress + '% of the way)' : '') +
               '. Arriving about ' + at + ' (in ' + inText + '). Last update ' + ago(d.last.ago) + '.';
        if (d.last.ago > 120) text += ' The driver may be out of signal.';
      }
      info.textContent = text;
    };

    var poll = function () {
      if (document.hidden) return;
      fetch('api_track.php?load_id=' + encodeURIComponent(loadId), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(render)
        .catch(function () {});
    };
    poll();
    setInterval(poll, 8000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  }
})();