<?php
require_once "../config.php";

if (!isset($_SESSION["admin"]) || $_SESSION["admin"] !== true) {
    header("Location: login.php");
    exit();
}
if (!empty($_SESSION["must_change_pw"])) {
    header("Location: change_password.php");
    exit();
}

$shows = getShows();
$stats = [];
if ($shows) {
    $totalTickets = 0;
    $totalAvailable = 0;
    $totalSold = 0;
    $totalIncome = 0;
    $soldByDate = [];
    $availableByDate = [];
    foreach ($shows["dates"] as $dateId => $dateData) {
        $totalTickets += $dateData["tickets"];
        $totalAvailable += $dateData["tickets_available"];
        $sold = $dateData["tickets"] - $dateData["tickets_available"];
        $totalSold += $sold;
        $totalIncome += $sold * floatval($dateData["price"]);
        $soldByDate[$dateData["date"]] = $sold;
        $availableByDate[$dateData["date"]] = $dateData["tickets_available"];
    }
    $stats = [
        "totalTickets" => $totalTickets,
        "totalAvailable" => $totalAvailable,
        "totalSold" => $totalSold,
        "totalIncome" => $totalIncome,
        "soldByDate" => $soldByDate,
        "availableByDate" => $availableByDate,
    ];
}


$pageTitle = 'QrGate Admin Panel';
$assetBase = '../';
$csrfToken = generateCsrfToken();
$csrfMeta = '<meta name="csrf-token" content="' . htmlspecialchars($csrfToken, ENT_QUOTES) . '">';
$extraHead = <<<HTML
    {$csrfMeta}
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
HTML;
?>
<!DOCTYPE html>
<html lang="en">

