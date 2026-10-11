<?php
require_once 'auth.php';
$u = current_user();
$title = 'Find a truck for your harvest';
$poll = '';
$sample = ['total' => 91, 'prox' => 88, 'cap' => 100, 'avail' => 100, 'time' => 76];
$factors = [
  ['Distance',      $sample['prox'],  'How close the truck is to your pickup. Less empty driving means a faster, cheaper trip.'],
  ['Load size',     $sample['cap'],   'Whether the vehicle can carry your weight. A 4,200 kg load needs a truck built for it.'],
  ['Availability',  $sample['avail'], 'Whether the truck is free on your pickup date, so you are not waiting on a promise.'],
  ['Delivery time', $sample['time'],  'How soon the produce can reach the market. This matters most for fresh goods.'],
];
include 'includes/header.php';
?>

<div class="lp">
  <span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span><span class="orb o4"></span>

  <!-- 1. Hook -->
  <section class="lp-hero">
    <div class="row align-items-center g-5">
      <div class="col-lg-6">
        <h1 id="headline">Your harvest can't wait for a phone call.</h1>
        <p class="lead mt-3"><?= APP_NAME ?> matches farmers with trucks that are nearby, big enough and free on the day, so produce reaches the market fresh.</p>
        <div class="d-flex flex-wrap gap-2 mt-4 btns">
          <?php if ($u): ?>
            <a class="btn btn-crate btn-lg" href="<?= home_for($u['role']) ?>">Go to my dashboard</a>
          <?php else: ?>
            <a class="btn btn-crate btn-lg" href="register.php?role=farmer">I have produce to move</a>
            <a class="btn btn-outline-ink btn-lg" href="register.php?role=transporter">I have a vehicle</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-6">
        <div class="tilt rv">
          <div class="glass match-card sample">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <span class="rank-tag">Recommended</span>
                <h2 class="h5 mt-2 mb-0">Isuzu FRR, 5 tonne</h2>
                <p class="text-muted small mb-0">Based in Machakos, 42 km from pickup</p>
              </div>
              <div class="total"><span data-count="<?= (int)$sample['total'] ?>">0</span><small>/100</small></div>
            </div>
            <?= gauge($sample) ?>
            <p class="small text-muted mt-3 mb-0">Sample result for 4,200 kg of tomatoes, Mlolongo to Wakulima Market.</p>
          </div>
        </div>
      </div>
    </div>
    <div class="lp-road" aria-hidden="true">
      <span class="lp-stop a">Farm</span><span class="lp-truck">🚚</span><span class="lp-stop b">Market</span>
    </div>
  </section>

  <!-- 2. Problem -->
  <section class="lp-sec">
    <h2 class="rv">Fresh produce spoils fast. Finding a truck is slow.</h2>
    <p class="sub rv" style="--d:100ms">Most farmers find transport by calling around and hoping. Most transporters find loads the same way. Both lose time, and the produce pays for it.</p>
    <div class="lp-split">
      <div class="glass lp-side rv" style="--d:0ms">
        <h3>The farmer</h3>
        <p>Tomatoes are picked and crated, but no truck is confirmed. Every hour of waiting takes value off the load.</p>
      </div>
      <div class="glass lp-side rv" style="--d:150ms">
        <h3>The transporter</h3>
        <p>The truck is free and nearby, but nobody knows. It drives back empty or sits idle.</p>
      </div>
    </div>
    <p class="lp-bridge rv">They are often a few kilometres apart and never meet. <?= APP_NAME ?> connects them.</p>
  </section>

  <!-- 3. Ranking -->
  <section class="lp-sec">
    <h2 class="rv">Every truck gets a score, and you see why.</h2>
    <p class="sub rv" style="--d:100ms">No black box. Each truck is scored out of 100 on four things that decide whether a delivery works. This is the sample truck from the top of the page.</p>
    <div class="glass lp-factors rv">
      <?php foreach ($factors as [$name, $val, $why]): ?>
        <div class="lp-factor">
          <b><?= $name ?></b>
          <div class="bar"><i data-w="<?= (int)$val ?>"></i></div>
          <span class="n"><?= (int)$val ?></span>
          <p><?= $why ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- 4. Steps -->
  <section class="lp-sec">
    <h2 class="rv">From posted load to confirmed truck.</h2>
    <div class="lp-flow" id="flow">
      <div class="prog"></div>
      <div class="lp-st"><b>Post</b><p>Farmers list the produce, weight, route and dates. Transporters list a vehicle, where it is based and when it is free.</p></div>
      <div class="lp-st"><b>Compare</b><p>Every eligible truck gets a score with the reasons shown. You decide who to trust.</p></div>
      <div class="lp-st"><b>Confirm</b><p>One side requests, the other accepts. Both get an SMS with the other's phone number.</p></div>
    </div>
  </section>

  <!-- 5. Offline -->
  <section class="lp-sec">
    <div class="glass lp-sms rv">
      <div>
        <h2>Works on any phone, even without internet.</h2>
        <p class="mb-0">Not every farm has data. When a match is confirmed, both people get a text message, so the deal does not depend on a smartphone.</p>
      </div>
      <div>
        <div class="lp-bubble">Match confirmed. Transporter: +254 7XX XXX XXX. Pickup Friday, 6 am.</div>
        <div class="lp-bubble">Received. I will be at the farm gate by 6.</div>
      </div>
    </div>
  </section>

  <!-- 6. Close -->
  <section class="lp-cta">
    <h2 class="rv">Your next load could already have a truck.</h2>
    <div class="d-flex flex-wrap gap-2 justify-content-center rv" style="--d:120ms">
      <?php if ($u): ?>
        <a class="btn btn-crate btn-lg" href="<?= home_for($u['role']) ?>">Go to my dashboard</a>
      <?php else: ?>
        <a class="btn btn-crate btn-lg" href="register.php?role=farmer">I have produce to move</a>
        <a class="btn btn-outline-ink btn-lg" href="register.php?role=transporter">I have a vehicle</a>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
