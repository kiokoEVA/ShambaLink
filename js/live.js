/*
 * live.js : short AJAX polling.
 * Every 5 seconds it asks api_poll.php for a "version" of the user's data.
 * If the version changed, it re-fetches the current page and swaps in only the #live section.
 */
(function () {
  var live = document.getElementById('live');
  if (!live) return;

  var role = live.getAttribute('data-role');
  var INTERVAL = 5000;
  var lastVersion = null;
  var busy = false;

  function refreshSection() {
    return fetch(location.href, { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var fresh = doc.getElementById('live');
        if (fresh && fresh.innerHTML !== live.innerHTML) live.innerHTML = fresh.innerHTML;
      });
  }

  function poll() {
    if (document.hidden || busy) return;   // do nothing while the tab is in the background
    busy = true;
    fetch('api_poll.php?role=' + encodeURIComponent(role), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (d) {
        var changed = lastVersion !== null && d.v !== lastVersion;
        lastVersion = d.v;
        return changed ? refreshSection() : null;
      })
      .catch(function () { /* offline or logged out: try again next time */ })
      .then(function () { busy = false; });
  }

  poll();
  setInterval(poll, INTERVAL);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
})();