/*
 * track.js : locations and live tracking.
 *
 *   Driver : shares the phone's GPS position every 10 seconds from the moment a load is matched.
 *            ?demo=1 on the dashboard address adds a "Simulate drive (demo)" button (no GPS needed).
 *   Farmer : sets a pickup pin once (current location, or by tapping the map).
 *   Both   : a map polls api_track.php every 8 seconds. The farmer sees the truck; the driver sees the farmer's pin,
 *            the drop-off and Navigate buttons that open Google Maps. (Leaflet + OpenStreetMap, no API key.)
 */
(function () {
  window.__trackLoaded = true;   // lets the page tell whether this file was found

  // ---------- small helpers ----------
  function setText(el, text) { if (el) el.textContent = text; }

  function haversineKm(lat1, lon1, lat2, lon2) {
    var r = Math.PI / 180, dLat = (lat2 - lat1) * r, dLon = (lon2 - lon1) * r;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(lat1 * r) * Math.cos(lat2 * r) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(a)));
  }

  // Read a JSON answer; if the server sent something else (404, PHP error, login page) say what it was
  function readJson(r) {
    return r.text().then(function (t) {
      try { return JSON.parse(t); }
      catch (e) {
        var err = new Error('bad answer');
        err.snippet = 'HTTP ' + r.status + ': ' + t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 160);
        throw err;
      }
    });
  }
  function failText(e, fallback) { return e && e.snippet ? 'Server problem. ' + e.snippet : fallback; }

  function agoText(s) {
    if (s < 10) return 'just now';
    if (s < 60) return s + ' seconds ago';
    if (s < 3600) return Math.round(s / 60) + ' min ago';
    return Math.round(s / 3600) + ' h ago';
  }

  // ---------------- Driver: share location ----------------
  var shareForm = document.getElementById('shareForm');
  if (shareForm) {
    var SEND_EVERY = 10000;
    var sessions = {};   // load id -> { watchId, timer, latest, statusEl, btn, lock }

    // One place that sends a position to the server (used by real GPS and by the demo)
    var postPoint = function (loadId, lat, lng, speedKmh, statusEl, label) {
      var fd = new FormData(shareForm);               // carries the CSRF token
      fd.append('action', 'share');
      fd.append('load_id', loadId);
      fd.append('lat', lat);
      fd.append('lng', lng);
      if (speedKmh != null && !isNaN(speedKmh)) fd.append('speed', Math.round(speedKmh));
      return fetch('api/api_track.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(readJson)
        .then(function (d) { setText(statusEl, d.ok ? label + ' Last sent ' + new Date().toLocaleTimeString() : (d.error || 'Could not send.')); })
        .catch(function (e) { setText(statusEl, failText(e, 'No connection. Trying again.')); });
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

  // ---------------- Maps: farmer card and driver trip cards ----------------
  function startTracker(box) {
    var role = box.getAttribute('data-role');            // 'farmer' or 'driver'
    var loadId = box.getAttribute('data-load-id');
    var mapEl = box.querySelector('.trip-map');
    var info = box.querySelector('.trip-info');
    var nav = box.querySelector('.trip-nav');
    var controls = box.querySelector('.pin-controls');
    var pinForm = box.querySelector('.pin-form');
    var btnGps = box.querySelector('.btn-pin-gps');
    var btnMap = box.querySelector('.btn-pin-map');
    var pinStatus = box.querySelector('.pin-status');

    var map = null, truck = null, trail = null, pinMarker = null, fitted = false, pickMode = false;

    function tip(text) { var s = document.createElement('span'); s.textContent = text; return s; }

    function ensureMap(d) {
      if (map) return;
      map = L.map(mapEl).setView([d.origin.lat, d.origin.lng], 8);
      L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
      L.circleMarker([d.origin.lat, d.origin.lng], { radius: 6, color: '#1b7f4b', fillColor: '#1b7f4b', fillOpacity: 1 }).bindTooltip(tip('Pickup area: ' + d.origin.name)).addTo(map);
      L.circleMarker([d.dest.lat, d.dest.lng], { radius: 8, color: '#c0392b', fillColor: '#c0392b', fillOpacity: 1 }).bindTooltip(tip('Drop-off: ' + d.dest.name)).addTo(map);
      trail = L.polyline([], { color: '#f0b429', weight: 4 }).addTo(map);
      pinMarker = L.circleMarker([d.origin.lat, d.origin.lng], { radius: 10, color: '#1d4f91', weight: 3, fillColor: '#3b82f6', fillOpacity: 1 }).bindTooltip(tip('Pickup pin (the farmer)'));
      map.on('click', function (e) {
        if (role === 'farmer' && pickMode) { setPickMode(false); savePin(e.latlng.lat, e.latlng.lng); }
      });
    }

    // ----- farmer: save the pickup pin -----
    function setPickMode(on) {
      pickMode = on;
      if (btnMap) btnMap.textContent = on ? 'Tap the map where the truck should come' : 'Pick the pickup spot on the map';
    }

    function savePin(lat, lng) {
      var fd = new FormData(pinForm);
      fd.append('action', 'pin');
      fd.append('load_id', loadId);
      fd.append('lat', lat);
      fd.append('lng', lng);
      setText(pinStatus, 'Saving...');
      fetch('api/api_track.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(readJson)
        .then(function (d) { setText(pinStatus, d.ok ? 'Pickup pin saved.' : (d.error || 'Could not save the pin.')); if (d.ok) poll(); })
        .catch(function (e) { setText(pinStatus, failText(e, 'No connection. Try again.')); });
    }

    if (btnGps) btnGps.addEventListener('click', function () {
      if (!navigator.geolocation) { setText(pinStatus, 'This browser cannot share location. Use the map instead.'); return; }
      setText(pinStatus, 'Finding your location...');
      navigator.geolocation.getCurrentPosition(
        function (p) { savePin(p.coords.latitude, p.coords.longitude); },
        function (err) { setText(pinStatus, err.code === 1 ? 'Location permission denied. Use the map instead.' : 'Could not get your location. Use the map instead.'); },
        { enableHighAccuracy: true, timeout: 15000 }
      );
    });
    if (btnMap) btnMap.addEventListener('click', function () { setPickMode(!pickMode); });

    // ----- driver: Navigate buttons (open Google Maps / the Maps app) -----
    function setNav(d, pin) {
      if (!nav) return;
      nav.textContent = '';
      var pickup = pin ? [pin.lat, pin.lng] : [d.origin.lat, d.origin.lng];
      [['Navigate to pickup', pickup], ['Navigate to drop-off', [d.dest.lat, d.dest.lng]]].forEach(function (x) {
        var a = document.createElement('a');
        a.className = 'btn btn-outline-ink btn-sm';
        a.target = '_blank';
        a.rel = 'noopener';
        a.href = 'https://www.google.com/maps/dir/?api=1&travelmode=driving&destination=' + x[1][0] + ',' + x[1][1];
        a.textContent = x[0];
        nav.appendChild(a);
      });
    }

    // ----- the sentence under the title -----
    function message(d, pin) {
      var target = pin ? pin : { lat: d.origin.lat, lng: d.origin.lng };
      var toPickupKm = d.last ? Math.round(haversineKm(d.last.lat, d.last.lng, target.lat, target.lng) * 1.3) : null;

      if (d.status === 'delivered') return 'Delivered.';
      if (d.status === 'open') {
        return pin ? 'Your pickup pin is saved. The driver will see it once a truck is confirmed.'
                   : 'Share your pickup location so the driver can find you easily.';
      }
      if (d.status === 'matched') {
        if (role === 'farmer') {
          return 'A truck is confirmed.' +
            (toPickupKm !== null ? ' It is about ' + toPickupKm + ' km from your pickup spot.' : ' It will appear on the map when the driver shares their location.') +
            (pin ? '' : ' Set your pickup pin so the driver can find you.');
        }
        return (pin ? 'Go to the farmer\'s pickup pin (blue).' : 'The farmer has not set an exact pin yet, so go to the pickup area (green).') +
          (toPickupKm !== null ? ' About ' + toPickupKm + ' km away.' : '');
      }
      // in transit
      if (!d.last) return role === 'farmer' ? 'The trip has started. Waiting for the driver to share their location.' : 'Trip started. Share your location so the farmer can follow.';
      var mins = Math.max(1, Math.round(d.eta_hours * 60));
      var inText = mins < 60 ? mins + ' min' : Math.floor(mins / 60) + ' h ' + (mins % 60) + ' min';
      var at = new Date(Date.now() + mins * 60000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      var text = (d.last.speed != null ? 'Moving at ' + d.last.speed + ' km/h. ' : '') + d.remaining_km + ' km to go' +
                 (d.progress != null ? ' (' + d.progress + '% of the way)' : '') +
                 '. Arriving about ' + at + ' (in ' + inText + '). Last update ' + agoText(d.last.ago) + '.';
      if (d.last.ago > 120) text += ' The driver may be out of signal.';
      return text;
    }

    function render(d) {
      if (d.error || d.status === 'cancelled' || (role === 'driver' && d.status === 'open')) { box.style.display = 'none'; return; }
      box.style.display = '';
      ensureMap(d);
      map.invalidateSize();

      var pin = d.pin || null;
      var bounds = [[d.origin.lat, d.origin.lng], [d.dest.lat, d.dest.lng]];
      if (pin) {
        pinMarker.setLatLng([pin.lat, pin.lng]);
        if (!map.hasLayer(pinMarker)) pinMarker.addTo(map);
        bounds.push([pin.lat, pin.lng]);
      }
      trail.setLatLngs(d.trail);
      if (d.last) {
        var ll = [d.last.lat, d.last.lng];
        if (!truck) truck = L.circleMarker(ll, { radius: 10, color: '#0b3d2e', weight: 3, fillColor: '#f0b429', fillOpacity: 1 }).bindTooltip(tip(role === 'farmer' ? 'Your produce' : 'You')).addTo(map);
        else truck.setLatLng(ll);
        bounds.push(ll);
      }
      if (!fitted) { map.fitBounds(bounds, { padding: [30, 30] }); fitted = true; }

      if (controls) controls.style.display = d.status === 'delivered' ? 'none' : '';
      if (role === 'driver') setNav(d, pin);
      setText(info, message(d, pin));
    }

    function poll() {
      if (document.hidden) return;
      fetch('api/api_track.php?load_id=' + encodeURIComponent(loadId), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(render)
        .catch(function () {});
    }
    poll();
    setInterval(poll, 8000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
  }

  var boxes = document.querySelectorAll('.trip-box');
  if (boxes.length && typeof L !== 'undefined') boxes.forEach(startTracker);
})();