(function () {
  var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelectorAll(s); };

  // headline: words sharpen in one by one
  var h = document.getElementById('headline');
  if (h) {
    h.innerHTML = h.textContent.trim().split(/\s+/).map(function (w, i) {
      return '<span class="w" style="--i:' + i + '">' + w + '</span>';
    }).join(' ');
  }

  // hero score counts up
  $('[data-count]').forEach(function (el) {
    var end = +el.dataset.count;
    if (reduce) { el.textContent = end; return; }
    setTimeout(function () {
      var s = null;
      (function step(t) {
        s = s || t;
        var p = Math.min((t - s) / 1800, 1);
        el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3)));
        if (p < 1) requestAnimationFrame(step);
      })(performance.now());
    }, 900);
  });

  // reveal on scroll, fill score bars
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      if (!e.isIntersecting) return;
      e.target.classList.add('in');
      $('.bar i', e.target).forEach(function (b) { b.style.width = b.dataset.w + '%'; });
      io.unobserve(e.target);
    });
  }, { threshold: .2 });
  $('.rv').forEach(function (el) { io.observe(el); });

  // pointer light on glass panels
  $('.glass').forEach(function (g) {
    g.addEventListener('pointermove', function (e) {
      var r = g.getBoundingClientRect();
      g.style.setProperty('--mx', (e.clientX - r.left) + 'px');
      g.style.setProperty('--my', (e.clientY - r.top) + 'px');
    });
  });

  if (reduce) { $('.bar i').forEach(function (b) { b.style.width = b.dataset.w + '%'; }); return; }

  // hero card tilts toward the pointer
  var tilt = document.querySelector('.tilt .glass');
  if (tilt && window.matchMedia('(hover:hover)').matches) {
    var wrap = tilt.parentNode;
    wrap.addEventListener('pointermove', function (e) {
      var r = wrap.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
      tilt.style.transform = 'rotateY(' + (x * 9) + 'deg) rotateX(' + (-y * 9) + 'deg)';
    });
    wrap.addEventListener('pointerleave', function () { tilt.style.transform = ''; });
  }

  // colour orbs drift at a different speed from the page
  var orbs = $('.orb'), ticking = false;
  function onScroll() {
    if (ticking) return; ticking = true;
    requestAnimationFrame(function () {
      var y = window.scrollY;
      orbs.forEach(function (o, i) { o.style.transform = 'translateY(' + (y * (.08 + i * .03)) + 'px)'; });
      var flow = document.getElementById('flow');
      if (flow) {
        var r = flow.getBoundingClientRect(), vh = window.innerHeight;
        var p = Math.min(Math.max((vh * .6 - r.top) / r.height, 0), 1);
        flow.querySelector('.prog').style.height = (p * (r.height - 12)) + 'px';
        $('.lp-st', flow).forEach(function (s) { s.classList.toggle('on', s.getBoundingClientRect().top < vh * .6); });
      }
      ticking = false;
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
})();
</script>
<?php include 'includes/footer.php'; ?>