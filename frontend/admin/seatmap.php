<?php
require_once '../config.php';

// Admin only.
if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
    header('Location: login.php');
    exit;
}
if (!empty($_SESSION['must_change_pw'])) {
    header('Location: change_password.php');
    exit;
}

$shows = getShows();
$locations = ($shows && !empty($shows['locations']) && is_array($shows['locations']))
    ? $shows['locations'] : [];
$preLoc = isset($_GET['loc']) ? (string)$_GET['loc'] : '';

$csrfToken = generateCsrfToken();

$pageTitle = 'Seat Map Editor';
$assetBase = '../';
$extraHead = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">'
    . '<script src="https://cdn.jsdelivr.net/npm/konva@9/konva.min.js"></script>'
    . '<style>
        #stageWrap { background:
            linear-gradient(var(--avo-border,#2a2a2a) 1px, transparent 1px) 0 0/20px 20px,
            linear-gradient(90deg, var(--avo-border,#2a2a2a) 1px, transparent 1px) 0 0/20px 20px;
            background-color: var(--avo-card,#111); }
        .tool-btn { width:100%; text-align:left; }
        .seat-legend-dot { width:.9rem; height:.9rem; border-radius:50%; display:inline-block; }
        /* Native colour input: basecoat .input squishes it, so style directly. */
        .sm-color { width:2.6rem; height:2.4rem; padding:2px; flex:0 0 auto; cursor:pointer;
            border:1px solid var(--avo-border,#2a2a2a); border-radius:.55rem;
            background:var(--avo-card,#111); }
        .sm-color::-webkit-color-swatch-wrapper { padding:0; }
        .sm-color::-webkit-color-swatch { border:none; border-radius:.4rem; }
        .sm-color::-moz-color-swatch { border:none; border-radius:.4rem; }
        #catList > div { min-width:0; }
        #catList .cat-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
      </style>';
?>
<!DOCTYPE html>
<html lang="en">
<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="min-h-screen flex flex-col">
    <div class="avo-topbar" aria-hidden="true"></div>
    <main class="flex-1 p-4">
        <div class="flex items-center justify-between mb-4 flex-wrap gap-3">
            <div>
                <div class="avo-kicker mb-1">// seat map editor</div>
                <h1 class="text-2xl">Room <span class="avo-hl">layout</span></h1>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <label class="text-sm avo-muted">Location</label>
                <select id="locationSelect" class="select">
                    <?php if (empty($locations)): ?>
                        <option value="">No locations — create one first</option>
                    <?php else: foreach ($locations as $locId => $loc): ?>
                        <option value="<?= htmlspecialchars($locId) ?>" <?= ($preLoc === (string)$locId) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($loc['name'] ?? $locId) ?>
                        </option>
                    <?php endforeach; endif; ?>
                </select>
                <button id="saveBtn" class="btn">Save map</button>
                <a href="index.php" class="btn-outline">← Admin</a>
            </div>
        </div>

        <div class="grid gap-4" style="grid-template-columns: 220px 1fr 260px;">
            <!-- LEFT: palette + generators -->
            <aside class="flex flex-col gap-3">
                <div class="card"><div class="card-content space-y-2">
                    <div class="avo-kicker">// add</div>
                    <button class="btn-outline tool-btn" data-add="seat">＋ Single seat</button>
                    <button class="btn-outline tool-btn" data-add="stage">＋ Stage</button>
                    <button class="btn-outline tool-btn" data-add="screen">＋ Screen</button>
                    <button class="btn-outline tool-btn" data-add="table">＋ Table (round)</button>
                    <button class="btn-outline tool-btn" data-add="table_rect">＋ Table (rect)</button>
                    <button class="btn-outline tool-btn" data-add="wall">＋ Wall</button>
                    <button class="btn-outline tool-btn" data-add="label">＋ Text label</button>
                </div></div>

                <div class="card"><div class="card-content space-y-2">
                    <div class="avo-kicker">// fast fill</div>
                    <label class="text-xs avo-muted">Row of seats</label>
                    <div class="flex gap-2">
                        <input id="rowCount" type="number" min="1" value="10" class="input" style="width:5rem" title="seats">
                        <button id="genRow" class="btn" style="flex:1">Add row</button>
                    </div>
                    <label class="text-xs avo-muted mt-2 block">Block (rows × cols)</label>
                    <div class="flex gap-2">
                        <input id="blkRows" type="number" min="1" value="5" class="input" style="width:4rem" title="rows">
                        <input id="blkCols" type="number" min="1" value="10" class="input" style="width:4rem" title="cols">
                        <button id="genBlock" class="btn" style="flex:1">Add</button>
                    </div>
                </div></div>

                <div class="card"><div class="card-content space-y-2">
                    <div class="avo-kicker">// selection</div>
                    <button id="dupBtn" class="btn-outline tool-btn">Duplicate <span class="avo-muted">(Ctrl+D)</span></button>
                    <button id="delBtn" class="btn-outline tool-btn">Delete <span class="avo-muted">(Del)</span></button>
                    <button id="autoNumBtn" class="btn tool-btn">Auto-number seats</button>
                    <p class="text-xs avo-muted">Auto-number applies to the selection, or to all seats if nothing is selected.</p>
                </div></div>
            </aside>

            <!-- CENTER: canvas -->
            <section>
                <div class="flex items-center gap-2 mb-2 text-xs avo-muted">
                    <button id="zoomIn" class="btn-outline" style="padding:.2rem .5rem">+</button>
                    <button id="zoomOut" class="btn-outline" style="padding:.2rem .5rem">−</button>
                    <button id="zoomReset" class="btn-outline" style="padding:.2rem .5rem">reset</button>
                    <button id="zoomFit" class="btn-outline" style="padding:.2rem .5rem">fit</button>
                    <span id="statusLine">0 seats</span>
                    <span class="ml-auto">Drag to move · drag empty space to box-select · Shift+click multi-select · Space+drag to pan</span>
                </div>
                <div id="stageWrap" class="rounded-lg overflow-hidden" style="border:1px solid var(--avo-border,#2a2a2a); height:70vh;"></div>
            </section>

            <!-- RIGHT: categories + inspector -->
            <aside class="flex flex-col gap-3">
                <div class="card"><div class="card-content space-y-2">
                    <div class="avo-kicker">// categories</div>
                    <div id="catList" class="space-y-1"></div>
                    <div class="space-y-2 pt-2" style="border-top:1px solid var(--avo-border,#2a2a2a)">
                        <input id="catName" type="text" class="input" placeholder="Category name" style="width:100%">
                        <div class="flex gap-2 items-center">
                            <input id="catColor" type="color" value="#22c55e" class="sm-color" title="Colour">
                            <input id="catPrice" type="number" min="0" step="0.01" class="input" placeholder="Price €" style="flex:1;min-width:0">
                            <button id="catAdd" class="btn" style="flex:0 0 auto">Add</button>
                        </div>
                    </div>
                    <button id="assignCat" class="btn-outline tool-btn mt-1" disabled>Assign to selected seats</button>
                </div></div>

                <div class="card"><div class="card-content space-y-2">
                    <div class="avo-kicker">// seat</div>
                    <div id="seatInspector" class="text-sm avo-muted">Select a single seat to edit its row &amp; number.</div>
                </div></div>
            </aside>
        </div>
    </main>

    <?php
    $orgName = '';
    $current_language = 'en';
    include __DIR__ . '/../partials/footer.php';
    ?>

    <script src="<?= $assetBase ?>admin/seatmap-editor.js?v=<?= @filemtime(__DIR__ . '/seatmap-editor.js') ?: time() ?>"></script>
</body>
</html>