<?php include __DIR__ . '/../partials/head.php'; ?>
<body class="page">

    <style>
        h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: inline-flex;
            align-items: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .sidebar span {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        #sidebar-title {
            font-size: initial;
            font-weight: initial;
            margin-bottom: initial;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 10%;
        }

        header h2 {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }


        canvas {
            max-height: 300px;
        }

        main {
            margin-left: 260px;
            padding: 1.5rem;
            margin-right: auto;
            margin-top: 0;
        }

        .pie-charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .chartjs-render-monitor {
            max-height: 300px;
        }

        @media (max-width: 768px) {
            main {
                margin-left: 0 !important;
                padding: 1rem;
            }

            aside.sidebar {
                position: fixed;
                left: -260px;
                top: 0;
                height: 100vh;
                z-index: 1000;
                transition: left 0.3s ease;
            }

            aside.sidebar.active {
                left: 0;
            }

            body.page {
                overflow-x: hidden;
            }

            .stats-grid,
            .pie-charts-container,
            .days-table-container,
            .form,
            table {
                grid-template-columns: 1fr !important;
                width: 100% !important;
            }

            .card {
                width: 100% !important;
            }

            canvas {
                max-width: 100% !important;
                height: auto !important;
            }

            h1 {
                font-size: 1.5rem !important;
            }

            h2 {
                font-size: 1.25rem !important;
            }

            button[onclick*="basecoat:sidebar"] {
                display: block !important;
                margin-bottom: 1rem;
                width: fit-content;
            }

            @media (max-width: 768px) {

                #incomeChart,
                #totalSalesChart {
                    height: 220px !important;
                    width: 100% !important;
                    max-width: 100%;
                }

                #statistics .card>section {
                    height: 220px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    padding: 0;
                }

                .days-table-container {
                    overflow-x: auto;
                    -webkit-overflow-scrolling: touch;
                    margin: 0;
                    padding: 0;
                }

                .days-table-container table {
                    min-width: 600px;
                    width: auto;
                }

                #statistics .card,
                #days .card {
                    padding: 1rem;
                }
            }

            .days-table-container {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -1.5rem;
                padding: 0 1.5rem;
            }

            /* Image Management Styles */
            #bannerPreview img,
            #logoPreview img,
            #wallpaperPreview img {
                max-width: 100%;
                max-height: 300px;
                object-fit: contain;
                display: block;
                margin: 0 auto;
            }

            .image-preview-container {
                border: 1px solid var(--avo-border);
                border-radius: var(--avo-radius-md);
                padding: 1rem;
                min-height: 200px;
                display: flex;
                align-items: center;
                justify-content: center;
                background-color: var(--avo-surface);
            }
        }
    </style>
    <div class="avo-topbar" aria-hidden="true"></div>
    <div id="toaster" class="toaster" data-align="center"></div>

    <aside class="sidebar" data-side="left" aria-hidden="false">
        <nav aria-label="Navigation Menu">

            <section class="scrollbar">
                <div role="group" aria-labelledby="group-label-content-1">
                    <ul>
                        <li>
                            <h1 id="sidebar-title"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                    viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"
                                    class="lucide lucide-scan-qr-code-icon lucide-scan-qr-code">
                                    <path d="M17 12v4a1 1 0 0 1-1 1h-4" />
                                    <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                                    <path d="M17 8V7" />
                                    <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                                    <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                                    <path d="M7 17h.01" />
                                    <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                                    <rect x="7" y="7" width="5" height="5" rx="1" />
                                </svg>QRGate Admin Panel</h1>
                        </li>
                    </ul>
                </div>
                <div role="group" aria-labelledby="group-label-content-2">

                    <ul>
                        <li><a href="#" data-section="dashboard"><span><svg xmlns="http://www.w3.org/2000/svg"
                                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-layout-dashboard-icon lucide-layout-dashboard">
                                        <rect width="7" height="9" x="3" y="3" rx="1" />
                                        <rect width="7" height="5" x="14" y="3" rx="1" />
                                        <rect width="7" height="9" x="14" y="12" rx="1" />
                                        <rect width="7" height="5" x="3" y="16" rx="1" />
                                    </svg> Dashboard</span></a></li>
                        <li><a href="#" data-section="statistics"><span><svg xmlns="http://www.w3.org/2000/svg"
                                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-chart-column-big-icon lucide-chart-column-big">
                                        <path d="M3 3v16a2 2 0 0 0 2 2h16" />
                                        <rect x="15" y="5" width="4" height="12" rx="1" />
                                        <rect x="7" y="8" width="4" height="9" rx="1" />
                                    </svg> Statistics</span></a></li>
                        <li><a href="#" data-section="event"><span><svg xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-file-pen-icon lucide-file-pen">
                                        <path
                                            d="M12.659 22H18a2 2 0 0 0 2-2V8a2.4 2.4 0 0 0-.706-1.706l-3.588-3.588A2.4 2.4 0 0 0 14 2H6a2 2 0 0 0-2 2v9.34" />
                                        <path d="M14 2v5a1 1 0 0 0 1 1h5" />
                                        <path
                                            d="M10.378 12.622a1 1 0 0 1 3 3.003L8.36 20.637a2 2 0 0 1-.854.506l-2.867.837a.5.5 0 0 1-.62-.62l.836-2.869a2 2 0 0 1 .506-.853z" />
                                    </svg> Manage Event</span></a>
                        </li>
                        <li><a href="#" data-section="days"><span><svg xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-calendar-days-icon lucide-calendar-days">
                                        <path d="M8 2v4" />
                                        <path d="M16 2v4" />
                                        <rect width="18" height="18" x="3" y="4" rx="2" />
                                        <path d="M3 10h18" />
                                        <path d="M8 14h.01" />
                                        <path d="M12 14h.01" />
                                        <path d="M16 14h.01" />
                                        <path d="M8 18h.01" />
                                        <path d="M12 18h.01" />
                                        <path d="M16 18h.01" />
                                    </svg> Manage Dates</span></a>
                        </li>
                        <li><a href="#" data-section="images"><span><svg xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-image-icon lucide-image">
                                        <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                                        <circle cx="9" cy="9" r="2" />
                                        <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                                    </svg> Image Management</span></a>
                        </li>
                        <li><a href="#" data-section="screens"><span><svg xmlns="http://www.w3.org/2000/svg" width="24"
                                        height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-monitor-icon lucide-monitor">
                                        <rect width="20" height="14" x="2" y="3" rx="2" />
                                        <line x1="8" x2="16" y1="21" y2="21" />
                                        <line x1="12" x2="12" y1="17" y2="21" />
                                    </svg> Screen Software</span></a>
                        </li>
                        <li><a href="#" data-section="payment-settings"><span><svg xmlns="http://www.w3.org/2000/svg"
                                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-credit-card-icon lucide-credit-card">
                                        <rect width="20" height="14" x="2" y="5" rx="2" />
                                        <line x1="2" x2="22" y1="10" y2="10" />
                                    </svg> Zahlungseinstellungen</span></a>
                        </li>
                        <li><a href="#" data-section="accounts"><span><svg xmlns="http://www.w3.org/2000/svg"
                                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-users-icon lucide-users">
                                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                        <circle cx="9" cy="7" r="4" />
                                        <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                                    </svg> Benutzerkonten</span></a>
                        </li>
                        <li><a href="#" data-section="danger"><span><svg xmlns="http://www.w3.org/2000/svg"
                                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-shield-alert">
                                        <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z" />
                                        <path d="M12 8v4" />
                                        <path d="M12 16h.01" />
                                    </svg> Wartung &amp; Daten</span></a>
                        </li>

                    </ul>
                </div>
                <div role="group" aria-labelledby="group-label-content-3">
                    <ul>
                        <li><a href="apps.php" data-section="switch-app"><span><svg xmlns="http://www.w3.org/2000/svg"
                                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-grid">
                                        <rect width="7" height="7" x="3" y="3" rx="1" />
                                        <rect width="7" height="7" x="14" y="3" rx="1" />
                                        <rect width="7" height="7" x="14" y="14" rx="1" />
                                        <rect width="7" height="7" x="3" y="14" rx="1" />
                                    </svg> App wechseln</span></a></li>
                        <li><a href="logout.php" data-section="logout"><span><svg xmlns="http://www.w3.org/2000/svg"
                                        width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                        class="lucide lucide-log-out-icon lucide-log-out">
                                        <path d="m16 17 5-5-5-5" />
                                        <path d="M21 12H9" />
                                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                                    </svg> Logout</span></a></li>
                    </ul>
                </div>
            </section>
        </nav>
    </aside>
    <dialog id="confirmation-dialog" class="dialog" aria-labelledby="confirmation-dialog-title"
        aria-describedby="confirmation-dialog-description">
        <div>
            <header>
                <h2 id="confirmation-dialog-title">Confirm Action</h2>
                <p id="confirmation-dialog-description"></p>
            </header>
            <footer>
                <button class="btn-outline"
                    onclick="document.getElementById('confirmation-dialog').close()">Cancel</button>
                <button class="btn-destructive" id="confirmation-dialog-confirm">Confirm</button>
            </footer>
        </div>
    </dialog>

    <main>

        <button type="button" class="btn-outline"
            onclick="document.dispatchEvent(new CustomEvent('basecoat:sidebar'));"><svg
                xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                class="lucide lucide-panel-right-icon lucide-panel-right">
                <rect width="18" height="18" x="3" y="3" rx="2" />
                <path d="M15 3v18" />
            </svg></button>
        <!-- Dashboard -->
        <div id="dashboard" class="active" style="display: none">
            <div class="avo-kicker mb-1">// control room</div>
            <h1>Dash<span class="avo-hl">board</span></h1>
            <div class="stats-grid">
                <div class="card">
                    <header>
                        <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-ticket-icon lucide-ticket">
                                <path
                                    d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                                <path d="M13 5v2" />
                                <path d="M13 17v2" />
                                <path d="M13 11v2" />
                            </svg> Total tickets</h2>
                        <p>The total number of tickets available for all days of this event. </p>
                    </header>
                    <section><?php echo $stats["totalTickets"] ??
                        0; ?> Tickets</section>
                </div>
                <div class="card">
                    <header>
                        <h2 class="flex justify-between items-center">
                            <span class="flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="lucide lucide-ticket-check-icon lucide-ticket-check">
                                    <path
                                        d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                                    <path d="m9 12 2 2 4-4" />
                                </svg>
                                Sold Tickets
                            </span>
                            <span class="badge-outline flex items-center gap-1">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="lucide lucide-percent-icon lucide-percent">
                                    <line x1="19" x2="5" y1="5" y2="19" />
                                    <circle cx="6.5" cy="6.5" r="2.5" />
                                    <circle cx="17.5" cy="17.5" r="2.5" />
                                </svg>
                                <?php
                                $percentage = 0;
                                if ($stats["totalTickets"] > 0) {
                                    $percentage = ($stats["totalSold"] / $stats["totalTickets"]) * 100;
                                }
                                echo number_format($percentage, 2); ?>
                            </span>
                        </h2>
                        <p>The number of tickets remaining for the entire event. This also includes reserved tickets.
                        </p>
                    </header>
                    <section><?php echo $stats["totalSold"] ??
                        0; ?> Tickets</section>
                </div>
                <div class="card">
                    <header>
                        <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-ticket-minus-icon lucide-ticket-minus">
                                <path
                                    d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z" />
                                <path d="M9 12h6" />
                            </svg>Tickets Left</h2>
                        <p>The number of tickets that are still available for the entire event and are for sale.</p>
                    </header>
                    <section><?php echo $stats["totalAvailable"] ??
                        0; ?> Tickets</section>
                </div>
                <div class="card">
                    <header>
                        <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-banknote-icon lucide-banknote">
                                <rect width="20" height="12" x="2" y="6" rx="2" />
                                <circle cx="12" cy="12" r="2" />
                                <path d="M6 12h.01M18 12h.01" />
                            </svg> Estimated income</h2>
                        <p>Estimated income in the best-case scenario. This means that all tickets booked were paid for
                            and used.</p>
                    </header>
                    <section><?php echo number_format(
                        $stats["totalIncome"] ?? 0,
                        2
                    ); ?> €</section>
                </div>
            </div>

            <div class="card" id="checkinCard">
                <header>
                    <h2 class="flex justify-between items-center">
                        <span class="flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-scan-line-icon">
                                <path d="M3 7V5a2 2 0 0 1 2-2h2" />
                                <path d="M17 3h2a2 2 0 0 1 2 2v2" />
                                <path d="M21 17v2a2 2 0 0 1-2 2h-2" />
                                <path d="M7 21H5a2 2 0 0 1-2-2v-2" />
                                <path d="M7 12h10" />
                            </svg>
                            Live Einlass
                        </span>
                        <span id="checkinLiveDot" class="badge-outline flex items-center gap-1" title="Aktualisiert automatisch">
                            <span style="width:8px;height:8px;border-radius:999px;background:var(--avo-success);display:inline-block;"></span>
                            Live
                        </span>
                    </h2>
                    <p>Wie viele der verkauften Tickets bereits am Einlass gescannt wurden. Aktualisiert automatisch.</p>
                </header>
                <section>
                    <div id="checkinDateLabel" style="font-size:.85rem;color:var(--avo-text-muted);margin-bottom:.4rem;">Lade…</div>
                    <div style="display:flex;align-items:baseline;gap:.5rem;flex-wrap:wrap;">
                        <span id="checkinBig" style="font-family:var(--avo-font-display);font-size:2.4rem;font-weight:800;line-height:1;">–</span>
                        <span id="checkinOf" style="color:var(--avo-text-muted);font-weight:600;">/ – eingecheckt</span>
                        <span id="checkinPct" class="badge-outline" style="margin-left:auto;">–%</span>
                    </div>
                    <div style="height:10px;border-radius:999px;background:color-mix(in oklab,var(--avo-border) 70%,transparent);margin:.75rem 0 .25rem;overflow:hidden;">
                        <div id="checkinBar" style="height:100%;width:0%;background:var(--avo-primary);border-radius:999px;transition:width .4s ease;"></div>
                    </div>
                    <div style="display:flex;gap:1.5rem;font-size:.85rem;color:var(--avo-text-muted);margin-top:.5rem;">
                        <span><strong id="checkinPending" style="color:var(--avo-text);">–</strong> ausstehend</span>
                        <span><strong id="checkinSold" style="color:var(--avo-text);">–</strong> verkauft</span>
                    </div>

                    <h4 style="margin:1.25rem 0 .5rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--avo-text-muted);">Letzte Scans</h4>
                    <div id="checkinRecent" style="display:flex;flex-direction:column;gap:.35rem;">
                        <div style="color:var(--avo-text-muted);font-size:.85rem;">–</div>
                    </div>
                </section>
            </div>

            <div class="card">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-activity-icon lucide-activity">
                            <path
                                d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2" />
                        </svg> Event Overview</h2>
                </header>
                <section>
                    <?php if ($shows): ?>
                        <p><strong>Organization:</strong> <?php echo htmlspecialchars(
                            $shows["orga_name"] ?? ''
                        ); ?></p>
                        <p><strong>Event Title:</strong> <?php echo htmlspecialchars(
                            $shows["title"] ?? ''
                        ); ?></p>
                        <p><strong>Subtitle:</strong> <?php echo htmlspecialchars(
                            $shows["subtitle"] ?? ''
                        ); ?></p>
                        <p><strong>Active Dates:</strong> <?php echo count(
                            $shows["dates"]
                        ); ?></p>
                        <p><strong>Store Status:</strong>
                            <span style="color: <?php echo $shows[
                                "store_lock"
                            ]
                                ? "var(--avo-error)"
                                : "var(--avo-success)"; ?>">
                                <?php echo $shows["store_lock"]
                                    ? "LOCKED"
                                    : "OPEN"; ?>
                            </span>
                        </p>
                    <?php else: ?>
                        <p style="color: var(--avo-error);">Error loading event data</p>
                    <?php endif; ?>
                </section>
            </div>

        </div>

        <!-- Statistics -->
        <div id="statistics" style="display: none;">
            <h1><svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-chart-column-big-icon lucide-chart-column-big" style="margin-right: 10px;">
                    <path d="M3 3v16a2 2 0 0 0 2 2h16" />
                    <rect x="15" y="5" width="4" height="12" rx="1" />
                    <rect x="7" y="8" width="4" height="9" rx="1" />
                </svg> Statistics</h1>
            <div class="pie-charts-container">
                <div class="card">
                    <header>
                        <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-badge-euro-icon lucide-badge-euro">
                                <path
                                    d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z" />
                                <path d="M7 12h5" />
                                <path d="M15 9.4a4 4 0 1 0 0 5.2" />
                            </svg> Ticket Sales Overview</h2>
                    </header>
                    <section><canvas id="salesChart"></canvas></section>
                </div>

                <div class="card">
                    <header>
                        <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" class="lucide lucide-tickets-icon lucide-tickets">
                                <path d="m3.173 8.18 11-5a2 2 0 0 1 2.647.993L18.56 8" />
                                <path d="M6 10V8" />
                                <path d="M6 14v1" />
                                <path d="M6 19v2" />
                                <rect x="2" y="8" width="20" height="13" rx="2" />
                            </svg> Tickets Available Per Day</h2>
                    </header>
                    <section><canvas id="availabilityChart"></canvas></section>
                </div>
            </div>
            <div class="card">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-badge-euro-icon lucide-badge-euro">
                            <path
                                d="M3.85 8.62a4 4 0 0 1 4.78-4.77 4 4 0 0 1 6.74 0 4 4 0 0 1 4.78 4.78 4 4 0 0 1 0 6.74 4 4 0 0 1-4.77 4.78 4 4 0 0 1-6.75 0 4 4 0 0 1-4.78-4.77 4 4 0 0 1 0-6.76Z" />
                            <path d="M7 12h5" />
                            <path d="M15 9.4a4 4 0 1 0 0 5.2" />
                        </svg> Income Over Time</h2>
                </header>
                <section><canvas id="incomeChart"></canvas></section>
            </div>
            <br>
            <div class="card">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-tickets-icon lucide-tickets">
                            <path d="m3.173 8.18 11-5a2 2 0 0 1 2.647.993L18.56 8" />
                            <path d="M6 10V8" />
                            <path d="M6 14v1" />
                            <path d="M6 19v2" />
                            <rect x="2" y="8" width="20" height="13" rx="2" />
                        </svg> Total Tickets Sold Over Time</h2>
                </header>
                <section><canvas id="totalSalesChart"></canvas></section>
            </div>
            <br>
            <div class="card">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-chart-column-big-icon lucide-chart-column-big">
                            <path d="M3 3v16a2 2 0 0 0 2 2h16" />
                            <rect x="15" y="5" width="4" height="12" rx="1" />
                            <rect x="7" y="8" width="4" height="9" rx="1" />
                        </svg> Detailed Statistics</h2>
                </header>
                <section>
                    <div class="days-table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Tickets</th>
                                    <th>Sold</th>
                                    <th>Available</th>
                                    <th>Price</th>
                                    <th>Income</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($shows):
                                    foreach (
                                        $shows["dates"]
                                        as $dateId => $dateData
                                    ):

                                        $sold =
                                            $dateData["tickets"] -
                                            $dateData["tickets_available"];
                                        $income =
                                            $sold *
                                            floatval($dateData["price"]);
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(
                                                $dateData["date"]
                                            ); ?></td>
                                            <td><?php echo $dateData[
                                                "tickets"
                                            ]; ?></td>
                                            <td><?php echo $sold; ?></td>
                                            <td><?php echo $dateData[
                                                "tickets_available"
                                            ]; ?></td>
                                            <td>€<?php echo $dateData[
                                                "price"
                                            ]; ?></td>
                                            <td>€<?php echo number_format(
                                                $income,
                                                2
                                            ); ?></td>
                                        </tr>
                                        <?php
                                    endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <!-- Manage Event -->
        <div id="event" style="display: none">
            <div class="card">
                <header>
                    <h1>Manage Current Event</h1>
                </header>
                <section>
                    <form id="eventForm" class="form grid gap-6">
                        <div class="grid gap-2">
                            <label class="label" for="orgaName">Organization Name</label>
                            <input type="text" id="orgaName"
                                value="<?php echo $shows ? htmlspecialchars($shows['orga_name'] ?? '') : ''; ?>">
                            <p class="text-muted-foreground text-sm">This is your public display name.</p>
                        </div>
                        <div class="grid gap-2">
                            <label class="label" for="eventTitle">Event Title</label>
                            <input type="text" id="eventTitle"
                                value="<?php echo $shows ? htmlspecialchars($shows['title'] ?? '') : ''; ?>">
                        </div>
                        <div class="grid gap-2">
                            <label class="label" for="eventSubtitle">Subtitle</label>
                            <input type="text" id="eventSubtitle"
                                value="<?php echo $shows ? htmlspecialchars($shows['subtitle'] ?? '') : ''; ?>">
                        </div>
                        <div class="grid gap-2">
                            <label class="label" for="bannerUrl">Banner URL</label>
                            <input type="text" id="bannerUrl"
                                value="<?php echo $shows ? htmlspecialchars($shows['banner'] ?? '') : ''; ?>">
                        </div>
                        <div class="grid gap-2">
                            <label class="label" for="contactEmail">Kontakt-E-Mail</label>
                            <input type="email" id="contactEmail" placeholder="kontakt@veranstalter.de"
                                value="<?php echo $shows ? htmlspecialchars($shows['contact_email'] ?? '') : ''; ?>">
                            <p class="text-muted-foreground text-sm">Öffentliche Kontaktadresse für Kunden (Storno &amp; Rückfragen). Wird im Ticketshop angezeigt.</p>
                        </div>
                        <div class="grid gap-2">
                            <label class="label" for="appDomain">App-Domain</label>
                            <input type="text" id="appDomain" placeholder="https://tickets.veranstalter.de"
                                value="<?php echo $shows ? htmlspecialchars($shows['app_domain'] ?? '') : ''; ?>">
                            <p class="text-muted-foreground text-sm">Öffentliche Adresse deines Ticketshops (Frontend). Wird für Links in E-Mails genutzt, z.&nbsp;B. den Stornierungs-Link. Ohne <code>https://</code> wird es automatisch ergänzt.</p>
                        </div>
                        <div class="flex items-start gap-3">
                            <input type="checkbox" id="storeLock" <?php echo $shows && $shows['store_lock'] ? 'checked' : ''; ?>>
                            <div class="flex flex-col gap-1">
                                <label class="leading-snug" for="storeLock">Store Lock</label>
                                <p class="text-muted-foreground text-sm">This will lock the store
                                    frontend and prevent new purchases.</p>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <label class="label" for="paymentMethods">Payment Methods</label>
                            <select id="paymentMethods" class="select">
                                <option value="both" <?php echo ($shows && isset($shows['payment_methods']) && $shows['payment_methods'] === 'both') ? 'selected' : ''; ?>>
                                    Both (Cash & Online)</option>
                                <option value="cash" <?php echo ($shows && isset($shows['payment_methods']) && $shows['payment_methods'] === 'cash') ? 'selected' : ''; ?>>
                                    Cash only</option>
                                <option value="online" <?php echo ($shows && isset($shows['payment_methods']) && $shows['payment_methods'] === 'online') ? 'selected' : ''; ?>>
                                    Online only</option>
                            </select>
                            <p class="text-muted-foreground text-sm">Select which payment methods should be available in
                                the store.</p>
                        </div>

                        <div style="margin-top: 1.5rem;">
                            <button class="btn-outline" type="submit">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" class="lucide lucide-save-icon lucide-save">
                                    <path
                                        d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
                                    <path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7" />
                                    <path d="M7 3v4a1 1 0 0 0 1 1h7" />
                                </svg>
                                Save changes
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </div>

        <!-- Manage Days -->
        <div id="days" style="display: none;">
            <h1>Manage Days</h1>

            <!-- Locations -->
            <div class="card">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-map-pin-icon lucide-map-pin">
                            <path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0" />
                            <circle cx="12" cy="10" r="3" />
                        </svg> Locations</h2>
                </header>
                <section>
                    <p class="text-muted-foreground text-sm" style="margin-bottom: 1rem;">
                        Define the venues where your events take place. Each day can be assigned to a
                        location, which is then printed on the ticket (email &amp; PDF).</p>
                    <form id="addLocationForm" class="form grid gap-6" style="margin-bottom: 1.5rem;">
                        <div class="grid gap-3"><label class="label" for="newLocationName">Name</label><input
                                type="text" id="newLocationName" class="input" placeholder="Main Hall"></div>
                        <div class="grid gap-3"><label class="label" for="newLocationAddress">Address</label><input
                                type="text" id="newLocationAddress" class="input"
                                placeholder="Example Street 1, 12345 City"></div>
                        <div>
                            <button type="submit" class="btn-outline"><svg xmlns="http://www.w3.org/2000/svg" width="24"
                                    height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"
                                    class="lucide lucide-circle-plus-icon lucide-circle-plus">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M8 12h8" />
                                    <path d="M12 8v8" />
                                </svg> Add Location</button>
                        </div>
                    </form>
                    <div class="days-table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Address</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="locationsTableBody">
                                <?php if ($shows && !empty($shows["locations"]) && is_array($shows["locations"])):
                                    foreach ($shows["locations"] as $locId => $loc): ?>
                                        <tr data-location-id="<?php echo htmlspecialchars($locId); ?>">
                                            <td><input type="text" class="input location-name"
                                                    value="<?php echo htmlspecialchars($loc["name"] ?? ""); ?>"></td>
                                            <td><input type="text" class="input location-address"
                                                    value="<?php echo htmlspecialchars($loc["address"] ?? ""); ?>"></td>
                                            <td>
                                                <a class="btn-icon-outline" href="seatmap.php?loc=<?php echo urlencode($locId); ?>"
                                                    title="Edit this location's seat map" style="display:inline-flex;vertical-align:middle;"><svg
                                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"
                                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                        stroke-linejoin="round" class="lucide lucide-layout-grid">
                                                        <rect width="7" height="7" x="3" y="3" rx="1" /><rect width="7" height="7" x="14" y="3" rx="1" />
                                                        <rect width="7" height="7" x="14" y="14" rx="1" /><rect width="7" height="7" x="3" y="14" rx="1" />
                                                    </svg></a>
                                                <button class="btn-icon-outline" type="button" action-type="update-location"
                                                    data-location-id="<?php echo htmlspecialchars($locId); ?>"><svg
                                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                        stroke-linecap="round" stroke-linejoin="round"
                                                        class="lucide lucide-save-icon lucide-save">
                                                        <path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
                                                        <path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7" />
                                                        <path d="M7 3v4a1 1 0 0 0 1 1h7" />
                                                    </svg></button>
                                                <button class="btn-icon-destructive" type="button" action-type="delete-location"
                                                    data-location-id="<?php echo htmlspecialchars($locId); ?>"><svg
                                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                        stroke-linecap="round" stroke-linejoin="round"
                                                        class="lucide lucide-trash2-icon lucide-trash-2">
                                                        <path d="M10 11v6" />
                                                        <path d="M14 11v6" />
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                                        <path d="M3 6h18" />
                                                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                    </svg></button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
            <br>

            <div class="card">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-calendar-plus-icon lucide-calendar-plus">
                            <path d="M16 19h6" />
                            <path d="M16 2v4" />
                            <path d="M19 16v6" />
                            <path d="M21 12.598V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h8.5" />
                            <path d="M3 10h18" />
                            <path d="M8 2v4" />
                        </svg> Add New Day</h2>
                </header>
                <section>
                    <form id="addDayForm" class="form grid gap-6">
                        <div class="grid gap-3"><label class="label" for="newDate">Date</label><input type="date"
                                id="newDate" class="input"></div>
                        <div class="grid gap-3"><label class="label" for="newTime">Time</label><input type="time"
                                id="newTime" class="input" value="20:00"></div>
                        <div class="grid gap-3"><label class="label" for="newTickets">Total Tickets</label><input
                                type="number" id="newTickets" class="input" value="100"></div>
                        <div class="grid gap-3"><label class="label" for="newPrice">Price (€)</label><input
                                type="number" step="0.01" id="newPrice" class="input" value="15.00"></div>
                        <div class="grid gap-3"><label class="label" for="newLocation">Location</label>
                            <select id="newLocation" class="select">
                                <option value="">— No location —</option>
                                <?php if ($shows && !empty($shows["locations"]) && is_array($shows["locations"])):
                                    foreach ($shows["locations"] as $locId => $loc): ?>
                                        <option value="<?php echo htmlspecialchars($locId); ?>"><?php echo htmlspecialchars($loc["name"] ?? ""); ?></option>
                                    <?php endforeach;
                                endif; ?>
                            </select>
                        </div>

                        <div class="grid gap-3">
                            <label class="label" style="display:flex; align-items:center; gap:.5rem; cursor:pointer;">
                                <input type="checkbox" id="newSeating"> Reserved seating (choose seats from the room map)
                            </label>
                            <a href="seatmap.php" class="avo-link" style="font-size:.8rem;">Open seat map editor ↗</a>
                        </div>

                        <div style="margin-top: 1rem;">
                            <button type="submit" class="btn-outline"><svg xmlns="http://www.w3.org/2000/svg" width="24"
                                    height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round"
                                    class="lucide lucide-circle-plus-icon lucide-circle-plus">
                                    <circle cx="12" cy="12" r="10" />
                                    <path d="M8 12h8" />
                                    <path d="M12 8v8" />
                                </svg> Add
                                Day</button>
                        </div>
                    </form>
                </section>
            </div>
            <br>
            <div class="card gap-6">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-calendar-days-icon lucide-calendar-days">
                            <path d="M8 2v4" />
                            <path d="M16 2v4" />
                            <rect width="18" height="18" x="3" y="4" rx="2" />
                            <path d="M3 10h18" />
                            <path d="M8 14h.01" />
                            <path d="M12 14h.01" />
                            <path d="M16 14h.01" />
                            <path d="M8 18h.01" />
                            <path d="M12 18h.01" />
                            <path d="M16 18h.01" />
                        </svg> Current Days</h2>
                </header>
                <section>
                    <div class="days-table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Time</th>
                                    <th>Total</th>
                                    <th>Available</th>
                                    <th>Price</th>
                                    <th>Location</th>
                                    <th>Seating</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="daysTableBody">
                                <?php if ($shows):
                                    foreach (
                                        $shows["dates"]
                                        as $dateId => $dateData
                                    ): ?>
                                        <tr data-date-id="<?php echo $dateId; ?>">
                                            <td><input type="date" class="input" value="<?php echo $dateData[
                                                "date"
                                            ]; ?>"></td>
                                            <td><input type="time" class="input" value="<?php echo $dateData[
                                                "time"
                                            ]; ?>"></td>
                                            <td><input type="number" class="input" value="<?php echo $dateData[
                                                "tickets"
                                            ]; ?>"></td>
                                            <td><input type="number" class="input" value="<?php echo $dateData[
                                                "tickets_available"
                                            ]; ?>">
                                            </td>
                                            <td><input type="number" step="0.01" class="input" value="<?php echo $dateData[
                                                "price"
                                            ]; ?>"></td>
                                            <td>
                                                <select class="select day-location">
                                                    <option value="">— No location —</option>
                                                    <?php if ($shows && !empty($shows["locations"]) && is_array($shows["locations"])):
                                                        foreach ($shows["locations"] as $locId => $loc): ?>
                                                            <option value="<?php echo htmlspecialchars($locId); ?>" <?php echo (($dateData["location"] ?? "") === $locId) ? "selected" : ""; ?>><?php echo htmlspecialchars($loc["name"] ?? ""); ?></option>
                                                        <?php endforeach;
                                                    endif; ?>
                                                </select>
                                            </td>
                                            <td style="text-align:center; white-space:nowrap;">
                                                <input type="checkbox" class="day-seating" title="Reserved seating — uses this day's location seat map"
                                                    <?php echo !empty($dateData["seating"]) ? "checked" : ""; ?>>
                                                <?php if (!empty($dateData["location"])): ?>
                                                    <a href="seatmap.php?loc=<?php echo urlencode($dateData["location"]); ?>" class="avo-link"
                                                        title="Edit the seat map of this day's location" style="font-size:.75rem; margin-left:.4rem;">map ↗</a>
                                                <?php else: ?>
                                                    <span class="avo-muted" title="Assign a location first" style="font-size:.7rem; margin-left:.4rem;">no loc</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn-icon-outline" type="submit" action-type="update-day"
                                                    data-date-id="<?php echo $dateId; ?>"><svg
                                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                        stroke-linecap="round" stroke-linejoin="round"
                                                        class="lucide lucide-save-icon lucide-save">
                                                        <path
                                                            d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z" />
                                                        <path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7" />
                                                        <path d="M7 3v4a1 1 0 0 0 1 1h7" />
                                                    </svg></button>
                                                <button class="btn-icon-destructive" type="submit" action-type="delete-day"
                                                    data-date-id="<?php echo $dateId; ?>"><svg
                                                        xmlns="http://www.w3.org/2000/svg" width="24" height="24"
                                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                                        stroke-linecap="round" stroke-linejoin="round"
                                                        class="lucide lucide-trash2-icon lucide-trash-2">
                                                        <path d="M10 11v6" />
                                                        <path d="M14 11v6" />
                                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6" />
                                                        <path d="M3 6h18" />
                                                        <path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                                    </svg></button>
                                            </td>
                                        </tr>
                                    <?php endforeach;
                                endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <!-- Image Management -->
        <div id="images" style="display: none">
            <h1><svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-image-icon lucide-image" style="margin-right: 10px;">
                    <rect width="18" height="18" x="3" y="3" rx="2" ry="2" />
                    <circle cx="9" cy="9" r="2" />
                    <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21" />
                </svg> Image Management</h1>

            <div class="card">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-images-icon lucide-images">
                            <path d="M18 22H4a2 2 0 0 1-2-2V6" />
                            <path d="m22 13-1.296-1.296a2.41 2.41 0 0 0-3.408 0L14 16" />
                            <circle cx="12" cy="8" r="2" />
                            <path d="m20 6 2 2-3-3-2 2 3 3Z" />
                            <rect width="12" height="16" x="2" y="2" rx="2" />
                        </svg> Current Images</h2>
                </header>
                <section>
                    <div class="grid gap-6">
                        <div class="grid gap-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold">Banner Image</h3>
                                <button class="btn-outline" onclick="document.getElementById('bannerUpload').click()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="lucide lucide-upload-icon lucide-upload">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                        <polyline points="17,8 12,3 7,8" />
                                        <line x1="12" x2="12" y1="3" y2="15" />
                                    </svg>
                                    Upload Banner
                                </button>
                                <input type="file" id="bannerUpload" style="display: none;" accept="image/*"
                                    onchange="uploadImage('banner')">
                            </div>
                            <div id="bannerPreview" class="image-preview-container">
                                <p class="text-muted-foreground">No banner image uploaded</p>
                            </div>
                        </div>

                        <div class="grid gap-4">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold">Logo Image</h3>
                                <button class="btn-outline" onclick="document.getElementById('logoUpload').click()">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                        stroke-linejoin="round" class="lucide lucide-upload-icon lucide-upload">
                                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                                        <polyline points="17,8 12,3 7,8" />
                                        <line x1="12" x2="12" y1="3" y2="15" />
                                    </svg>
                                    Upload Logo
                                </button>
                                <input type="file" id="logoUpload" style="display: none;" accept="image/*"
                                    onchange="uploadImage('logo')">
                            </div>
                            <div id="logoPreview" class="image-preview-container">
                                <p class="text-muted-foreground">No logo image uploaded</p>
                            </div>
                        </div>


                    </div>
                </section>
            </div>
        </div>

        <!-- Screen Software -->
        <div id="screens" style="display: none">
            <h1><svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-monitor-icon lucide-monitor" style="margin-right: 10px;">
                    <rect width="20" height="14" x="2" y="3" rx="2" />
                    <line x1="8" x2="16" y1="21" y2="21" />
                    <line x1="12" x2="12" y1="17" y2="21" />
                </svg> Screen Software</h1>

            <!-- Global Settings -->
            <div class="card" style="margin-bottom: 1.5rem;">
                <header>
                    <h2><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                            class="lucide lucide-settings-icon">
                            <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg> Global Settings</h2>
                </header>
                <section>
                    <div class="grid gap-2" style="max-width: 300px;">
                        <label class="label" for="screenLanguageMode">Language Mode</label>
                        <select id="screenLanguageMode" class="select">
                            <option value="both">Both (EN + DE)</option>
                            <option value="en">English only</option>
                            <option value="de">German only</option>
                        </select>
                        <p class="text-muted-foreground text-sm">Controls which languages are shown on the welcome screen.</p>
                    </div>
                </section>
            </div>

            <!-- Slide Editor Layout -->
            <div style="display: grid; grid-template-columns: 240px 1fr; gap: 1.5rem; align-items: start;">
                <!-- Slide Strip (Left) -->
                <div class="card" style="position: sticky; top: 1.5rem;">
                    <header>
                        <h2>Slides</h2>
                    </header>
                    <section>
                        <div id="slideStrip" style="display: flex; flex-direction: column; gap: 0.5rem;">
                        </div>
                        <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                            <button class="btn-outline" style="flex: 1;" onclick="addSlide()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
                                Add
                            </button>
                        </div>
                    </section>
                </div>

                <!-- Slide Editor (Right) -->
                <div id="slideEditor" class="card">
                    <header>
                        <h2 id="slideEditorTitle">Select a slide</h2>
                    </header>
                    <section id="slideEditorContent">
                        <p class="text-muted-foreground">Click on a slide in the left panel to edit it.</p>
                    </section>
                </div>
            </div>

            <!-- Save Button -->
            <div style="margin-top: 1.5rem;">
                <button class="btn-outline" onclick="saveScreens()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-save-icon"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                    Save All Changes
                </button>
            </div>
        </div>

        <!-- Payment Settings -->
        <?php
        $stripeConfig = $shows['stripe'] ?? ['publishable_key' => '', 'secret_key' => '', 'webhook_secret' => ''];
        $pubKeySet = !empty($stripeConfig['publishable_key']);
        $secretKeySet = !empty($stripeConfig['secret_key']);
        $webhookSecretSet = !empty($stripeConfig['webhook_secret']);
        $isLiveMode = $pubKeySet && str_starts_with($stripeConfig['publishable_key'], 'pk_live_');
        ?>
        <div id="payment-settings" style="display: none;">
            <h1><svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-credit-card-icon lucide-credit-card" style="margin-right: 10px;">
                    <rect width="20" height="14" x="2" y="5" rx="2" />
                    <line x1="2" x2="22" y1="10" y2="10" />
                </svg> Zahlungseinstellungen</h1>

            <div class="card">
                <header>
                    <h2 style="display: flex; align-items: center; justify-content: space-between;">
                        <span style="display: flex; align-items: center; gap: 0.5rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                class="lucide lucide-zap-icon lucide-zap">
                                <path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z" />
                            </svg>
                            Stripe Integration
                        </span>
                        <?php if ($pubKeySet): ?>
                            <span style="padding: 2px 10px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;
                                <?php echo $isLiveMode
                                    ? 'background: color-mix(in oklab, var(--avo-success) 18%, transparent); color: var(--avo-success); border: 1px solid var(--avo-success);'
                                    : 'background: color-mix(in oklab, var(--avo-warning) 18%, transparent); color: var(--avo-warning); border: 1px solid var(--avo-warning);'; ?>">
                                <?php echo $isLiveMode ? 'Live Mode' : 'Test Mode'; ?>
                            </span>
                        <?php endif; ?>
                    </h2>
                    <p>Enter your Stripe API keys. Keys are stored securely in shows.json. The secret key is never sent to the browser.</p>
                </header>
                <section>
                    <form class="form space-y-4" style="max-width: 500px;" onsubmit="savePaymentSettings(); return false;">
                        <div class="grid gap-3">
                            <label class="label" for="stripe-pub-key">Publishable Key
                                <span class="text-muted-foreground text-sm">(safe to expose)</span>
                            </label>
                            <input class="input" type="text" id="stripe-pub-key" placeholder="pk_live_... or pk_test_..."
                                value="<?php echo htmlspecialchars($stripeConfig['publishable_key']); ?>">
                        </div>
                        <div class="grid gap-3">
                            <label class="label" for="stripe-secret-key">Secret Key</label>
                            <input class="input" type="password" id="stripe-secret-key"
                                placeholder="<?php echo $secretKeySet ? '(currently set — leave blank to keep)' : 'sk_live_... or sk_test_...'; ?>">
                            <?php if ($secretKeySet): ?>
                                <p class="text-muted-foreground text-sm">A secret key is currently set. Enter a new value to replace it.</p>
                            <?php endif; ?>
                        </div>
                        <div class="grid gap-3">
                            <label class="label" for="stripe-webhook-secret">Webhook Secret
                                <span class="text-muted-foreground text-sm">(optional)</span>
                            </label>
                            <input class="input" type="password" id="stripe-webhook-secret"
                                placeholder="<?php echo $webhookSecretSet ? '(currently set — leave blank to keep)' : 'whsec_...'; ?>">
                        </div>
                        <button type="submit" class="btn-outline">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-save-icon"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg>
                            Save Keys
                        </button>
                    </form>
                </section>
            </div>
        </div>

        <!-- Accounts -->
        <div id="accounts" style="display: none">
            <h1><svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    class="lucide lucide-users-icon" style="margin-right: 10px;">
                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" /><circle cx="9" cy="7" r="4" />
                    <path d="M22 21v-2a4 4 0 0 0-3-3.87" /><path d="M16 3.13a4 4 0 0 1 0 7.75" />
                </svg> Benutzerkonten</h1>

            <div class="card" style="margin-bottom: 1.5rem;">
                <header>
                    <h2>Neues Konto anlegen</h2>
                    <p>Lege ein Konto an und vergib Zugänge. Das Passwort kann der Nutzer später selbst ändern.</p>
                </header>
                <section>
                    <form id="createAccountForm" class="form grid gap-4" style="max-width: 460px;"
                          onsubmit="createAccount(); return false;">
                        <div class="grid gap-2">
                            <label class="label" for="acc-username">Benutzername</label>
                            <input class="input" type="text" id="acc-username" autocomplete="off" required>
                        </div>
                        <div class="grid gap-2">
                            <label class="label" for="acc-password">Passwort <span class="text-muted-foreground text-sm">(min. 6 Zeichen)</span></label>
                            <input class="input" type="text" id="acc-password" autocomplete="off" required>
                        </div>
                        <fieldset class="grid gap-2">
                            <legend class="label">Zugänge</legend>
                            <label class="flex items-center gap-2"><input type="checkbox" id="acc-admin"> Admin (Verwaltung & Konten)</label>
                            <label class="flex items-center gap-2"><input type="checkbox" id="acc-ticketflow"> TicketFlow (Abendkasse)</label>
                            <label class="flex items-center gap-2"><input type="checkbox" id="acc-handheld"> Handheld (Scanner)</label>
                        </fieldset>
                        <div><button type="submit" class="btn-outline">Konto anlegen</button></div>
                    </form>
                </section>
            </div>

            <div class="card">
                <header>
                    <h2>Bestehende Konten</h2>
                </header>
                <section>
                    <div class="days-table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Benutzername</th>
                                    <th>Admin</th>
                                    <th>TicketFlow</th>
                                    <th>Handheld</th>
                                    <th>Aktionen</th>
                                </tr>
                            </thead>
                            <tbody id="accountsTableBody">
                                <tr><td colspan="5" class="text-muted-foreground">Lade…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <div id="danger" style="display: none">
            <style>
                #danger .btn-destructive {
                    display: inline-flex; align-items: center; justify-content: center;
                    white-space: nowrap; border-radius: 11px; border: 1px solid transparent;
                    padding: 0.5rem 1rem; font-size: 0.875rem; font-weight: 600;
                    background-color: #C73D20; color: #fff; cursor: pointer;
                    transition: background-color .15s ease;
                }
                #danger .btn-destructive:hover { background-color: #9C2E16; }
            </style>
            <div class="card">
                <header>
                    <h2>Backup</h2>
                    <p class="text-muted-foreground">Lade eine vollständige Kopie der Datenbank (Events, Tickets, Statistiken, Konten &amp; Einstellungen) als <code>.db</code>-Datei herunter. Konsistenter Snapshot — auch im laufenden Betrieb sicher.</p>
                </header>
                <section>
                    <button type="button" class="btn-outline" onclick="downloadBackup(this)">
                        Datenbank-Backup herunterladen
                    </button>
                </section>
            </div>

            <div class="card" style="border-color:#C73D20;">
                <header>
                    <h2 style="color:#C73D20;">Gefahrenzone</h2>
                    <p class="text-muted-foreground">Diese Aktionen sind <strong>unwiderruflich</strong>. Erstelle vorher ein Backup. Zur Bestätigung musst du jeweils ein Wort eintippen.</p>
                </header>
                <section class="grid gap-4">
                    <div class="flex items-center justify-between gap-4" style="flex-wrap:wrap;">
                        <div>
                            <strong>Alle Daten löschen</strong>
                            <div class="text-muted-foreground text-sm">Löscht alle Tickets, Verkäufe und Statistiken. Plätze werden auf die volle Kapazität zurückgesetzt. Event-Konfiguration und Konten bleiben erhalten.</div>
                        </div>
                        <button type="button" class="btn-destructive" onclick="dangerAction('wipe_data','LÖSCHEN','Alle Daten löschen')">Daten löschen</button>
                    </div>

                    <div class="flex items-center justify-between gap-4" style="flex-wrap:wrap;border-top:1px solid var(--avo-border);padding-top:1rem;">
                        <div>
                            <strong>System neu installieren</strong>
                            <div class="text-muted-foreground text-sm">Startet den Einrichtungsassistenten erneut. Alle Daten bleiben erhalten — nur die Installation wird zurückgesetzt.</div>
                        </div>
                        <button type="button" class="btn-destructive" onclick="dangerAction('reinstall','INSTALL','System neu installieren')">Neu installieren</button>
                    </div>

                    <div class="flex items-center justify-between gap-4" style="flex-wrap:wrap;border-top:1px solid var(--avo-border);padding-top:1rem;">
                        <div>
                            <strong>Auf Werkseinstellungen zurücksetzen</strong>
                            <div class="text-muted-foreground text-sm">Löscht <strong>alles</strong>: Events, Tickets, Statistiken, Einstellungen, hochgeladene Bilder. Das Admin-Konto wird auf <code>admin/admin</code> zurückgesetzt und das System ist danach uninstalliert.</div>
                        </div>
                        <button type="button" class="btn-destructive" onclick="dangerAction('factory_reset','WERKSRESET','Auf Werkseinstellungen zurücksetzen')">Werksreset</button>
                    </div>
                </section>
            </div>
        </div>

        <?php
        $orgName = $shows['orga_name'] ?? '';
        $current_language = 'en';
        include __DIR__ . '/../partials/footer.php';
        ?>
    </main>

    <div id="notificationContainer" class="notification-container"></div>

    <script>
        // Browser-facing base for backend images (same-origin; nginx proxies /api/image/).
        const API_BASE_URL = '<?php echo PUBLIC_API_BASE; ?>';
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        function showToast(message, type = 'success', duration = 3000) {
            const category = type === 'error' ? 'error' : type === 'warning' ? 'warning' : 'success';
            const title = type === 'error' ? 'Error' : type === 'warning' ? 'Warning' : 'Success';

            document.dispatchEvent(new CustomEvent('basecoat:toast', {
                detail: {
                    config: {
                        category: category,
                        title: title,
                        description: message,
                        duration: duration,
                        cancel: {
                            label: 'Dismiss'
                        }
                    }
                }
            }));
        }
        // --- Danger zone + backup -------------------------------------------
        async function downloadBackup(btn) {
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Erstelle Backup…';
            try {
                const res = await fetch('backup.php');
                if (!res.ok) {
                    let msg = 'Backup fehlgeschlagen (' + res.status + ')';
                    try { msg = (await res.json()).message || msg; } catch (e) {}
                    throw new Error(msg);
                }
                const blob = await res.blob();
                const dispo = res.headers.get('Content-Disposition') || '';
                const m = dispo.match(/filename="([^"]+)"/);
                const name = m ? m[1] : 'qrgate-backup.db';
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = name;
                document.body.appendChild(a); a.click(); a.remove();
                URL.revokeObjectURL(url);
                showToast('Backup heruntergeladen.');
            } catch (e) {
                showToast(e.message || 'Backup fehlgeschlagen', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        }

        async function dangerAction(action, word, label) {
            const typed = window.prompt(
                '⚠ ' + label + '\n\nDiese Aktion kann NICHT rückgängig gemacht werden.\n' +
                'Tippe "' + word + '" um fortzufahren:'
            );
            if (typed === null) return;            // cancelled
            if (typed.trim() !== word) {
                showToast('Bestätigung stimmt nicht — abgebrochen.', 'warning');
                return;
            }
            try {
                const res = await fetch('api.php?action=' + action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }
                }).then(r => r.json());
                if (res.status === 'success') {
                    showToast(res.message || 'Erledigt.', 'success', 6000);
                    // After reinstall / factory reset the system needs the setup
                    // wizard again — send the operator there.
                    if (action === 'reinstall' || action === 'factory_reset') {
                        localStorage.removeItem('currentAdminSection');
                        setTimeout(() => { window.location.href = '/install'; }, 2500);
                    }
                } else {
                    showToast('Fehler: ' + (res.message || 'unbekannt'), 'error', 6000);
                }
            } catch (e) {
                showToast('Anfrage fehlgeschlagen: ' + e.message, 'error');
            }
        }

        function switchSection(targetId) {
            if (targetId === 'logout') {
                window.location.href = 'logout.php';
                return;
            }
            if (targetId === 'switch-app') {
                window.location.href = 'apps.php';
                return;
            }

            document.querySelectorAll('main > div').forEach(s => s.style.display = 'none');
            document.getElementById(targetId).style.display = 'block';
            localStorage.setItem('currentAdminSection', targetId);
        }

        document.querySelectorAll('aside a').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                const target = e.target.closest('a').getAttribute('data-section');
                switchSection(target);

                setTimeout(() => location.reload(), 100);
            });
        });

        const saved = localStorage.getItem('currentAdminSection');
        if (saved && document.getElementById(saved)) switchSection(saved);

        // ---- live check-in widget (dashboard) ----------------------------
        // Polls the backend for per-date door check-in counts + the latest scans
        // and paints them into the "Live Einlass" card. Runs only while the
        // dashboard section is on screen; failures keep the last good values.
        (function () {
            const card = document.getElementById('checkinCard');
            if (!card) return;
            const $ = id => document.getElementById(id);
            function fmtTime(s) {
                if (!s) return '';
                const m = String(s).match(/(\d{2}):(\d{2})(?::\d{2})?\s*$/);
                return m ? m[1] + ':' + m[2] : s;
            }
            function esc(s) {
                const d = document.createElement('div');
                d.textContent = (s == null ? '' : s);
                return d.innerHTML;
            }
            async function poll() {
                const dash = document.getElementById('dashboard');
                if (!dash || dash.style.display === 'none') return;
                try {
                    const r = await fetch('admin-api-proxy.php?endpoint=checkins', { cache: 'no-store' });
                    const j = await r.json();
                    if (!j || j.status !== 'success' || !j.data) return;
                    const d = j.data;
                    const byDate = d.by_date || {};
                    // Prefer today's event; otherwise the date with the most sold tickets.
                    let key = (d.today && byDate[d.today]) ? d.today : null;
                    if (!key) {
                        let best = -1;
                        for (const k in byDate) {
                            if ((byDate[k].sold || 0) > best) { best = byDate[k].sold || 0; key = k; }
                        }
                    }
                    const row = key ? byDate[key] : { sold: 0, checked_in: 0, pending: 0 };
                    const sold = row.sold || 0, ci = row.checked_in || 0, pending = row.pending || 0;
                    const pct = sold > 0 ? Math.round((ci / sold) * 100) : 0;
                    $('checkinDateLabel').textContent = key
                        ? (key === d.today ? 'Heute · ' + key : key)
                        : 'Kein Event mit Terminverkauf';
                    $('checkinBig').textContent = ci;
                    $('checkinOf').textContent = '/ ' + sold + ' eingecheckt';
                    $('checkinPct').textContent = pct + '%';
                    $('checkinBar').style.width = pct + '%';
                    $('checkinPending').textContent = pending;
                    $('checkinSold').textContent = sold;
                    const rec = d.recent || [];
                    const box = $('checkinRecent');
                    if (!rec.length) {
                        box.innerHTML = '<div style="color:var(--avo-text-muted);font-size:.85rem;">Noch keine Scans.</div>';
                    } else {
                        box.innerHTML = rec.map(function (x) {
                            const name = esc(x.name || x.tid);
                            const seat = x.seat_label ? ' · ' + esc(x.seat_label) : '';
                            return '<div style="display:flex;justify-content:space-between;gap:1rem;font-size:.88rem;' +
                                'border-bottom:1px solid color-mix(in oklab,var(--avo-border) 60%,transparent);padding:.3rem 0;">' +
                                '<span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + name + seat + '</span>' +
                                '<span style="color:var(--avo-text-muted);font-variant-numeric:tabular-nums;flex-shrink:0;">' +
                                esc(fmtTime(x.used_at)) + '</span></div>';
                        }).join('');
                    }
                } catch (e) { /* transient network/backend hiccup — keep last values */ }
            }
            poll();
            setInterval(poll, 8000);
        })();


        function initCharts() {
            const dates = <?php echo json_encode(array_keys($stats["soldByDate"] ?? [])); ?>;
            const soldData = <?php echo json_encode(array_values($stats["soldByDate"] ?? [])); ?>;
            const availableData = <?php echo json_encode(array_values($stats["availableByDate"] ?? [])); ?>;

            if (dates.length === 0) return;

            const css = getComputedStyle(document.documentElement);
            const coral = css.getPropertyValue('--avo-primary').trim();
            const muted = css.getPropertyValue('--avo-text-muted').trim();
            const border = css.getPropertyValue('--avo-border').trim();
            const surface = css.getPropertyValue('--avo-surface').trim();
            const textColor = css.getPropertyValue('--avo-text').trim();

            // coral-ramp palette for categorical (pie) series
            const coralRamp = ['#FF6B4A', '#ED5333', '#C73D20', '#FF9379', '#9C2E16', '#FFB6A4', '#FF7A5C', '#5F1C0D'];
            const generateColors = (baseColor, count) => {
                const colors = [];
                for (let i = 0; i < count; i++) {
                    colors.push(coralRamp[i % coralRamp.length]);
                }
                return colors;
            };


            const salesCtx = document.getElementById('salesChart')?.getContext('2d');
            if (salesCtx) {
                new Chart(salesCtx, {
                    type: 'pie',
                    data: {
                        labels: dates,
                        datasets: [{
                            label: 'Tickets Sold',
                            data: soldData,
                            backgroundColor: generateColors(270, dates.length),
                            borderColor: border,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                labels: {
                                    color: muted,
                                    font: { size: 13 }
                                }
                            },
                            tooltip: {
                                titleColor: textColor,
                                bodyColor: textColor,
                                backgroundColor: surface,
                                borderColor: border,
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    label: function (context) {
                                        return `${context.label}: ${context.parsed} tickets sold`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            
            const availCtx = document.getElementById('availabilityChart')?.getContext('2d');
            if (availCtx) {
                new Chart(availCtx, {
                    type: 'pie',
                    data: {
                        labels: dates,
                        datasets: [{
                            label: 'Available Tickets',
                            data: availableData,
                            backgroundColor: generateColors(140, dates.length),
                            borderColor: border,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                labels: {
                                    color: muted,
                                    font: { size: 13 }
                                }
                            },
                            tooltip: {
                                titleColor: textColor,
                                bodyColor: textColor,
                                backgroundColor: surface,
                                borderColor: border,
                                borderWidth: 1,
                                padding: 10,
                                callbacks: {
                                    label: function (context) {
                                        return `${context.label}: ${context.parsed} tickets available`;
                                    }
                                }
                            }
                        }
                    }
                });
            }

            
            loadStatsData();
        }

        async function loadStatsData() {
            try {
                const response = await fetch('admin-api-proxy.php?endpoint=stats');
                const data = await response.json();

                if (data.status === 'success' && data.data) {
                    const stats = data.data;

                    const css = getComputedStyle(document.documentElement);
                    const coral = css.getPropertyValue('--avo-primary').trim();
                    const coralSoft = 'color-mix(in oklab, ' + coral + ' 12%, transparent)';
                    const muted = css.getPropertyValue('--avo-text-muted').trim();
                    const gridColor = 'color-mix(in oklab, ' + css.getPropertyValue('--avo-border').trim() + ' 70%, transparent)';
                    // sales line = mono (theme-aware) so coral stays the single accent, distinct from the income line
                    const salesColor = css.getPropertyValue('--avo-text').trim();

                    const incomeDates = Object.keys(stats.income_by_date || {});
                    const incomeValues = Object.values(stats.income_by_date || {});

                    const incomeCtx = document.getElementById('incomeChart')?.getContext('2d');
                    if (incomeCtx && incomeDates.length > 0) {
                        new Chart(incomeCtx, {
                            type: 'line',
                            data: {
                                labels: incomeDates,
                                datasets: [{
                                    label: 'Income (€)',
                                    data: incomeValues,
                                    borderColor: coral,
                                    backgroundColor: coralSoft,
                                    tension: 0.4,
                                    fill: true
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: { labels: { color: muted } },
                                    tooltip: {
                                        callbacks: {
                                            label: function (context) {
                                                return '€' + context.parsed.y.toFixed(2);
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: {
                                            color: muted,
                                            callback: function (value) {
                                                return '€' + value.toFixed(2);
                                            }
                                        },
                                        grid: { color: gridColor }
                                    },
                                    x: {
                                        ticks: { color: muted },
                                        grid: { color: gridColor }
                                    }
                                }
                            }
                        });
                    }

                    
                    const salesDates = Object.keys(stats.sales_by_date || {});
                    const salesValues = Object.values(stats.sales_by_date || {});

                    const salesCtx = document.getElementById('totalSalesChart')?.getContext('2d');
                    if (salesCtx && salesDates.length > 0) {
                        new Chart(salesCtx, {
                            type: 'line',
                            data: {
                                labels: salesDates,
                                datasets: [{
                                    label: 'Tickets Sold',
                                    data: salesValues,
                                    backgroundColor: 'color-mix(in oklab, ' + salesColor + ' 10%, transparent)',
                                    borderColor: salesColor,
                                    tension: 0.4,
                                    fill: true
                                }]
                            },
                            options: {
                                responsive: true,
                                plugins: {
                                    legend: { labels: { color: muted } },
                                    tooltip: {
                                        callbacks: {
                                            label: function (context) {
                                                return context.parsed.y + ' tickets';
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        ticks: { color: muted },
                                        grid: { color: gridColor }
                                    },
                                    x: {
                                        ticks: { color: muted },
                                        grid: { color: gridColor }
                                    }
                                }
                            }
                        });
                    }
                }
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }

        if (document.getElementById('statistics').style.display !== 'none' || localStorage.getItem('currentAdminSection') === 'statistics') {
            setTimeout(initCharts, 200);
        }

        document.querySelector('aside a[data-section="statistics"]').addEventListener('click', () => setTimeout(initCharts, 200));

        
        document.getElementById('eventForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = e.submitter;
            btn.disabled = true;
            const data = {
                orga_name: document.getElementById('orgaName').value,
                title: document.getElementById('eventTitle').value,
                subtitle: document.getElementById('eventSubtitle').value,
                banner: document.getElementById('bannerUrl').value,
                contact_email: document.getElementById('contactEmail').value,
                app_domain: document.getElementById('appDomain').value.trim(),
                store_lock: document.getElementById('storeLock').checked,
                payment_methods: document.getElementById('paymentMethods').value,
                dates: {}
            };
            <?php if ($shows) {
                foreach ($shows["dates"] as $id => $d): ?>
                    data.dates["<?php echo $id; ?>"] = {
                        date: "<?php echo $d[
                            "date"
                        ]; ?>", time: "<?php echo $d["time"]; ?>", tickets: <?php echo $d[
                                "tickets"
                            ]; ?>, tickets_available: <?php echo $d[
                                 "tickets_available"
                             ]; ?>, price: "<?php echo $d["price"]; ?>", location: "<?php echo htmlspecialchars($d["location"] ?? "", ENT_QUOTES); ?>"
                    };
                <?php endforeach;
                ;
            } ?>

            fetch('admin-api-proxy.php?endpoint=show_edit', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify(data)
            })
                .then(r => r.json())
                .then(res => {
                    btn.disabled = false;
                    if (res.status === 'success') {
                        showToast('Event updated!');
                        localStorage.setItem('currentAdminSection', 'event');
                        //setTimeout(() => location.reload(), 500);
                    } else showToast('Error: ' + (res.message || 'Unknown'), 'error');
                })
                .catch(err => {
                    btn.disabled = false;
                    showToast('Network error', 'error');
                });
        });

        
        document.getElementById('addDayForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const data = {
                dateId: 'day_' + Date.now(),
                date: document.getElementById('newDate').value,
                time: document.getElementById('newTime').value,
                tickets: document.getElementById('newTickets').value,
                price: document.getElementById('newPrice').value,
                location: document.getElementById('newLocation') ? document.getElementById('newLocation').value : '',
                seating: document.getElementById('newSeating') ? document.getElementById('newSeating').checked : false
            };
            fetch('api.php?action=add_day', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN }, body: JSON.stringify(data) })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        showToast('Day added!');
                        localStorage.setItem('currentAdminSection', 'days');
                        setTimeout(() => location.reload(), 1000);
                    } else showToast('Error: ' + res.message, 'error');
                });
        });

        document.getElementById('addLocationForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const name = document.getElementById('newLocationName').value.trim();
            const address = document.getElementById('newLocationAddress').value.trim();
            if (!name) { showToast('Location name is required', 'error'); return; }
            fetch('api.php?action=add_location', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify({ name, address })
            })
                .then(r => r.json())
                .then(res => {
                    if (res.status === 'success') {
                        showToast('Location added!');
                        localStorage.setItem('currentAdminSection', 'days');
                        setTimeout(() => location.reload(), 800);
                    } else showToast('Error: ' + (res.message || 'Unknown'), 'error');
                })
                .catch(() => showToast('Network error', 'error'));
        });

        document.getElementById('locationsTableBody')?.addEventListener('click', async function (e) {
            const btn = e.target.closest('button');
            if (!btn) return;
            const locationId = btn.getAttribute('data-location-id');
            const row = btn.closest('tr');

            if (btn.getAttribute('action-type') === 'delete-location') {
                const confirmed = await showConfirmationDialog('Delete this location? Days using it will lose their location.');
                if (!confirmed) return;
                fetch('api.php?action=delete_location', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ locationId })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') {
                            showToast('Location deleted!');
                            setTimeout(() => location.reload(), 600);
                        } else showToast('Error: ' + (res.message || 'Unknown'), 'error');
                    })
                    .catch(() => showToast('Network error', 'error'));
            } else if (btn.getAttribute('action-type') === 'update-location') {
                const name = row.querySelector('.location-name').value.trim();
                const address = row.querySelector('.location-address').value.trim();
                if (!name) { showToast('Location name is required', 'error'); return; }
                fetch('api.php?action=update_location', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ locationId, name, address })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') showToast('Location updated!');
                        else showToast('Error: ' + (res.message || 'Unknown'), 'error');
                    })
                    .catch(() => showToast('Network error', 'error'));
            }
        });

        function showConfirmationDialog(message) {
            const dialog = document.getElementById('confirmation-dialog');
            const description = dialog.querySelector('#confirmation-dialog-description');
            const confirmBtn = dialog.querySelector('#confirmation-dialog-confirm');

            return new Promise((resolve) => {
                description.textContent = message;
                confirmBtn.onclick = () => {
                    dialog.close();
                    resolve(true);
                };
                dialog.addEventListener('close', () => resolve(false), { once: true });
                dialog.showModal();
            });
        }

        
        document.getElementById('daysTableBody').addEventListener('click', async function (e) {
            const btn = e.target.closest('button');
            if (!btn) return;
            const dateId = btn.getAttribute('data-date-id');
            const row = btn.closest('tr');

            if (btn.getAttribute('action-type') === "delete-day") {
                const confirmed = await showConfirmationDialog('Delete this day? This action cannot be undone.');
                if (!confirmed) return;

                fetch('api.php?action=delete_day', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ dateId })
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') {
                            showToast('Day deleted!');
                            row.remove();
                        } else {
                            showToast('Error: ' + res.message, 'error');
                        }
                    })
                    .catch(err => {
                        showToast('Network error', 'error');
                    });

            } else if (btn.getAttribute('action-type') === "update-day") {
                const row = btn.closest('tr');
                const dateId = btn.getAttribute('data-date-id');

                const dateInput = row.querySelector('input[type="date"]').value;
                const timeInput = row.querySelector('input[type="time"]').value;
                const numberInputs = row.querySelectorAll('input[type="number"]');

                if (numberInputs.length < 3) {
                    showToast('Input fields incomplete.', 'error');
                    return;
                }

                const ticketsInput = parseInt(numberInputs[0].value, 10);     
                const availableInput = parseInt(numberInputs[1].value, 10);  
                const priceInput = parseFloat(numberInputs[2].value);        

                
                if (!dateInput || !timeInput || isNaN(ticketsInput) || isNaN(availableInput) || isNaN(priceInput)) {
                    showToast('Please fill in all fields correctly.', 'error');
                    return;
                }

                if (availableInput > ticketsInput || availableInput < 0 || ticketsInput < 0) {
                    showToast('Invalid input. Please check the values you entered.', 'error');
                    return;
                }

                const locationSelect = row.querySelector('select.day-location');
                const seatingInput = row.querySelector('.day-seating');
                const updateData = {
                    dateId: dateId,
                    date: dateInput,
                    time: timeInput,
                    tickets: ticketsInput,
                    available: availableInput,
                    price: priceInput.toFixed(2),
                    location: locationSelect ? locationSelect.value : '',
                    seating: seatingInput ? seatingInput.checked : false
                };

                fetch('api.php?action=update_day', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify(updateData)
                })
                    .then(r => r.json())
                    .then(res => {
                        if (res.status === 'success') {
                            showToast('Day updated successfully!');
                        } else {
                            showToast('Error: ' + (res.message || 'Unknown error'), 'error');
                        }
                    })
                    .catch(err => {
                        console.error('Update error:', err);
                        showToast('Network or server error.', 'error');
                    });
            }
        });

        
        async function loadCurrentImages() {
            const timestamp = new Date().getTime();

            try {
                
                const bannerPreview = document.getElementById('bannerPreview');
                bannerPreview.innerHTML = `<img src="${API_BASE_URL}/api/image/get/banner.png?t=${timestamp}" alt="Banner" class="max-w-full max-h-[300px] object-contain" onerror="this.onerror=null; this.src='${API_BASE_URL}/api/image/get/banner.png';">`;

                
                const logoPreview = document.getElementById('logoPreview');
                logoPreview.innerHTML = `<img src="${API_BASE_URL}/api/image/get/logo.png?t=${timestamp}" alt="Logo" class="max-w-full max-h-[200px] object-contain" onerror="this.onerror=null; this.src='${API_BASE_URL}/api/image/get/logo.png';">`;
            } catch (error) {
                console.error('Error loading images:', error);
                showToast('Error loading images', 'error');
            }
        }


        async function uploadImage(type) {
            const fileInput = document.getElementById(`${type}Upload`);
            const file = fileInput.files[0];

            if (!file) {
                showToast('Keine Datei ausgewählt', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('file', file);
            formData.append('type', type);

            try {
                const response = await fetch('api.php?action=upload_image', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: formData,
                });

                const responseText = await response.text();
                console.log('Server-Antwort:', responseText);

                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (e) {
                    throw new Error(`Server antwortete nicht mit JSON: ${responseText.substring(0, 100)}...`);
                }

                if (data.status === 'success') {
                    showToast(`${type} erfolgreich hochgeladen!`, 'success');
                    await loadCurrentImages();
                } else {
                    throw new Error(data.message || 'Unbekannter Fehler');
                }
            } catch (error) {
                console.error('Upload-Fehler:', error);
                showToast(`Fehler: ${error.message}`, 'error');
            }
        }


        
        document.querySelector('aside a[data-section="images"]').addEventListener('click', () => {
            setTimeout(loadCurrentImages, 200);
        });


        if (window.location.hash === '#images' || localStorage.getItem('currentAdminSection') === 'images') {
            setTimeout(loadCurrentImages, 200);
        }

        // ===== Screen Software =====
        const iconOptions = [
            { value: 'fa-smile', label: 'Smile' },
            { value: 'fa-heart', label: 'Heart' },
            { value: 'fa-ticket', label: 'Ticket' },
            { value: 'fa-theater-masks', label: 'Theater Masks' },
            { value: 'fa-users', label: 'Users' },
            { value: 'fa-star', label: 'Star' },
            { value: 'fa-music', label: 'Music' },
            { value: 'fa-hand-peace', label: 'Peace' },
            { value: 'fa-fire', label: 'Fire' },
            { value: 'fa-gift', label: 'Gift' },
            { value: 'fa-microphone', label: 'Microphone' },
            { value: 'fa-camera', label: 'Camera' },
        ];

        const animationOptions = [
            { value: 'none', label: 'None' },
            { value: 'bounce 1s infinite', label: 'Bounce' },
            { value: 'pulse 1s infinite', label: 'Pulse' },
            { value: 'wobble 1s infinite', label: 'Wobble' },
            { value: 'laugh 0.5s infinite', label: 'Laugh' },
        ];

        const defaultSlides = [
            {
                id: 'slide_1',
                icon: 'fa-smile',
                icon_animation: 'laugh 0.5s infinite',
                text_en: 'Welcome to\n{orga_name}',
                text_de: 'Willkommen bei der\n{orga_name}',
                cast: []
            },
            {
                id: 'slide_2',
                icon: 'fa-theater-masks',
                icon_animation: 'bounce 1s infinite',
                text_en: '{show_title}\n{show_subtitle}',
                text_de: '{show_title}\n{show_subtitle}',
                cast: []
            },
            {
                id: 'slide_3',
                icon: 'fa-heart',
                icon_animation: 'pulse 1s infinite',
                text_en: 'We are so happy to see you here!',
                text_de: 'Wir freuen uns sehr, dich hier zu sehen!',
                cast: []
            },
            {
                id: 'slide_4',
                icon: 'fa-ticket',
                icon_animation: 'wobble 1s infinite',
                text_en: 'To ensure a quick and smooth check-in,\nplease have your ticket ready before entering.',
                text_de: 'Um einen zügigen Check-in zu ermöglichen,\nhalte bitte dein Ticket vor dem Einlass bereit.',
                cast: []
            }
        ];

        let screensData = {
            language_mode: 'both',
            slides: []
        };
        let selectedSlideIndex = -1;

        function loadScreensData() {
            const showData = <?php echo json_encode($shows ?? []); ?>;
            if (showData && showData.screens) {
                screensData = JSON.parse(JSON.stringify(showData.screens));
            } else {
                screensData = {
                    language_mode: 'both',
                    slides: JSON.parse(JSON.stringify(defaultSlides))
                };
            }
            document.getElementById('screenLanguageMode').value = screensData.language_mode || 'both';
            renderSlideStrip();
            if (screensData.slides.length > 0) {
                selectSlide(0);
            }
        }

        function renderSlideStrip() {
            const strip = document.getElementById('slideStrip');
            strip.innerHTML = '';
            screensData.slides.forEach((slide, index) => {
                const item = document.createElement('div');
                item.style.cssText = 'padding: 0.5rem; border: 2px solid ' + (index === selectedSlideIndex ? 'var(--avo-primary)' : 'var(--avo-border)') + '; border-radius: var(--avo-radius-md); cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: border-color 0.2s;';
                item.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 0.5rem; flex: 1; min-width: 0;">
                        <i class="fas ${slide.icon}" style="font-size: 1.2em; width: 24px; text-align: center;"></i>
                        <span style="font-size: 0.85em; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${slide.text_en ? slide.text_en.split('\\n')[0].substring(0, 20) : 'Slide ' + (index + 1)}</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 2px;">
                        <button class="btn-icon-outline" style="padding: 2px; width: 20px; height: 20px;" onclick="event.stopPropagation(); moveSlide(${index}, -1)" ${index === 0 ? 'disabled' : ''}>
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m18 15-6-6-6 6"/></svg>
                        </button>
                        <button class="btn-icon-outline" style="padding: 2px; width: 20px; height: 20px;" onclick="event.stopPropagation(); moveSlide(${index}, 1)" ${index === screensData.slides.length - 1 ? 'disabled' : ''}>
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </div>
                `;
                item.addEventListener('click', () => selectSlide(index));
                strip.appendChild(item);
            });
        }

        function selectSlide(index) {
            selectedSlideIndex = index;
            renderSlideStrip();
            renderSlideEditor();
        }

        function renderSlideEditor() {
            const container = document.getElementById('slideEditorContent');
            const title = document.getElementById('slideEditorTitle');

            if (selectedSlideIndex < 0 || selectedSlideIndex >= screensData.slides.length) {
                title.textContent = 'Select a slide';
                container.innerHTML = '<p class="text-muted-foreground">Click on a slide in the left panel to edit it.</p>';
                return;
            }

            const slide = screensData.slides[selectedSlideIndex];
            title.textContent = 'Edit Slide ' + (selectedSlideIndex + 1);

            const hasCast = slide.cast && slide.cast.length > 0;

            let iconOptionsHtml = iconOptions.map(o =>
                `<option value="${o.value}" ${slide.icon === o.value ? 'selected' : ''}>${o.label}</option>`
            ).join('');

            let animOptionsHtml = animationOptions.map(o =>
                `<option value="${o.value}" ${slide.icon_animation === o.value ? 'selected' : ''}>${o.label}</option>`
            ).join('');

            let castHtml = '';
            if (hasCast) {
                castHtml = slide.cast.map((member, i) => `
                    <div style="display: flex; gap: 0.5rem; align-items: center; padding: 0.5rem; border: 1px solid var(--avo-border); border-radius: var(--avo-radius-md);">
                        <div style="width: 48px; height: 48px; border-radius: 50%; overflow: hidden; background: var(--avo-surface); flex-shrink: 0; display: flex; align-items: center; justify-content: center;">
                            ${member.image ? `<img src="${API_BASE_URL}/api/show/cast/image/${member.image}" style="width: 100%; height: 100%; object-fit: cover;">` : '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'}
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 0.25rem; flex: 1;">
                            <input type="text" class="input" value="${member.name || ''}" placeholder="Name" onchange="updateCastField(${i}, 'name', this.value)">
                            <input type="text" class="input" value="${member.role || ''}" placeholder="Role (optional)" onchange="updateCastField(${i}, 'role', this.value)">
                        </div>
                        <button class="btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.8em;" onclick="document.getElementById('castFileInput_${i}').click()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17,8 12,3 7,8"/><line x1="12" x2="12" y1="3" y2="15"/></svg>
                            Image
                        </button>
                        <input type="file" id="castFileInput_${i}" style="display: none;" accept="image/*" onchange="uploadCastMemberImage(${i}, this)">
                        <button class="btn-icon-destructive" style="padding: 4px;" onclick="removeCastMember(${i})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>
                `).join('');
            }

            container.innerHTML = `
                <div class="grid gap-6">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="grid gap-2">
                            <label class="label">Icon</label>
                            <select class="select" onchange="updateSlideField('icon', this.value)">
                                ${iconOptionsHtml}
                            </select>
                        </div>
                        <div class="grid gap-2">
                            <label class="label">Icon Animation</label>
                            <select class="select" onchange="updateSlideField('icon_animation', this.value)">
                                ${animOptionsHtml}
                            </select>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <label class="label">Text (English)</label>
                        <textarea class="textarea" rows="3" onchange="updateSlideField('text_en', this.value)" placeholder="Use \\n for line breaks. Placeholders: {orga_name}, {show_title}, {show_subtitle}">${slide.text_en || ''}</textarea>
                        <p class="text-muted-foreground text-sm">Use {orga_name}, {show_title}, {show_subtitle} as placeholders.</p>
                    </div>

                    <div class="grid gap-2">
                        <label class="label">Text (German)</label>
                        <textarea class="textarea" rows="3" onchange="updateSlideField('text_de', this.value)" placeholder="Use \\n for line breaks. Placeholders: {orga_name}, {show_title}, {show_subtitle}">${slide.text_de || ''}</textarea>
                    </div>

                    <div style="border-top: 1px solid var(--avo-border); padding-top: 1rem;">
                        <div class="flex items-start gap-3" style="margin-bottom: 1rem;">
                            <input type="checkbox" class="input" id="castToggle" ${hasCast ? 'checked' : ''} onchange="toggleCast(this.checked)">
                            <div class="flex flex-col gap-1">
                                <label class="leading-snug" for="castToggle">Show Cast on this slide</label>
                                <p class="text-muted-foreground text-sm">Enable to display cast members on this slide.</p>
                            </div>
                        </div>

                        <div id="castSection" style="display: ${hasCast ? 'block' : 'none'};">
                            <div id="castMembers" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                ${castHtml}
                            </div>
                            <button class="btn-outline" style="margin-top: 0.75rem;" onclick="addCastMember()">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
                                Add Cast Member
                            </button>
                            <p class="text-muted-foreground text-sm" style="margin-top: 0.5rem;">Cast members are automatically split across multiple slides (up to 6 per slide).</p>
                        </div>
                    </div>

                    <div style="border-top: 1px solid var(--avo-border); padding-top: 1rem;">
                        <button class="btn-destructive" onclick="deleteSlide(${selectedSlideIndex})">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                            Delete Slide
                        </button>
                    </div>
                </div>
            `;
        }

        function updateSlideField(field, value) {
            if (selectedSlideIndex < 0) return;
            screensData.slides[selectedSlideIndex][field] = value;
        }

        function moveSlide(index, direction) {
            const newIndex = index + direction;
            if (newIndex < 0 || newIndex >= screensData.slides.length) return;
            const temp = screensData.slides[index];
            screensData.slides[index] = screensData.slides[newIndex];
            screensData.slides[newIndex] = temp;
            if (selectedSlideIndex === index) selectedSlideIndex = newIndex;
            else if (selectedSlideIndex === newIndex) selectedSlideIndex = index;
            renderSlideStrip();
            renderSlideEditor();
        }

        function addSlide() {
            const newId = 'slide_' + Date.now();
            screensData.slides.push({
                id: newId,
                icon: 'fa-star',
                icon_animation: 'none',
                text_en: '',
                text_de: '',
                cast: []
            });
            selectSlide(screensData.slides.length - 1);
        }

        async function deleteSlide(index) {
            if (screensData.slides.length <= 1) {
                showToast('You must have at least one slide.', 'warning');
                return;
            }
            const confirmed = await showConfirmationDialog('Delete this slide? This cannot be undone.');
            if (!confirmed) return;
            screensData.slides.splice(index, 1);
            if (selectedSlideIndex >= screensData.slides.length) {
                selectedSlideIndex = screensData.slides.length - 1;
            }
            renderSlideStrip();
            renderSlideEditor();
        }

        function toggleCast(enabled) {
            if (selectedSlideIndex < 0) return;
            const slide = screensData.slides[selectedSlideIndex];
            if (enabled && (!slide.cast || slide.cast.length === 0)) {
                slide.cast = [{ name: '', image: '' }];
            } else if (!enabled) {
                slide.cast = [];
            }
            renderSlideEditor();
        }

        function addCastMember() {
            if (selectedSlideIndex < 0) return;
            const slide = screensData.slides[selectedSlideIndex];
            if (!slide.cast) slide.cast = [];
            slide.cast.push({ name: '', image: '' });
            renderSlideEditor();
        }

        function removeCastMember(castIndex) {
            if (selectedSlideIndex < 0) return;
            screensData.slides[selectedSlideIndex].cast.splice(castIndex, 1);
            renderSlideEditor();
        }

        function updateCastField(castIndex, field, value) {
            if (selectedSlideIndex < 0) return;
            screensData.slides[selectedSlideIndex].cast[castIndex][field] = value;
        }

        async function uploadCastMemberImage(castIndex, input) {
            const file = input.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('file', file);

            try {
                const response = await fetch('api.php?action=upload_cast_image', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: formData
                });
                const data = await response.json();
                if (data.status === 'success' && data.filename) {
                    screensData.slides[selectedSlideIndex].cast[castIndex].image = data.filename;
                    renderSlideEditor();
                    showToast('Cast image uploaded!');
                } else {
                    showToast('Error: ' + (data.message || 'Upload failed'), 'error');
                }
            } catch (err) {
                showToast('Network error uploading image', 'error');
            }
        }

        async function saveScreens() {
            screensData.language_mode = document.getElementById('screenLanguageMode').value;

            try {
                const response = await fetch('api.php?action=save_screens', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ screens: screensData })
                });
                const data = await response.json();
                if (data.status === 'success') {
                    showToast('Screen configuration saved!');
                } else {
                    showToast('Error: ' + (data.message || 'Save failed'), 'error');
                }
            } catch (err) {
                showToast('Network error saving screens', 'error');
            }
        }

        // Initialize screens when section is shown
        document.querySelector('aside a[data-section="screens"]').addEventListener('click', () => {
            setTimeout(loadScreensData, 200);
        });
        if (localStorage.getItem('currentAdminSection') === 'screens') {
            setTimeout(loadScreensData, 200);
        }

        async function savePaymentSettings() {
            const publishableKey = document.getElementById('stripe-pub-key').value.trim();
            const secretKey = document.getElementById('stripe-secret-key').value.trim();
            const webhookSecret = document.getElementById('stripe-webhook-secret').value.trim();

            const payload = { publishable_key: publishableKey };
            if (secretKey !== '') payload.secret_key = secretKey;
            if (webhookSecret !== '') payload.webhook_secret = webhookSecret;

            try {
                const response = await fetch('api.php?action=save_payment_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();
                if (data.status === 'success') {
                    showToast('Payment settings saved!');
                    // Clear sensitive fields after save
                    document.getElementById('stripe-secret-key').value = '';
                    document.getElementById('stripe-webhook-secret').value = '';
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('Error: ' + (data.message || 'Save failed'), 'error');
                }
            } catch (err) {
                showToast('Network error saving payment settings', 'error');
            }
        }

        // ---- Account management ----
        function accProxy(endpoint, payload) {
            return fetch('admin-api-proxy.php?endpoint=' + endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                body: JSON.stringify(payload || {})
            }).then(r => r.json());
        }

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        async function loadAccounts() {
            const body = document.getElementById('accountsTableBody');
            if (!body) return;
            try {
                const data = await fetch('admin-api-proxy.php?endpoint=users_list').then(r => r.json());
                const users = (data && data.users) || [];
                if (!users.length) {
                    body.innerHTML = '<tr><td colspan="5" class="text-muted-foreground">Keine Konten.</td></tr>';
                    return;
                }
                body.innerHTML = '';
                users.forEach(u => {
                    const tr = document.createElement('tr');

                    const tdName = document.createElement('td');
                    tdName.textContent = u.username;
                    tr.appendChild(tdName);

                    // Permission checkboxes — listeners bound directly (no inline
                    // onclick strings, which broke on usernames/quotes).
                    [['can_admin', u.can_admin], ['can_ticketflow', u.can_ticketflow], ['can_handheld', u.can_handheld]]
                        .forEach(([perm, on]) => {
                            const td = document.createElement('td');
                            const cb = document.createElement('input');
                            cb.type = 'checkbox';
                            cb.checked = !!on;
                            cb.dataset.perm = perm;
                            cb.addEventListener('change', () => updateAccountPerm(u.username, tr));
                            td.appendChild(cb);
                            tr.appendChild(td);
                        });

                    const tdAct = document.createElement('td');
                    const btn = document.createElement('button');
                    btn.className = 'btn-icon-destructive';
                    btn.title = 'Löschen';
                    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
                    btn.addEventListener('click', () => deleteAccount(u.username));
                    tdAct.appendChild(btn);
                    tr.appendChild(tdAct);

                    body.appendChild(tr);
                });
            } catch (e) {
                body.innerHTML = '<tr><td colspan="5" style="color:var(--avo-error)">Fehler beim Laden.</td></tr>';
            }
        }

        async function createAccount() {
            const username = document.getElementById('acc-username').value.trim();
            const password = document.getElementById('acc-password').value;
            if (!username || password.length < 6) {
                showToast('Benutzername und Passwort (min. 6 Zeichen) erforderlich', 'error');
                return;
            }
            const data = await accProxy('users_create', {
                username, password,
                can_admin: document.getElementById('acc-admin').checked,
                can_ticketflow: document.getElementById('acc-ticketflow').checked,
                can_handheld: document.getElementById('acc-handheld').checked,
            });
            if (data && data.status === 'success') {
                showToast('Konto angelegt');
                document.getElementById('createAccountForm').reset();
                loadAccounts();
            } else {
                showToast('Fehler: ' + ((data && (data.message || data.error)) || 'unbekannt'), 'error');
            }
        }

        async function updateAccountPerm(username, row) {
            const get = perm => {
                const cb = row.querySelector('input[data-perm="' + perm + '"]');
                return cb ? cb.checked : false;
            };
            const data = await accProxy('users_update', {
                username,
                can_admin: get('can_admin'),
                can_ticketflow: get('can_ticketflow'),
                can_handheld: get('can_handheld'),
            });
            if (data && data.status === 'success') {
                showToast('Zugänge aktualisiert');
            } else {
                showToast('Fehler: ' + ((data && (data.message || data.error)) || 'unbekannt'), 'error');
                loadAccounts();
            }
        }

        async function deleteAccount(username) {
            if (!confirm('Konto "' + username + '" wirklich löschen?')) return;
            const data = await accProxy('users_delete', { username });
            if (data && data.status === 'success') {
                showToast('Konto gelöscht');
                loadAccounts();
            } else {
                showToast('Fehler: ' + ((data && (data.message || data.error)) || 'unbekannt'), 'error');
            }
        }

        document.querySelector('aside a[data-section="accounts"]').addEventListener('click', () => {
            setTimeout(loadAccounts, 200);
        });
        if (localStorage.getItem('currentAdminSection') === 'accounts') {
            setTimeout(loadAccounts, 200);
        }
    </script>
</body>

</html>