/* ==========================================================================
   QrGate · handheld — shared scanner engine
   Drives the camera (html5-qrcode low-level API), the result sheet, torch,
   vibration and clock for BOTH the Scanner and the Inspector page.
   Each page sets window.HH_CONFIG before loading this file:
     { mode:'validate'|'inspect', payloadKey:'ticketId'|'tid',
       validText, invalidText, showTimeline:bool }
   ========================================================================== */
(function () {
  "use strict";

  var CFG = window.HH_CONFIG || {};
  var PAYLOAD_KEY = CFG.payloadKey || "ticketId";
  var SHOW_TIMELINE = !!CFG.showTimeline;
  var VALID_TEXT = CFG.validText || "Valid";
  var INVALID_TEXT = CFG.invalidText || "Invalid";
  // Loud, distinct wording for a re-scan of an ALREADY-used ticket. This is the
  // fraud/double-entry case — it must never look like an ordinary "Invalid".
  var REUSE_TEXT = CFG.reuseText || "Already used!";
  var REUSE_HINT = CFG.reuseHint || "First scanned:";

  var SAME_COOLDOWN = 4000;
  var REQUEST_TIMEOUT = 6500; // abort a hung validate fast — 200 people are waiting
  var AUTO_ADVANCE_MS = 1200; // how long a VALID result stays up in auto-advance mode
  var lastCode = "";
  var lastTime = 0;
  var busy = false;
  var scanner = null;
  var torchOn = false;
  var torchAvailable = false;
  var autoAdvance = false;     // auto-dismiss VALID results (toggle, default off)
  var autoAdvanceTimer = null; // pending auto-dismiss, cancelled on manual interaction
  var netRetryTimer = null;    // clears the transient "reconnecting" pill state

  var $ = function (id) { return document.getElementById(id); };

  // ---- icons (inline lucide paths) --------------------------------------
  var ICON = {
    ticket: '<path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/><path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>',
    user: '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
    coins: '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4"/><path d="m16.71 13.88.7.71-2.82 2.82"/>',
    calendar: '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
    clock: '<path d="M12 6v6l4 2"/><circle cx="12" cy="12" r="10"/>',
    check: '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><path d="m9 12 2 2 4-4"/>',
    cross: '<path d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z"/><line x1="15" x2="9" y1="9" y2="15"/><line x1="9" x2="15" y1="9" y2="15"/>',
    userCheck: '<path d="M2 21a8 8 0 0 1 13.292-6"/><circle cx="10" cy="8" r="5"/><path d="m16 19 2 2 4-4"/>',
    userX: '<path d="M2 21a8 8 0 0 1 11.873-7"/><circle cx="10" cy="8" r="5"/><path d="m17 17 5 5"/><path d="m22 17-5 5"/>',
    alert: '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>'
  };
  function svg(paths, cls) {
    return '<svg class="' + (cls || "") + '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" ' +
      'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + paths + "</svg>";
  }
  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  // ---- helpers ----------------------------------------------------------
  function todayISO() {
    var d = new Date();
    return d.getFullYear() + "-" +
      String(d.getMonth() + 1).padStart(2, "0") + "-" +
      String(d.getDate()).padStart(2, "0");
  }
  function vibrate(pattern) {
    if (navigator.vibrate) { try { navigator.vibrate(pattern); } catch (e) {} }
  }
  function play(file) {
    try { new Audio("./" + file).play().catch(function () {}); } catch (e) {}
  }
  function toast(msg) {
    var t = $("hhToast");
    t.textContent = msg;
    t.classList.add("show");
    clearTimeout(toast._t);
    toast._t = setTimeout(function () { t.classList.remove("show"); }, 2600);
  }

  // ---- connectivity indicator -------------------------------------------
  // Always-visible pill so door staff can see at a glance whether the device
  // can still reach the server. Driven by navigator.onLine + online/offline
  // events, and flipped to "reconnecting" whenever a validate fetch fails.
  // This is a network-health hint only — it never affects ticket verdicts.
  function setNet(state) {
    var pill = $("hhNet");
    if (!pill) return; // inspector page has no pill — degrade gracefully
    pill.classList.remove("is-online", "is-offline", "is-reconnecting");
    var dot = $("hhNetDot");
    var label = $("hhNetLabel");
    if (state === "offline") {
      pill.classList.add("is-offline");
      if (label) label.textContent = "Offline";
    } else if (state === "reconnecting") {
      pill.classList.add("is-reconnecting");
      if (label) label.textContent = "Verbinde…";
    } else {
      pill.classList.add("is-online");
      if (label) label.textContent = "Online";
    }
    if (dot) { /* colour handled via class on the pill */ }
  }
  function netFromBrowser() {
    setNet(navigator.onLine === false ? "offline" : "online");
  }
  // a failed/timed-out fetch means the door is degraded even if the OS still
  // reports onLine; show "reconnecting" briefly, then settle back to truth.
  function netDegraded() {
    setNet(navigator.onLine === false ? "offline" : "reconnecting");
    clearTimeout(netRetryTimer);
    netRetryTimer = setTimeout(netFromBrowser, 3000);
  }
  // a successful round-trip proves the door is live again.
  function netRecovered() {
    clearTimeout(netRetryTimer);
    netFromBrowser();
  }

  // ---- result rendering -------------------------------------------------
  function row(icon, label, valueHtml) {
    return '<div class="hh-row">' + svg(icon, "hh-row__ico") +
      '<div class="hh-row__main"><div class="hh-row__label">' + label + "</div>" +
      '<div class="hh-row__value">' + valueHtml + "</div></div></div>";
  }

  // A denial is a RE-USE (double entry) when the server rejected it precisely
  // because the ticket was already consumed. Keyed off the server message so it
  // is unambiguous vs. "not paid" / "wrong date" / "not found".
  function isReuse(d) {
    return !!(d && d.status === "error" && d.message === "Ticket already used");
  }
  // When the ticket was first (legitimately) let in, so staff can see exactly
  // when the original entry happened: prefer the first successful attempt, else
  // fall back to used_at.
  function firstScanTime(t) {
    var a = (t.access_attempts || []).filter(function (x) { return x.status === "success"; });
    if (a.length && a[0].time) return a[0].time;
    return t.used_at || "";
  }

  function renderBody(d) {
    var t = d.data || {};
    var attempts = t.access_attempts || [];
    var okCount = attempts.filter(function (a) { return a.status === "success"; }).length;
    var failCount = attempts.filter(function (a) { return a.status === "error"; }).length;
    var isToday = todayISO() === t.valid_date;

    var html = "";
    // Loud alarm banner at the very top for a re-scanned (already used) ticket.
    if (isReuse(d)) {
      html += '<div class="hh-alarm">' + svg(ICON.alert, "hh-alarm__ico") +
        '<div class="hh-alarm__main"><div class="hh-alarm__t">' + REUSE_TEXT + "</div>" +
        '<div class="hh-alarm__s">' + REUSE_HINT + " " +
        (esc(firstScanTime(t)) || "&mdash;") + "</div></div></div>";
    }

    html += '<div class="hh-rows">';
    html += row(ICON.ticket, "Ticket ID", '<span class="mono">' + esc(t.tid) + "</span>");
    html += row(ICON.user, "Name", esc((t.first_name || "") + " " + (t.last_name || "")).trim() || "&mdash;");
    html += row(ICON.tag, "Type", esc(t.type || "&mdash;"));
    html += row(ICON.coins, "Paid",
      t.paid === true ? '<span class="hh-pill ok">Yes</span>'
                      : '<span class="hh-pill no">No</span>');
    html += row(ICON.calendar, "Valid on",
      esc(t.valid_date) + ' &nbsp;<span class="hh-pill ' + (isToday ? "ok" : "no") + '">' +
      (isToday ? "Today" : "Not today") + "</span>");
    html += row(ICON.clock, "Used at", esc(t.used_at || "Not used yet"));
    html += "</div>";

    // attempt stats
    html += '<div class="hh-stats">' +
      '<div class="hh-stat"><div class="hh-stat__num">' + attempts.length + '</div><div class="hh-stat__lbl">Attempts</div></div>' +
      '<div class="hh-stat ok"><div class="hh-stat__num">' + okCount + '</div><div class="hh-stat__lbl">Granted</div></div>' +
      '<div class="hh-stat no"><div class="hh-stat__num">' + failCount + '</div><div class="hh-stat__lbl">Denied</div></div>' +
      "</div>";

    // full timeline (inspector only)
    if (SHOW_TIMELINE && attempts.length) {
      html += '<div class="hh-timeline"><h4>History</h4>';
      attempts.slice().reverse().forEach(function (a) {
        var cls = a.status === "success" ? "success" : "error";
        var ic = a.status === "success" ? ICON.userCheck : ICON.userX;
        html += '<div class="hh-att ' + cls + '">' + svg(ic, "hh-att__ico") +
          '<div><div class="hh-att__t">' + esc(a.status) + ' &middot; ' + esc(a.type || "") + "</div>" +
          '<div class="hh-att__s">' + esc(a.time || "") + "</div></div></div>";
      });
      html += "</div>";
    }
    return html;
  }

  function showResult(valid, d) {
    var sheet = $("hhResult");
    var reuse = !valid && isReuse(d);
    sheet.classList.remove("valid", "invalid", "reuse");
    sheet.classList.add(valid ? "valid" : "invalid");
    if (reuse) sheet.classList.add("reuse");

    $("hhResultIcon").innerHTML = svg(valid ? ICON.check : (reuse ? ICON.alert : ICON.cross));
    $("hhResultStatus").textContent = valid ? VALID_TEXT : (reuse ? REUSE_TEXT : INVALID_TEXT);
    $("hhResultMsg").textContent = (d && d.message) || "";

    $("hhResultBody").innerHTML = (d && d.data) ? renderBody(d) : "";
    sheet.classList.add("show");

    play(valid ? "success.mp3" : "error.mp3");
    // A re-used ticket gets a longer, unmistakable buzz pattern + a second error
    // tone so a busy door can't wave it through by reflex.
    vibrate(valid ? 80 : (reuse ? [140, 60, 140, 60, 260] : [60, 50, 60]));
    if (reuse) setTimeout(function () { play("error.mp3"); }, 280);

    // Auto-advance applies ONLY to VALID results: keep the green flash + sound,
    // then dismiss so staff don't tap per guest. FAIL always stays up for a
    // deliberate manual dismiss so a denial can never be missed.
    clearTimeout(autoAdvanceTimer);
    if (autoAdvance && valid) {
      autoAdvanceTimer = setTimeout(dismissResult, AUTO_ADVANCE_MS);
    }
  }

  function dismissResult() {
    clearTimeout(autoAdvanceTimer);
    $("hhResult").classList.remove("show");
    busy = false;
    // keep lastCode/lastTime so the just-handled ticket (now consumed on the
    // server) can't be instantly re-fired within the cooldown window.
    if (scanner) { try { scanner.resume(); } catch (e) {} }
    document.querySelector(".hh-app").classList.remove("is-paused");
  }

  function resumeScanning() {
    busy = false;
    if (scanner) { try { scanner.resume(); } catch (e) {} }
    document.querySelector(".hh-app").classList.remove("is-paused");
  }

  // ---- scan handling ----------------------------------------------------
  // Camera path: de-dupe + cooldown, then hand the decoded code to validate().
  function onScan(code) {
    var now = Date.now();
    if (busy) return;
    if (code === lastCode && now - lastTime < SAME_COOLDOWN) return;
    lastCode = code; lastTime = now;
    validate(code, { fromCamera: true });
  }

  // Single server round-trip used by BOTH the camera and the manual-entry
  // fallback. The verdict is ALWAYS the server's — there is no client-side
  // "valid" decision and no offline admit. Manual entry simply feeds a typed
  // ticket id through the exact same endpoint as a scan.
  function validate(code, opts) {
    opts = opts || {};
    if (busy) return;
    busy = true;

    if (scanner) { try { scanner.pause(true); } catch (e) {} }
    document.querySelector(".hh-app").classList.add("is-paused");
    $("hhSpinner").classList.add("show");

    var payload = {}; payload[PAYLOAD_KEY] = code;

    // abort a hung request fast so the scanner never stalls at a busy door
    var ctrl = (typeof AbortController !== "undefined") ? new AbortController() : null;
    var timedOut = false;
    var timer = setTimeout(function () {
      timedOut = true;
      if (ctrl) { try { ctrl.abort(); } catch (e) {} }
    }, REQUEST_TIMEOUT);

    fetch(window.location.href, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      cache: "no-store",
      signal: ctrl ? ctrl.signal : undefined
    })
      .then(function (r) {
        return r.json().then(
          function (data) { return { ok: r.ok, status: r.status, data: data }; },
          function () { return { ok: r.ok, status: r.status, data: null }; }
        );
      })
      .then(function (res) {
        clearTimeout(timer);
        $("hhSpinner").classList.remove("show");
        // A transport/server failure (no JSON, or HTTP 401/5xx) is NOT a ticket
        // verdict — never present it as a normal "Invalid" ticket to the operator.
        if (!res.data || (!res.ok && res.status >= 500)) {
          netDegraded();
          play("error.mp3");
          vibrate([60, 50, 60]);
          toast(res.data && res.data.message ? res.data.message : "Server error — try again");
          resumeScanning();
          lastCode = ""; // a server fault is not a real scan — allow immediate retry
          if (opts.onSettled) opts.onSettled(false);
          return;
        }
        netRecovered(); // a real round-trip proves the door is live again
        // Verdict is decided strictly by the server response.
        var valid = res.data.status === "success";
        showResult(valid, res.data);
        if (opts.onSettled) opts.onSettled(true);
      })
      .catch(function (err) {
        clearTimeout(timer);
        $("hhSpinner").classList.remove("show");
        netDegraded(); // no connection — flag it on the pill and let staff retry
        play("error.mp3");
        vibrate([60, 50, 60]);
        toast(timedOut ? "Keine Verbindung — erneut versuchen" : "Netzwerkfehler — erneut versuchen");
        // a network failure is never a "valid" verdict; re-enable scanning now
        resumeScanning();
        lastCode = "";
        if (opts.onSettled) opts.onSettled(false);
      });
  }

  // ---- manual TID entry (damaged QR / camera denied) --------------------
  // Sends a typed ticket id through the SAME server endpoint as a scan, so the
  // verdict stays server-authoritative. No client-side admit, ever.
  function submitManual() {
    var input = $("hhManualInput");
    if (!input) return;
    var code = (input.value || "").trim();
    if (!code) { input.focus(); return; }
    if (busy) { toast("Bitte warten…"); return; }
    // bypass the per-code cooldown for deliberate manual entries
    lastCode = code; lastTime = Date.now();
    if (input.blur) input.blur();
    validate(code, {
      fromManual: true,
      onSettled: function () { input.value = ""; }
    });
  }

  // ---- camera lifecycle -------------------------------------------------
  function qrbox(vw, vh) {
    var m = Math.floor(Math.min(vw, vh) * 0.7);
    return { width: m, height: m };
  }

  function start() {
    $("hhStart").classList.remove("show");
    scanner = new Html5Qrcode("reader", { verbose: false });
    scanner.start(
      { facingMode: "environment" },
      { fps: 10, qrbox: qrbox },
      onScan,
      function () { /* per-frame decode misses — ignore */ }
    ).then(function () {
      detectTorch();
    }).catch(function (err) {
      showStart(err);
    });
  }

  // Camera failed to start. Detect the unrecoverable cases (a hard permission
  // deny won't re-prompt; no camera at all) and give targeted guidance, then
  // surface the manual-entry field so the device is never a brick.
  function showStart(err) {
    $("hhStart").classList.add("show");
    var name = err && (err.name || err.constructor && err.constructor.name);
    var msg = $("hhStartMsg");
    var retryBtn = $("hhStartBtn");
    var blocked = false;

    if (name === "NotAllowedError" || name === "PermissionDeniedError" ||
        name === "SecurityError") {
      blocked = true;
      if (msg) msg.textContent =
        "Kamera blockiert — in den Browser-Einstellungen erlauben, oder unten Ticket-ID manuell eingeben.";
    } else if (name === "NotFoundError" || name === "DevicesNotFoundError" ||
               name === "OverconstrainedError") {
      blocked = true;
      if (msg) msg.textContent =
        "Keine Kamera gefunden — unten Ticket-ID manuell eingeben.";
    } else if (err) {
      if (msg) msg.textContent =
        "Kamera nicht verfügbar. Berechtigungen prüfen, dann zum Wiederholen tippen.";
    }

    // A hard deny / missing camera won't recover via the retry button (the
    // browser won't re-prompt), so hide it and lean on manual entry instead.
    if (retryBtn) retryBtn.style.display = blocked ? "none" : "";
    if (blocked) showManualFallback();
  }

  // Reveal the manual-entry block (normally hidden) so a camera-less device
  // can still validate tickets through the server.
  function showManualFallback() {
    var wrap = $("hhManual");
    if (wrap) wrap.classList.add("show");
  }

  function detectTorch() {
    try {
      var caps = scanner.getRunningTrackCapabilities();
      torchAvailable = !!(caps && caps.torch);
    } catch (e) { torchAvailable = false; }
    $("hhTorch").style.display = torchAvailable ? "inline-flex" : "none";
  }

  function toggleTorch() {
    if (!scanner || !torchAvailable) return;
    torchOn = !torchOn;
    scanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] })
      .then(function () { $("hhTorch").classList.toggle("is-on", torchOn); })
      .catch(function () { toast("Flashlight not supported"); torchOn = false; });
  }

  // ---- clock ------------------------------------------------------------
  function tick() {
    var d = new Date();
    $("hhClock").textContent = d.toLocaleString("de-DE", { dateStyle: "medium", timeStyle: "medium" });
  }

  // ---- boot -------------------------------------------------------------
  document.addEventListener("DOMContentLoaded", function () {
    tick(); setInterval(tick, 1000);
    $("hhDismiss").addEventListener("click", dismissResult);
    $("hhTorch").addEventListener("click", toggleTorch);
    $("hhStartBtn").addEventListener("click", start);

    // connectivity pill: seed from the browser, then track online/offline.
    // (No-ops on the inspector page, which has no pill.)
    netFromBrowser();
    window.addEventListener("online", netFromBrowser);
    window.addEventListener("offline", netFromBrowser);

    // manual ticket-id fallback (damaged QR / camera denied). Optional in the
    // DOM — guard so the inspector page (no manual block) is unaffected.
    var manualBtn = $("hhManualBtn");
    var manualInput = $("hhManualInput");
    if (manualBtn) manualBtn.addEventListener("click", submitManual);
    if (manualInput) {
      manualInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); submitManual(); }
      });
    }

    // auto-advance toggle (optional in the DOM, default off).
    var aa = $("hhAutoAdvance");
    if (aa) {
      autoAdvance = !!aa.checked;
      aa.addEventListener("change", function () { autoAdvance = !!aa.checked; });
    }

    // autostart; if the browser needs a gesture, the start overlay is shown
    start();
  });

  window.addEventListener("beforeunload", function () {
    if (scanner) { try { scanner.stop(); } catch (e) {} }
  });
})();
