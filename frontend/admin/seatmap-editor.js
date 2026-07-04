/* QrGate seat-map editor (Konva). Model is the source of truth; Konva nodes
 * mirror it 1:1 keyed by element id. */
(function () {
  'use strict';

  const CSRF = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
  const GRID = 20;
  const SEAT_R = 12;
  const ROW_GAP = 26;              // y distance that starts a new row when auto-numbering
  const DEFAULT_SEAT_FILL = '#3b82f6';
  const FURNITURE = {
    stage:      { w: 220, h: 44, fill: '#6b7280', label: 'STAGE',  shape: 'rect' },
    screen:     { w: 260, h: 18, fill: '#0ea5e9', label: 'SCREEN', shape: 'rect' },
    table:      { w: 64,  h: 64, fill: '#8b5cf6', label: '',       shape: 'ellipse' },
    table_rect: { w: 90,  h: 56, fill: '#8b5cf6', label: '',       shape: 'rect' },
    wall:       { w: 140, h: 8,  fill: '#52525b', label: '',       shape: 'rect' },
    label:      { w: 120, h: 24, fill: 'transparent', label: 'Text', shape: 'text' },
  };

  let elements = [];              // model
  let categories = [];
  let activeCatId = null;         // category applied by "Assign to selected"
  let nodes = new Map();          // id -> Konva node
  const selected = new Set();     // selected ids
  let seatSeq = 0;                // for unique ids

  const wrap = document.getElementById('stageWrap');
  const stage = new Konva.Stage({
    container: wrap,
    width: wrap.clientWidth,
    height: wrap.clientHeight,
  });
  const layer = new Konva.Layer();
  stage.add(layer);
  const tr = new Konva.Transformer({ rotateEnabled: true, ignoreStroke: true });
  layer.add(tr);

  // Manual resize handle (bottom-right corner) for the single selected piece of
  // furniture. Konva's transformer anchors need colorKey hit-testing, which is
  // unreliable here, so we draw + hit-test our own handle geometrically.
  const HANDLE = 14;
  const handle = new Konva.Rect({
    width: HANDLE, height: HANDLE, fill: '#fff', stroke: '#3b82f6', strokeWidth: 2,
    cornerRadius: 2, visible: false, listening: false, name: 'resizeHandle',
  });
  layer.add(handle);

  // Keep the stage sized to its container.
  new ResizeObserver(() => {
    stage.width(wrap.clientWidth);
    stage.height(wrap.clientHeight);
  }).observe(wrap);

  // --------------------------------------------------------------- ids
  function uid(prefix) {
    seatSeq += 1;
    return prefix + '_' + Date.now().toString(36) + '_' + seatSeq;
  }

  // --------------------------------------------------------------- colors
  function catColor(catId) {
    const c = categories.find((x) => String(x.id) === String(catId));
    return c ? c.color : DEFAULT_SEAT_FILL;
  }

  // --------------------------------------------------------------- nodes
  function seatLabel(el) {
    if (el.row && el.number) return String(el.row) + String(el.number);
    if (el.row) return String(el.row);
    if (el.number) return String(el.number);
    return '';
  }

  function createNode(el) {
    let node;
    if (el.type === 'seat') {
      node = new Konva.Group({ x: el.x, y: el.y, draggable: false });
      node.add(new Konva.Circle({
        radius: SEAT_R, fill: catColor(el.category_id),
        stroke: '#0008', strokeWidth: 1, name: 'seatDot',
      }));
      node.add(new Konva.Text({
        text: seatLabel(el), fontSize: 10, fill: '#fff', name: 'seatText',
        width: SEAT_R * 2, height: SEAT_R * 2, align: 'center', verticalAlign: 'middle',
        offsetX: SEAT_R, offsetY: SEAT_R, listening: false,
      }));
    } else {
      const cfg = FURNITURE[el.type] || FURNITURE.stage;
      node = new Konva.Group({ x: el.x, y: el.y, draggable: false, rotation: el.rotation || 0 });
      if (cfg.shape === 'ellipse') {
        node.add(new Konva.Ellipse({
          radiusX: el.w / 2, radiusY: el.h / 2, x: el.w / 2, y: el.h / 2,
          fill: cfg.fill, stroke: '#0006', strokeWidth: 1, name: 'body',
        }));
      } else if (cfg.shape === 'text') {
        node.add(new Konva.Text({
          text: el.text || cfg.label, fontSize: 16, fill: 'var(--avo-fg,#eee)',
          name: 'body',
        }));
      } else {
        node.add(new Konva.Rect({
          width: el.w, height: el.h, fill: cfg.fill, cornerRadius: 4,
          stroke: '#0006', strokeWidth: 1, name: 'body',
        }));
      }
      if (cfg.label && cfg.shape !== 'text') {
        node.add(new Konva.Text({
          text: cfg.label, fontSize: 12, fill: '#fff', width: el.w, height: el.h,
          align: 'center', verticalAlign: 'middle', listening: false, name: 'furnLabel',
        }));
      }
    }
    node.id(el.id);
    bindNode(node, el);
    nodes.set(el.id, node);
    layer.add(node);
    return node;
  }

  // Interaction (select/drag/box-select) is handled at the stage level with
  // manual geometry hit-testing — NOT Konva's colorKey hit graph. Some browser
  // + GPU combos read canvas pixels back off-by-one (getImageData is not exact),
  // which silently breaks Konva's pixel-based hit detection. Geometry testing
  // against our model is exact and portable.
  function bindNode(node, el) { /* no per-node Konva handlers */ }

  function removeElement(id) {
    const n = nodes.get(id);
    if (n) { n.destroy(); nodes.delete(id); }
    elements = elements.filter((e) => e.id !== id);
    selected.delete(id);
  }

  // --------------------------------------------------------------- selection
  function clearSelection() { selected.clear(); }

  function refreshSelection() {
    const sel = [...selected].map((id) => nodes.get(id)).filter(Boolean);
    // Transformer is used purely as a selection outline here (its resize/rotate
    // anchors rely on colorKey hit-testing, which can be unreliable). Resizing
    // furniture is done via the size inputs / drag, not anchors.
    tr.nodes(sel);
    tr.resizeEnabled(false);
    tr.rotateEnabled(false);
    tr.enabledAnchors([]);
    const allSeat = selected.size > 0 && [...selected].every((id) => elById(id)?.type === 'seat');
    document.getElementById('assignCat').disabled = !allSeat;
    updateSeatInspector();
    updateHandle();
    updateStatus();
    layer.batchDraw();
  }

  function elById(id) { return elements.find((e) => e.id === id); }

  // ---- manual resize (furniture only) ----
  function applyBodySize(node, el) {
    const body = node.findOne('.body');
    if (body) {
      if (body.getClassName() === 'Rect') { body.width(el.w); body.height(el.h); }
      else if (body.getClassName() === 'Ellipse') {
        body.radiusX(el.w / 2); body.radiusY(el.h / 2); body.x(el.w / 2); body.y(el.h / 2);
      }
    }
    const fl = node.findOne('.furnLabel');
    if (fl) { fl.width(el.w); fl.height(el.h); }
  }
  function resizableSelected() {
    if (selected.size !== 1) return null;
    const el = elById([...selected][0]);
    if (!el || el.type === 'seat' || el.type === 'label') return null;
    return el;
  }
  function updateHandle() {
    const el = resizableSelected();
    if (!el) { handle.visible(false); return; }
    handle.position({ x: el.x + (el.w || 0) - HANDLE / 2, y: el.y + (el.h || 0) - HANDLE / 2 });
    handle.visible(true); handle.moveToTop();
  }

  // ---- manual geometry hit-testing (portable; no colorKey pixel reads) ----
  function elementBBox(el) {
    if (el.type === 'seat') {
      return { x: el.x - SEAT_R, y: el.y - SEAT_R, w: SEAT_R * 2, h: SEAT_R * 2 };
    }
    const cfg = FURNITURE[el.type] || FURNITURE.stage;
    return { x: el.x, y: el.y, w: el.w || cfg.w, h: el.h || cfg.h };
  }
  function elementAtPoint(pos) {
    // Topmost first (later elements render on top).
    for (let i = elements.length - 1; i >= 0; i--) {
      const el = elements[i];
      if (el.type === 'seat') {
        const dx = pos.x - el.x, dy = pos.y - el.y;
        if (dx * dx + dy * dy <= (SEAT_R + 2) * (SEAT_R + 2)) return el.id;
      } else {
        const b = elementBBox(el);
        if (pos.x >= b.x && pos.x <= b.x + b.w && pos.y >= b.y && pos.y <= b.y + b.h) return el.id;
      }
    }
    return null;
  }

  // Unified pointer interaction: click-select, drag-move, rubber-band select.
  let mode = null;                 // 'drag' | 'box' | 'resize' | null
  let dragStart = null;            // pointer pos at drag start
  let dragOrigins = null;          // Map id -> {x,y} at drag start
  let resizeEl = null;             // element being resized
  let selRect = null, selStart = null, spaceDown = false;

  stage.on('pointerdown', (e) => {
    if (spaceDown) return;         // space+drag pans the stage
    if (e && e.evt && e.evt.button === 2) return; // ignore right-click
    const pos = stage.getRelativePointerPosition();
    const shift = !!(e && e.evt && e.evt.shiftKey);

    // Resize handle takes priority when a single furniture item is selected.
    const rEl = resizableSelected();
    if (rEl) {
      const hx = rEl.x + (rEl.w || 0), hy = rEl.y + (rEl.h || 0);
      if (Math.abs(pos.x - hx) <= HANDLE && Math.abs(pos.y - hy) <= HANDLE) {
        mode = 'resize'; resizeEl = rEl; return;
      }
    }

    const hitId = elementAtPoint(pos);

    if (hitId) {
      if (shift) {
        if (selected.has(hitId)) selected.delete(hitId); else selected.add(hitId);
      } else if (!selected.has(hitId)) {
        clearSelection(); selected.add(hitId);
      }
      refreshSelection();
      // Begin dragging the whole current selection.
      mode = 'drag';
      dragStart = pos;
      dragOrigins = new Map();
      selected.forEach((id) => { const n = nodes.get(id); if (n) dragOrigins.set(id, { x: n.x(), y: n.y() }); });
      return;
    }

    // Empty space: start a rubber-band box selection.
    if (!shift) { clearSelection(); refreshSelection(); }
    mode = 'box';
    selStart = pos;
    selRect = new Konva.Rect({
      x: pos.x, y: pos.y, width: 0, height: 0,
      fill: 'rgba(59,130,246,0.15)', stroke: '#3b82f6', strokeWidth: 1, listening: false,
    });
    layer.add(selRect); selRect.moveToTop();
  });

  stage.on('pointermove', () => {
    if (mode === 'resize' && resizeEl) {
      const pos = stage.getRelativePointerPosition();
      resizeEl.w = Math.max(GRID, Math.round(pos.x - resizeEl.x));
      resizeEl.h = Math.max(GRID, Math.round(pos.y - resizeEl.y));
      const n = nodes.get(resizeEl.id); if (n) applyBodySize(n, resizeEl);
      updateHandle(); tr.forceUpdate(); layer.batchDraw();
      return;
    }
    if (mode === 'drag') {
      const pos = stage.getRelativePointerPosition();
      const dx = pos.x - dragStart.x, dy = pos.y - dragStart.y;
      dragOrigins.forEach((o, id) => {
        const n = nodes.get(id); const el = elById(id);
        if (!n || !el) return;
        n.position({ x: o.x + dx, y: o.y + dy });
        el.x = n.x(); el.y = n.y();
      });
      layer.batchDraw();
    } else if (mode === 'box' && selRect) {
      const p = stage.getRelativePointerPosition();
      selRect.setAttrs({
        x: Math.min(p.x, selStart.x), y: Math.min(p.y, selStart.y),
        width: Math.abs(p.x - selStart.x), height: Math.abs(p.y - selStart.y),
      });
      layer.batchDraw();
    }
  });

  stage.on('pointerup', () => {
    if (mode === 'resize' && resizeEl) {
      resizeEl.w = Math.max(GRID, Math.round(resizeEl.w / GRID) * GRID);
      resizeEl.h = Math.max(GRID, Math.round(resizeEl.h / GRID) * GRID);
      const n = nodes.get(resizeEl.id); if (n) applyBodySize(n, resizeEl);
      updateHandle(); tr.forceUpdate(); layer.batchDraw();
      mode = null; resizeEl = null; return;
    }
    if (mode === 'drag') {
      // Snap each moved element to the grid and commit.
      dragOrigins.forEach((o, id) => {
        const n = nodes.get(id); const el = elById(id);
        if (!n || !el) return;
        const sx = Math.round(n.x() / GRID) * GRID, sy = Math.round(n.y() / GRID) * GRID;
        n.position({ x: sx, y: sy }); el.x = sx; el.y = sy;
      });
      layer.batchDraw();
    } else if (mode === 'box' && selRect) {
      const bx = selRect.x(), by = selRect.y(), bw = selRect.width(), bh = selRect.height();
      selRect.destroy(); selRect = null;
      // Select every element whose bbox intersects the rubber band.
      elements.forEach((el) => {
        const b = elementBBox(el);
        if (bx < b.x + b.w && bx + bw > b.x && by < b.y + b.h && by + bh > b.y) selected.add(el.id);
      });
      refreshSelection();
    }
    mode = null; dragOrigins = null;
  });

  // --------------------------------------------------------------- adding
  function viewCenter() {
    // Center of the current viewport in stage-local (unscaled) coords, snapped.
    const p = { x: stage.width() / 2, y: stage.height() / 2 };
    const inv = stage.getAbsoluteTransform().copy().invert();
    const c = inv.point(p);
    return { x: Math.round(c.x / GRID) * GRID, y: Math.round(c.y / GRID) * GRID };
  }

  function addSeatAt(x, y, extra) {
    const el = Object.assign({ id: uid('seat'), type: 'seat', x, y, row: '', number: null, category_id: null }, extra || {});
    elements.push(el); createNode(el); return el;
  }

  function addFurniture(type) {
    const cfg = FURNITURE[type]; const c = viewCenter();
    const el = { id: uid(type), type, x: c.x, y: c.y, w: cfg.w, h: cfg.h, rotation: 0 };
    if (type === 'label') el.text = 'Text';
    elements.push(el); createNode(el);
    clearSelection(); selected.add(el.id); refreshSelection();
  }

  document.querySelectorAll('[data-add]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const type = btn.getAttribute('data-add');
      if (type === 'seat') {
        const c = viewCenter();
        clearSelection(); const el = addSeatAt(c.x, c.y); selected.add(el.id); refreshSelection();
      } else { addFurniture(type); }
    });
  });

  // Generators ----------------------------------------------------
  document.getElementById('genRow').addEventListener('click', () => {
    const n = Math.max(1, parseInt(document.getElementById('rowCount').value, 10) || 1);
    const c = viewCenter(); const gap = GRID * 2;
    clearSelection();
    for (let i = 0; i < n; i++) {
      const el = addSeatAt(c.x + i * gap, c.y); selected.add(el.id);
    }
    refreshSelection();
  });
  document.getElementById('genBlock').addEventListener('click', () => {
    const rows = Math.max(1, parseInt(document.getElementById('blkRows').value, 10) || 1);
    const cols = Math.max(1, parseInt(document.getElementById('blkCols').value, 10) || 1);
    const c = viewCenter(); const gx = GRID * 2, gy = GRID * 2;
    clearSelection();
    for (let r = 0; r < rows; r++) {
      for (let col = 0; col < cols; col++) {
        const el = addSeatAt(c.x + col * gx, c.y + r * gy); selected.add(el.id);
      }
    }
    refreshSelection();
  });

  // Duplicate / delete --------------------------------------------
  function duplicateSelection() {
    if (!selected.size) return;
    const clones = [];
    [...selected].forEach((id) => {
      const el = elById(id); if (!el) return;
      const copy = Object.assign({}, el, { id: uid(el.type), x: el.x + GRID * 2, y: el.y + GRID * 2 });
      elements.push(copy); createNode(copy); clones.push(copy.id);
    });
    clearSelection(); clones.forEach((id) => selected.add(id)); refreshSelection();
  }
  function deleteSelection() { [...selected].forEach(removeElement); refreshSelection(); }
  document.getElementById('dupBtn').addEventListener('click', duplicateSelection);
  document.getElementById('delBtn').addEventListener('click', deleteSelection);

  // --------------------------------------------------------------- categories
  function renderCats() {
    const list = document.getElementById('catList');
    list.innerHTML = '';
    if (!categories.length) { list.innerHTML = '<p class="text-xs avo-muted">No categories yet.</p>'; }
    if (categories.length && !categories.some((c) => String(c.id) === String(activeCatId))) {
      activeCatId = categories[categories.length - 1].id;
    }
    categories.forEach((c) => {
      const row = document.createElement('div');
      const isActive = String(c.id) === String(activeCatId);
      row.className = 'flex items-center gap-2 text-sm';
      row.style.cssText = 'cursor:pointer;padding:.15rem .3rem;border-radius:.35rem;' +
        (isActive ? 'outline:1px solid var(--avo-primary,#3b82f6);' : '');
      row.innerHTML =
        '<span class="seat-legend-dot" style="background:' + c.color + '"></span>' +
        '<span class="cat-name">' + escapeHtml(c.name) + '</span>' +
        '<span class="avo-muted">' + Number(c.price).toFixed(2) + ' €</span>' +
        '<button class="avo-link" data-delcat="' + c.id + '">✕</button>';
      row.addEventListener('click', (ev) => {
        if (ev.target.hasAttribute('data-delcat')) return;
        activeCatId = c.id; renderCats();
      });
      list.appendChild(row);
    });
    list.querySelectorAll('[data-delcat]').forEach((b) => {
      b.addEventListener('click', (ev) => {
        ev.stopPropagation();
        const id = b.getAttribute('data-delcat');
        categories = categories.filter((x) => String(x.id) !== String(id));
        if (String(activeCatId) === String(id)) activeCatId = null;
        elements.forEach((e) => { if (String(e.category_id) === String(id)) e.category_id = null; });
        recolorSeats(); renderCats();
      });
    });
  }
  document.getElementById('catAdd').addEventListener('click', () => {
    const name = document.getElementById('catName').value.trim();
    const color = document.getElementById('catColor').value;
    const price = parseFloat(document.getElementById('catPrice').value) || 0;
    if (!name) return;
    categories.push({ id: uid('cat'), name, color, price });
    document.getElementById('catName').value = '';
    document.getElementById('catPrice').value = '';
    renderCats();
  });
  document.getElementById('assignCat').addEventListener('click', () => {
    if (!categories.length) { toast('Add a category first'); return; }
    const cat = categories.find((c) => String(c.id) === String(activeCatId)) || categories[categories.length - 1];
    let n = 0;
    [...selected].forEach((id) => { const e = elById(id); if (e && e.type === 'seat') { e.category_id = cat.id; n++; } });
    recolorSeats();
    toast('Assigned "' + cat.name + '" to ' + n + ' seat(s)');
  });
  function recolorSeats() {
    elements.forEach((e) => {
      if (e.type !== 'seat') return;
      const dot = nodes.get(e.id)?.findOne('.seatDot');
      if (dot) dot.fill(catColor(e.category_id));
    });
    layer.batchDraw();
  }

  // --------------------------------------------------------------- seat inspector
  function updateSeatInspector() {
    const box = document.getElementById('seatInspector');
    const ids = [...selected];
    if (ids.length !== 1 || elById(ids[0])?.type !== 'seat') {
      box.innerHTML = 'Select a single seat to edit its row &amp; number.';
      return;
    }
    const el = elById(ids[0]);
    box.innerHTML =
      '<div class="space-y-2">' +
      '<label class="text-xs avo-muted">Row</label>' +
      '<input id="insRow" class="input" value="' + escapeAttr(el.row || '') + '">' +
      '<label class="text-xs avo-muted">Number</label>' +
      '<input id="insNum" class="input" type="number" value="' + (el.number ?? '') + '">' +
      '</div>';
    const apply = () => {
      el.row = document.getElementById('insRow').value.trim();
      const nv = document.getElementById('insNum').value;
      el.number = nv === '' ? null : parseInt(nv, 10);
      const t = nodes.get(el.id)?.findOne('.seatText'); if (t) t.text(seatLabel(el));
      layer.batchDraw();
    };
    box.querySelector('#insRow').addEventListener('input', apply);
    box.querySelector('#insNum').addEventListener('input', apply);
  }

  // --------------------------------------------------------------- auto-number
  document.getElementById('autoNumBtn').addEventListener('click', () => {
    let seats = elements.filter((e) => e.type === 'seat');
    const selSeats = seats.filter((e) => selected.has(e.id));
    if (selSeats.length) seats = selSeats;
    if (!seats.length) { toast('No seats to number'); return; }

    const startLetter = (prompt('Start row letter?', 'A') || 'A').toUpperCase();
    const startNum = parseInt(prompt('Start seat number?', '1') || '1', 10) || 1;

    // Cluster into rows by y proximity, top → bottom.
    const byY = [...seats].sort((a, b) => a.y - b.y);
    const rows = [];
    let cur = [];
    let lastY = null;
    byY.forEach((s) => {
      if (lastY === null || s.y - lastY <= ROW_GAP) { cur.push(s); }
      else { rows.push(cur); cur = [s]; }
      lastY = s.y;
    });
    if (cur.length) rows.push(cur);

    let letterIdx = startLetter.charCodeAt(0) - 65;
    rows.forEach((rowSeats) => {
      const letter = lettersFrom(letterIdx);
      rowSeats.sort((a, b) => a.x - b.x).forEach((s, i) => {
        s.row = letter;
        s.number = startNum + i;
        const t = nodes.get(s.id)?.findOne('.seatText'); if (t) t.text(seatLabel(s));
      });
      letterIdx += 1;
    });
    layer.batchDraw();
    toast('Numbered ' + seats.length + ' seats in ' + rows.length + ' row(s)');
  });
  function lettersFrom(idx) {
    // 0->A, 25->Z, 26->AA ...
    let s = '';
    idx += 1;
    while (idx > 0) { const m = (idx - 1) % 26; s = String.fromCharCode(65 + m) + s; idx = Math.floor((idx - 1) / 26); }
    return s;
  }

  // --------------------------------------------------------------- zoom / pan
  function setZoom(scale, center) {
    const old = stage.scaleX();
    const c = center || { x: stage.width() / 2, y: stage.height() / 2 };
    const mousePoint = { x: (c.x - stage.x()) / old, y: (c.y - stage.y()) / old };
    scale = Math.max(0.2, Math.min(3, scale));
    stage.scale({ x: scale, y: scale });
    stage.position({ x: c.x - mousePoint.x * scale, y: c.y - mousePoint.y * scale });
    stage.batchDraw();
  }
  document.getElementById('zoomIn').addEventListener('click', () => setZoom(stage.scaleX() * 1.2));
  document.getElementById('zoomOut').addEventListener('click', () => setZoom(stage.scaleX() / 1.2));
  document.getElementById('zoomReset').addEventListener('click', () => { stage.scale({ x: 1, y: 1 }); stage.position({ x: 0, y: 0 }); stage.batchDraw(); });
  document.getElementById('zoomFit').addEventListener('click', () => fitToContent());

  // Scale + center the view so the whole layout fits the canvas. Big halls open
  // filling the viewport instead of a tiny cluster in the corner.
  function contentBBox() {
    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    elements.forEach((el) => {
      const b = elementBBox(el);
      minX = Math.min(minX, b.x); minY = Math.min(minY, b.y);
      maxX = Math.max(maxX, b.x + b.w); maxY = Math.max(maxY, b.y + b.h);
    });
    if (!isFinite(minX)) return null;
    return { x: minX, y: minY, w: maxX - minX, h: maxY - minY };
  }
  function fitToContent() {
    const bb = contentBBox();
    if (!bb || bb.w <= 0 || bb.h <= 0) { stage.scale({ x: 1, y: 1 }); stage.position({ x: 0, y: 0 }); stage.batchDraw(); return; }
    const pad = 40;
    const sw = stage.width(), sh = stage.height();
    let scale = Math.min((sw - pad * 2) / bb.w, (sh - pad * 2) / bb.h);
    scale = Math.max(0.2, Math.min(3, scale));
    stage.scale({ x: scale, y: scale });
    stage.position({
      x: (sw - bb.w * scale) / 2 - bb.x * scale,
      y: (sh - bb.h * scale) / 2 - bb.y * scale,
    });
    stage.batchDraw();
  }
  stage.on('wheel', (e) => {
    e.evt.preventDefault();
    const dir = e.evt.deltaY > 0 ? 1 / 1.1 : 1.1;
    setZoom(stage.scaleX() * dir, stage.getPointerPosition());
  });
  window.addEventListener('keydown', (e) => {
    if (e.code === 'Space' && !isTyping(e)) { spaceDown = true; stage.draggable(true); wrap.style.cursor = 'grab'; }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd' && !isTyping(e)) { e.preventDefault(); duplicateSelection(); }
    if ((e.key === 'Delete' || e.key === 'Backspace') && !isTyping(e)) { e.preventDefault(); deleteSelection(); }
  });
  window.addEventListener('keyup', (e) => {
    if (e.code === 'Space') { spaceDown = false; stage.draggable(false); wrap.style.cursor = 'default'; }
  });
  function isTyping(e) { const t = e.target; return t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT'); }

  // --------------------------------------------------------------- load / save
  function rebuild(layout, cats) {
    nodes.forEach((n) => n.destroy()); nodes.clear();
    elements = []; selected.clear();
    categories = Array.isArray(cats) ? cats : [];
    const els = (layout && Array.isArray(layout.elements)) ? layout.elements : [];
    els.forEach((el) => { elements.push(el); createNode(el); });
    renderCats(); recolorSeats(); refreshSelection();
    if (elements.length) fitToContent();
    layer.batchDraw();
  }

  async function loadLocation(locId) {
    if (!locId) { rebuild({ elements: [] }, []); return; }
    try {
      const r = await fetch('seatmap-proxy.php?location=' + encodeURIComponent(locId));
      const j = await r.json();
      if (j && j.status === 'success') rebuild(j.layout, j.categories);
      else rebuild({ elements: [] }, []);
    } catch (err) { toast('Load failed'); rebuild({ elements: [] }, []); }
  }

  async function save() {
    const locId = document.getElementById('locationSelect').value;
    if (!locId) { toast('No location selected'); return; }
    const payload = { location: locId, layout: { elements }, categories };
    try {
      const r = await fetch('seatmap-proxy.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(payload),
      });
      const j = await r.json();
      if (j && j.status === 'success') toast('Saved — ' + (j.capacity ?? 0) + ' seats');
      else toast('Save failed: ' + (j.message || j.error || 'unknown'));
    } catch (err) { toast('Save failed'); }
  }
  document.getElementById('saveBtn').addEventListener('click', save);
  document.getElementById('locationSelect').addEventListener('change', (e) => loadLocation(e.target.value));

  // --------------------------------------------------------------- misc
  function updateStatus() {
    const seatCount = elements.filter((e) => e.type === 'seat').length;
    document.getElementById('statusLine').textContent =
      seatCount + ' seats · ' + selected.size + ' selected';
  }
  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); }
  function escapeAttr(s) { return escapeHtml(s); }
  let toastTimer = null;
  function toast(msg) {
    let el = document.getElementById('smToast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'smToast';
      el.style.cssText = 'position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);background:var(--avo-card,#111);border:1px solid var(--avo-border,#333);color:var(--avo-fg,#eee);padding:.6rem 1rem;border-radius:.5rem;z-index:60;box-shadow:0 6px 20px #0007';
      document.body.appendChild(el);
    }
    el.textContent = msg; el.style.opacity = '1';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.style.opacity = '0'; }, 2200);
  }

  // Boot: load the first location.
  const initLoc = document.getElementById('locationSelect').value;
  loadLocation(initLoc);
})();
