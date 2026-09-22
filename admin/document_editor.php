<?php
// admin/document_editor.php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
$pageTitle = 'Document Template Designer';
require_once 'includes/header.php';

if (!canEdit()) {
    echo '<style>
      .word-ribbon, .designer-sidebar, .canvas-container button, .designer-container select, .designer-container input, .designer-container textarea {
          pointer-events: none !important;
          opacity: 0.65 !important;
          cursor: not-allowed !important;
      }
      .modal-close, .modal button[data-bs-dismiss="modal"] {
          pointer-events: auto !important;
          opacity: 1 !important;
          cursor: pointer !important;
      }
    </style>';
}
?>

<!-- Premium Compact MS Word-Style Ribbon Toolbar CSS -->
<style>
.designer-container {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 75px);
    background: #111111;
    color: #ffffff;
    border: 1px solid #333;
    border-radius: 8px;
    overflow: hidden;
    font-family: 'Inter', sans-serif;
}

/* MS Word-like Ribbon Toolbar */
.word-ribbon {
    background: #181818;
    border-bottom: 2px solid #2a2a2a;
    padding: 6px 12px;
    display: flex;
    flex-wrap: nowrap;
    overflow-x: auto;
    gap: 14px;
    align-items: stretch;
    user-select: none;
    z-index: 105;
    height: 88px;
    box-sizing: border-box;
}

.ribbon-group {
    display: flex;
    flex-direction: column;
    border-right: 1px solid #2a2a2a;
    padding-right: 14px;
    gap: 3px;
    flex-shrink: 0;
    justify-content: space-between;
}

.ribbon-group:last-child {
    border-right: none;
    padding-right: 0;
}

.ribbon-group-title {
    font-size: 9px;
    font-weight: 700;
    color: var(--gold-400);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    line-height: 1;
    margin-bottom: 2px;
}

.ribbon-row {
    display: flex;
    align-items: center;
    gap: 4px;
}

.ribbon-btn {
    background: #242424;
    color: #fff;
    border: 1px solid #3a3a3a;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all 0.2s;
    height: 24px;
    box-sizing: border-box;
}

.ribbon-btn:hover:not(:disabled) {
    background: #2e2e2e;
    border-color: var(--gold-500);
    color: var(--gold-400);
}

.ribbon-btn.active {
    background: var(--gold-500);
    color: #fff;
    border-color: var(--gold-500);
}

.ribbon-btn:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.ribbon-select {
    background: #242424;
    color: #fff;
    border: 1px solid #3a3a3a;
    border-radius: 4px;
    padding: 2px 6px;
    font-size: 11px;
    height: 24px;
    box-sizing: border-box;
}

.ribbon-select:focus {
    border-color: var(--gold-500);
    outline: none;
}

.ribbon-input {
    background: #242424;
    color: #fff;
    border: 1px solid #3a3a3a;
    border-radius: 4px;
    padding: 2px 4px;
    font-size: 11px;
    height: 24px;
    width: 50px;
    box-sizing: border-box;
}

.ribbon-input:focus {
    border-color: var(--gold-500);
    outline: none;
}

.ribbon-color-picker {
    background: #242424;
    border: 1px solid #3a3a3a;
    padding: 1px;
    width: 24px;
    height: 24px;
    border-radius: 4px;
    cursor: pointer;
    box-sizing: border-box;
}

/* Workspace Area */
.designer-workspace {
    flex: 1;
    background: #202020;
    overflow: auto;
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 15px;
}

/* Canvas Design Representation */
.canvas-outer {
    background: #ffffff;
    box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    position: relative;
    box-sizing: border-box;
    transform-origin: top center;
    transition: transform 0.1s ease-out;
    flex-shrink: 0; /* CRITICAL: Prevents Flexbox from shrinking A4 dimensions visually */
}

.canvas-background-layer {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-size: cover;
    background-position: center;
    z-index: 1;
    pointer-events: none;
}

/* Draggable Elements */
.canvas-element {
    position: absolute;
    z-index: 5;
    box-sizing: border-box;
    cursor: move;
    user-select: none;
    border: 1px dashed transparent;
}

.canvas-element.selected {
    border: 1px solid var(--gold-500);
}

.canvas-element-content {
    width: 100%;
    height: 100%;
    overflow: hidden;
    word-break: break-word;
    display: flex;
    align-items: center;
}

.canvas-element img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    pointer-events: none;
}

/* Resize Handles */
.resize-handle {
    position: absolute;
    width: 8px;
    height: 8px;
    background: var(--gold-500);
    border: 1px solid #fff;
    border-radius: 50%;
    z-index: 10;
    display: none;
}

.canvas-element.selected .resize-handle {
    display: block;
}

.handle-tl { top: -4px; left: -4px; cursor: nwse-resize; }
.handle-tr { top: -4px; right: -4px; cursor: nesw-resize; }
.handle-bl { bottom: -4px; left: -4px; cursor: nesw-resize; }
.handle-br { bottom: -4px; right: -4px; cursor: nwse-resize; }
.handle-t  { top: -4px; left: 50%; transform: translateX(-50%); cursor: ns-resize; }
.handle-b  { bottom: -4px; left: 50%; transform: translateX(-50%); cursor: ns-resize; }
.handle-l  { top: 50%; left: -4px; transform: translateY(-50%); cursor: ew-resize; }
.handle-r  { top: 50%; right: -4px; transform: translateY(-50%); cursor: ew-resize; }

.vertical-divider {
    border-left: 1px solid #2a2a2a;
    height: 24px;
    align-self: center;
    margin: 0 2px;
}
/* Custom Scrollbars to minimize width and height */
::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
::-webkit-scrollbar-track {
    background: #111111;
}
::-webkit-scrollbar-thumb {
    background: #333333;
    border-radius: 3px;
}
::-webkit-scrollbar-thumb:hover {
    background: var(--gold-500);
}
.selected-cell {
    outline: 2px solid var(--gold-500) !important;
    outline-offset: -2px;
    position: relative;
    box-shadow: inset 0 0 4px rgba(255, 255, 255, 0.4);
}
</style>

<div class="admin-page-header" style="margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center;">
    <div class="admin-page-title">Document Template Designer</div>
    <button class="btn" onclick="saveTemplate()" style="background: var(--gold-500); color: #fff; font-weight: 700; border: none; padding: 10px 20px; border-radius: 6px; font-family: 'Rajdhani', sans-serif; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(255, 255, 255, 0.2);" onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 16px rgba(255, 255, 255, 0.3)';" onmouseout="this.style.transform='none'; this.style.boxShadow='0 4px 12px rgba(255, 255, 255, 0.2)';">💾 Save Template</button>
</div>

<div class="designer-container">
    
    <!-- Top Horizontal Ribbon Toolbar -->
    <div class="word-ribbon">
        
        <!-- Setup Group -->
        <div class="ribbon-group">
            <span class="ribbon-group-title">Page Setup</span>
            <div class="ribbon-row">
                <select id="doc-type-select" class="ribbon-select" onchange="loadTemplate(this.value)" title="Document Type">
                    <option value="certificate">Certificate</option>
                    <option value="competitor_card">Competitor Card</option>
                    <option value="start_sheet">Start List</option>
                    <option value="score_sheet">Score Sheet - General Default</option>
                    <option value="score_sheet_air_rifle_pistol">Score Sheet - Air Rifle/Pistol</option>
                    <option value="score_sheet_centre_fire_pistol">Score Sheet - Centre Fire Pistol</option>
                    <option value="score_sheet_rifle_prone">Score Sheet - Rifle Prone</option>
                    <option value="score_sheet_standard_pistol">Score Sheet - Standard Pistol</option>
                    <option value="rank_list">Rank List</option>
                    <option value="print_summary">Print Summary (Lane Allocation)</option>
                </select>
                <select id="page-orientation" class="ribbon-select" onchange="updateOrientation()" title="Orientation">
                    <option value="portrait">Portrait</option>
                    <option value="landscape">Landscape</option>
                </select>
            </div>
            <div class="ribbon-row">
                <input type="number" id="page-width" class="ribbon-input" onchange="updatePageSize()" title="Width (px)" placeholder="W">
                <input type="number" id="page-height" class="ribbon-input" onchange="updatePageSize()" title="Height (px)" placeholder="H">
                <label class="ribbon-btn" style="cursor:pointer; background:#292010; border-color:#503e1e; color:#ddb25e;">
                    🌅 Bg Image
                    <input type="file" id="bg-uploader" style="display:none;" accept="image/*" onchange="uploadBackground(this)">
                </label>
                <input type="color" id="page-bg-color" class="ribbon-color-picker" title="Page Background Color" oninput="updatePageBgColor(this.value)">
            </div>
        </div>

        <!-- Page Border setup -->
        <div class="ribbon-group">
            <span class="ribbon-group-title">Page Border</span>
            <div class="ribbon-row">
                <select id="page-border-style" class="ribbon-select" onchange="updatePageBorder()" title="Page Border Style">
                    <option value="none">None</option>
                    <option value="solid">Solid</option>
                    <option value="dashed">Dashed</option>
                    <option value="double">Double</option>
                </select>
                <input type="color" id="page-border-color" class="ribbon-color-picker" title="Border Color" oninput="updatePageBorder()">
            </div>
            <div class="ribbon-row">
                <input type="number" id="page-border-width" class="ribbon-input" oninput="updatePageBorder()" title="Border Width" placeholder="W" style="width: 40px;">
                <input type="number" id="page-border-radius" class="ribbon-input" oninput="updatePageBorder()" title="Corner Radius" placeholder="R" style="width: 40px;">
                <input type="number" id="page-border-inset" class="ribbon-input" oninput="updatePageBorder()" title="Border Inset Margin (px)" placeholder="Inset" style="width: 45px;">
            </div>
        </div>

        <!-- Insert Elements Group -->
        <div class="ribbon-group">
            <span class="ribbon-group-title">Insert</span>
            <div class="ribbon-row">
                <button class="ribbon-btn" onclick="addNewElement('text', 'Enter Text')">➕ Text Box</button>
                <button class="ribbon-btn" onclick="addNewElement('photo')">📷 Photo</button>
                <button class="ribbon-btn" onclick="insertESignBox('shooter')">✍️ Shooter E-Sign</button>
            </div>
            <div class="ribbon-row">
                <button class="ribbon-btn" onclick="addNewElement('table')">📊 Table</button>
                <button class="ribbon-btn" onclick="addNewElement('line')">➖ Line</button>
                <button class="ribbon-btn" onclick="insertESignBox('official')">✍️ Official E-Sign</button>
                <label class="ribbon-btn" style="cursor:pointer; background:#292010; border-color:#503e1e; color:#ddb25e;">
                    ✍️ Upload E-Sign
                    <input type="file" id="esign-uploader" style="display:none;" accept="image/*" onchange="uploadESignature(this)">
                </label>
                <label class="ribbon-btn" style="cursor:pointer;">
                    🖼️ Logo
                    <input type="file" id="logo-uploader" style="display:none;" accept="image/*" onchange="uploadLogo(this)">
                </label>
            </div>
        </div>

        <!-- Dynamic Fields Selector -->
        <div class="ribbon-group">
            <span class="ribbon-group-title">Dynamic Fields</span>
            <div class="ribbon-row">
                <select id="ribbon-dynamic-fields" class="ribbon-select" onchange="if(this.value) { insertDynamicPlaceholder(this.value); this.value=''; }" title="Insert Dynamic Tags" style="max-width: 220px;">
                    <option value="">-- Insert Dynamic Field --</option>

                    <optgroup label="Athlete & Participant Info">
                        <option value="{participant_name}">Athlete Name</option>
                        <option value="{reg_id}">Enrollment / Reg ID</option>
                        <option value="{competitor_no}">Competitor / Bib No</option>
                        <option value="{club_name}">Club Name</option>
                        <option value="{district}">District</option>
                        <option value="{association}">State Association</option>
                    </optgroup>

                    <optgroup label="Event & Detail Info">
                        <option value="{event_name}">Event Name</option>
                        <option value="{category}">Category (NR/ISSF)</option>
                        <option value="{date}">Scheduled Date</option>
                        <option value="{time}">Reporting / Start Time</option>
                        <option value="{relay}">Detail / Relay No</option>
                        <option value="{lane}">FP / Target Lane No</option>
                        <option value="{card}">Card Number</option>
                        <option value="{match}">Match Number</option>
                        <option value="{rank}">Rank Position</option>
                        <option value="{cert_no}">Certificate Number</option>
                    </optgroup>

                    <optgroup label="Score Sheet Totals">
                        <option value="{total}">Sub-Total Score</option>
                        <option value="{penalty}">Penalty Deductions</option>
                        <option value="{grand_total}">Grand Total Score</option>
                        <option value="{precision_total}">Precision Stage Total</option>
                        <option value="{duelling_total}">Rapid Fire / Duelling Total</option>
                        <option value="{score}">Final Score</option>
                    </optgroup>

                    <optgroup label="Score Sheet Series Totals">
                        <option value="{s1_total}">Series 1 Total</option>
                        <option value="{s2_total}">Series 2 Total</option>
                        <option value="{s3_total}">Series 3 Total</option>
                        <option value="{s4_total}">Series 4 Total</option>
                        <option value="{s5_total}">Series 5 Total</option>
                        <option value="{s6_total}">Series 6 Total</option>
                        <option value="{s7_total}">Series 7 Total</option>
                        <option value="{s8_total}">Series 8 Total</option>
                        <option value="{s9_total}">Series 9 Total</option>
                        <option value="{s10_total}">Series 10 Total</option>
                        <option value="{s11_total}">Series 11 Total</option>
                        <option value="{s12_total}">Series 12 Total</option>
                    </optgroup>

                    <optgroup label="Score Sheet Series 1 Shots">
                        <option value="{s1_1}">Series 1 - Shot 1</option>
                        <option value="{s1_2}">Series 1 - Shot 2</option>
                        <option value="{s1_3}">Series 1 - Shot 3</option>
                        <option value="{s1_4}">Series 1 - Shot 4</option>
                        <option value="{s1_5}">Series 1 - Shot 5</option>
                        <option value="{s1_6}">Series 1 - Shot 6</option>
                        <option value="{s1_7}">Series 1 - Shot 7</option>
                        <option value="{s1_8}">Series 1 - Shot 8</option>
                        <option value="{s1_9}">Series 1 - Shot 9</option>
                        <option value="{s1_10}">Series 1 - Shot 10</option>
                    </optgroup>

                    <optgroup label="Score Sheet Series 2 Shots">
                        <option value="{s2_1}">Series 2 - Shot 1</option>
                        <option value="{s2_2}">Series 2 - Shot 2</option>
                        <option value="{s2_3}">Series 2 - Shot 3</option>
                        <option value="{s2_4}">Series 2 - Shot 4</option>
                        <option value="{s2_5}">Series 2 - Shot 5</option>
                        <option value="{s2_6}">Series 2 - Shot 6</option>
                        <option value="{s2_7}">Series 2 - Shot 7</option>
                        <option value="{s2_8}">Series 2 - Shot 8</option>
                        <option value="{s2_9}">Series 2 - Shot 9</option>
                        <option value="{s2_10}">Series 2 - Shot 10</option>
                    </optgroup>

                    <optgroup label="Score Sheet Series 3 Shots">
                        <option value="{s3_1}">Series 3 - Shot 1</option>
                        <option value="{s3_2}">Series 3 - Shot 2</option>
                        <option value="{s3_3}">Series 3 - Shot 3</option>
                        <option value="{s3_4}">Series 3 - Shot 4</option>
                        <option value="{s3_5}">Series 3 - Shot 5</option>
                        <option value="{s3_6}">Series 3 - Shot 6</option>
                        <option value="{s3_7}">Series 3 - Shot 7</option>
                        <option value="{s3_8}">Series 3 - Shot 8</option>
                        <option value="{s3_9}">Series 3 - Shot 9</option>
                        <option value="{s3_10}">Series 3 - Shot 10</option>
                    </optgroup>

                    <optgroup label="Score Sheet Series 4 Shots">
                        <option value="{s4_1}">Series 4 - Shot 1</option>
                        <option value="{s4_2}">Series 4 - Shot 2</option>
                        <option value="{s4_3}">Series 4 - Shot 3</option>
                        <option value="{s4_4}">Series 4 - Shot 4</option>
                        <option value="{s4_5}">Series 4 - Shot 5</option>
                        <option value="{s4_6}">Series 4 - Shot 6</option>
                        <option value="{s4_7}">Series 4 - Shot 7</option>
                        <option value="{s4_8}">Series 4 - Shot 8</option>
                        <option value="{s4_9}">Series 4 - Shot 9</option>
                        <option value="{s4_10}">Series 4 - Shot 10</option>
                    </optgroup>

                    <optgroup label="Score Sheet Series 5 Shots">
                        <option value="{s5_1}">Series 5 - Shot 1</option>
                        <option value="{s5_2}">Series 5 - Shot 2</option>
                        <option value="{s5_3}">Series 5 - Shot 3</option>
                        <option value="{s5_4}">Series 5 - Shot 4</option>
                        <option value="{s5_5}">Series 5 - Shot 5</option>
                        <option value="{s5_6}">Series 5 - Shot 6</option>
                        <option value="{s5_7}">Series 5 - Shot 7</option>
                        <option value="{s5_8}">Series 5 - Shot 8</option>
                        <option value="{s5_9}">Series 5 - Shot 9</option>
                        <option value="{s5_10}">Series 5 - Shot 10</option>
                    </optgroup>

                    <optgroup label="Score Sheet Series 6 Shots">
                        <option value="{s6_1}">Series 6 - Shot 1</option>
                        <option value="{s6_2}">Series 6 - Shot 2</option>
                        <option value="{s6_3}">Series 6 - Shot 3</option>
                        <option value="{s6_4}">Series 6 - Shot 4</option>
                        <option value="{s6_5}">Series 6 - Shot 5</option>
                        <option value="{s6_6}">Series 6 - Shot 6</option>
                        <option value="{s6_7}">Series 6 - Shot 7</option>
                        <option value="{s6_8}">Series 6 - Shot 8</option>
                        <option value="{s6_9}">Series 6 - Shot 9</option>
                        <option value="{s6_10}">Series 6 - Shot 10</option>
                    </optgroup>

                    <optgroup label="Signatures & Official Fields">
                        <option value="{shooter_signature}">Shooter Signature</option>
                        <option value="{range_officer_signature}">Range Officer Signature</option>
                        <option value="{target_officer_signature}">Target Officer Signature</option>
                    </optgroup>
                </select>
            </div>
            <div class="ribbon-row">
                <span style="font-size:10px; color:#777;">Click to insert in textbox</span>
            </div>
        </div>

        <!-- Selection pane dropdown -->
        <div class="ribbon-group">
            <span class="ribbon-group-title">Selection</span>
            <div class="ribbon-row">
                <select id="ribbon-element-selector" class="ribbon-select" style="min-width: 130px;" onchange="if(this.value) selectElement(this.value)" title="Active Elements list">
                    <option value="">-- Select Element --</option>
                </select>
            </div>
            <div class="ribbon-row">
                <span id="coords-display" style="font-size:10px; color:var(--gold-400); font-family:monospace; line-height:1;">X: 0, Y: 0</span>
            </div>
        </div>

        <!-- Style Painter Group -->
        <div class="ribbon-group" id="ribbon-painter-tools">
            <span class="ribbon-group-title">Style Painter</span>
            <div class="ribbon-row">
                <button class="ribbon-btn" id="btn-copy-style" onclick="copyElementStyle()" title="Copy style of selected element" disabled>📋 Copy Style</button>
            </div>
            <div class="ribbon-row">
                <button class="ribbon-btn" id="btn-paste-style" onclick="pasteElementStyle()" title="Paste style to selected element" disabled>🖌️ Paste Style</button>
            </div>
        </div>

        <!-- Formatting Tools (For Selected Element - Contextual) -->
        <div class="ribbon-group" id="ribbon-text-tools" style="display: none;">
            <span class="ribbon-group-title">Formatting</span>
            <div class="ribbon-row">
                <select id="elem-font-family" class="ribbon-select" onchange="updateActiveElementProps()">
                    <option value="Inter">Inter</option>
                    <option value="Cinzel">Cinzel</option>
                    <option value="Rajdhani">Rajdhani</option>
                    <option value="'Playfair Display'">Playfair</option>
                    <option value="Arial">Arial</option>
                </select>
                <input type="number" id="elem-font-size" class="ribbon-input" oninput="updateActiveElementProps()" title="Font size" placeholder="Size" style="width: 45px;">
                <button class="ribbon-btn" id="toggle-bold" onclick="toggleTextStyle('bold')" style="font-weight:700;">B</button>
                <button class="ribbon-btn" id="toggle-italic" onclick="toggleTextStyle('italic')" style="font-style:italic;">I</button>
            </div>
            <div class="ribbon-row">
                <input type="color" id="elem-color" class="ribbon-color-picker" title="Text Color" oninput="updateActiveElementProps()">
                <input type="color" id="elem-bg-color" class="ribbon-color-picker" title="Text Bg Color" oninput="document.getElementById('elem-bg-none').checked = false; document.getElementById('elem-bg-color').disabled = false; updateActiveElementProps()">
                <label style="font-size:10px; display:inline-flex; align-items:center; gap:2px; cursor:pointer;" title="Transparent Background">
                    <input type="checkbox" id="elem-bg-none" onchange="toggleBgNone()"> NoneBg
                </label>
                <div class="vertical-divider"></div>
                <button class="ribbon-btn" id="toggle-align-left" onclick="toggleTextAlign('left')">⬅️</button>
                <button class="ribbon-btn" id="toggle-align-center" onclick="toggleTextAlign('center')">🔲</button>
                <button class="ribbon-btn" id="toggle-align-right" onclick="toggleTextAlign('right')">➡️</button>
            </div>
        </div>

        <!-- Table Setup Group (For Selected Table Element - Contextual) -->
        <div class="ribbon-group" id="ribbon-table-tools" style="display: none;">
            <span class="ribbon-group-title">Table Setup</span>
            <div class="ribbon-row">
                <input type="text" id="elem-table-headers" class="ribbon-select" style="width: 150px;" oninput="updateTableHeaders()" title="Column Headers (comma-separated)" placeholder="Col1, Col2...">
                <select id="elem-table-grid" class="ribbon-select" onchange="updateTableGridStyle()" title="Table Grid Style">
                    <option value="full">Full Grid</option>
                    <option value="horizontal">Horizontal</option>
                    <option value="vertical">Vertical</option>
                    <option value="outer">Outer Only</option>
                    <option value="none">No Grid</option>
                </select>
            </div>
            <div class="ribbon-row">
                <button class="ribbon-btn" onclick="addTableRow()">➕ Row</button>
                <button class="ribbon-btn" onclick="addTableColumn()">➕ Col</button>
                <button class="ribbon-btn" onclick="deleteTableRow()" style="color:#ff6b6b;">➖ Row</button>
                <button class="ribbon-btn" onclick="deleteTableColumn()" style="color:#ff6b6b;">➖ Col</button>
                <div class="vertical-divider"></div>
                <input type="color" id="elem-table-bg-color" class="ribbon-color-picker" title="Table Background Color" oninput="updateTableBgColor(this.value)">
            </div>
        </div>

        <!-- Advanced Table Actions (Contextual) -->
        <div class="ribbon-group" id="ribbon-table-actions" style="display: none;">
            <span class="ribbon-group-title">Table Cells & Spans</span>
            <div class="ribbon-row">
                <button class="ribbon-btn" onclick="insertTableRowAbove()" title="Insert Row Above">🔼 Row Above</button>
                <button class="ribbon-btn" onclick="insertTableRowBelow()" title="Insert Row Below">🔽 Row Below</button>
                <button class="ribbon-btn" onclick="insertTableColumnLeft()" title="Insert Column Left">⬅️ Col Left</button>
                <button class="ribbon-btn" onclick="insertTableColumnRight()" title="Insert Column Right">➡️ Col Right</button>
            </div>
            <div class="ribbon-row">
                <button class="ribbon-btn" onclick="mergeCellRight()" title="Merge Cell with Right Neighbor" id="btn-merge-right" disabled>🔗 Merge Right</button>
                <button class="ribbon-btn" onclick="mergeCellDown()" title="Merge Cell with Bottom Neighbor" id="btn-merge-down" disabled>🔗 Merge Down</button>
                <button class="ribbon-btn" onclick="splitSelectedCell()" title="Split Selected Merged Cell" id="btn-split-cell" disabled>🪓 Split Cell</button>
                <div class="vertical-divider"></div>
                <input type="color" id="elem-cell-bg-color" class="ribbon-color-picker" title="Cell Background Color" oninput="paintCellBg(this.value)" disabled>
                <button class="ribbon-btn" onclick="paintRowBg()" title="Paint Entire Row Background" id="btn-paint-row" style="padding: 2px 4px; font-size:10px;" disabled>🎨 Row</button>
                <button class="ribbon-btn" onclick="paintColBg()" title="Paint Entire Column Background" id="btn-paint-col" style="padding: 2px 4px; font-size:10px;" disabled>🎨 Col</button>
            </div>
        </div>

        <!-- Borders & Z-Index Group (Contextual) -->
        <div class="ribbon-group" id="ribbon-border-tools" style="display: none;">
            <span class="ribbon-group-title">Borders & Layers</span>
            <div class="ribbon-row">
                <select id="elem-border-style" class="ribbon-select" onchange="updateActiveElementProps()" title="Border Style">
                    <option value="none">None</option>
                    <option value="solid">Solid</option>
                    <option value="dashed">Dashed</option>
                    <option value="double">Double</option>
                </select>
                <input type="color" id="elem-border-color" class="ribbon-color-picker" title="Border Color" oninput="updateActiveElementProps()">
                <input type="number" id="elem-border-width" class="ribbon-input" oninput="updateActiveElementProps()" title="Border Width" placeholder="W" style="width: 40px;">
                <input type="number" id="elem-border-radius" class="ribbon-input" oninput="updateActiveElementProps()" title="Radius" placeholder="R" style="width: 40px;">
            </div>
            <div class="ribbon-row">
                <input type="number" id="elem-z" class="ribbon-input" oninput="updateActiveElementProps()" title="Z-Index" placeholder="Z" style="width: 40px;">
                <button class="ribbon-btn" onclick="arrangeLayer('front')" title="Bring to Front">🔼 Front</button>
                <button class="ribbon-btn" onclick="arrangeLayer('back')" title="Send to Back">🔽 Back</button>
            </div>
        </div>

        <!-- Actions, Zoom & Save -->
        <div class="ribbon-group">
            <span class="ribbon-group-title">Actions & Saving</span>
            <div class="ribbon-row">
                <button class="ribbon-btn" onclick="undo()" id="btn-undo" title="Undo"><i class="bi bi-arrow-counterclockwise"></i></button>
                <button class="ribbon-btn" onclick="redo()" id="btn-redo" title="Redo"><i class="bi bi-arrow-clockwise"></i></button>
                <button class="ribbon-btn" onclick="duplicateSelected()" id="btn-duplicate" style="color:var(--gold-400);"><i class="bi bi-copy"></i> Dupl</button>
                <button class="ribbon-btn" onclick="deleteSelected()" id="btn-delete" style="color:#ff6b6b;"><i class="bi bi-trash-fill"></i> Del</button>
            </div>
            <div class="ribbon-row">
                <select class="zoom-select ribbon-select" id="zoom-select" onchange="setZoom(this.value)">
                    <option value="0.4">40%</option>
                    <option value="0.5">50%</option>
                    <option value="0.75" selected>75%</option>
                    <option value="1.0">100%</option>
                    <option value="1.25">125%</option>
                    <option value="1.5">150%</option>
                </select>
                <button class="ribbon-btn" onclick="fitToScreen()">🔍 Fit</button>
            </div>
        </div>

    </div>

    <!-- Center: Main Workspace -->
    <div class="designer-workspace" id="workspace-container">
        
        <!-- Editable details dialog when element selected -->
        <div id="quick-text-editor" style="display:none; position:absolute; top:20px; right:20px; z-index:100; background:#181818; padding:10px; border:1px solid #333; border-radius:6px; width:260px;">
            <span style="font-size:11px; color:#aaa; display:block; margin-bottom:4px;">Edit Details:</span>
            <textarea id="elem-text" class="ribbon-input" style="width:100%; height:60px; font-family:inherit;" oninput="updateActiveElementProps()"></textarea>
            <div style="margin-top:6px; display:flex; justify-content:space-between; align-items:center;">
                <label style="font-size:11px; color:#aaa; cursor:pointer; display:flex; align-items:center; gap:4px;">
                    <input type="checkbox" id="elem-lock-ratio" onchange="updateActiveElementProps()"> Lock Aspect
                </label>
            </div>
        </div>

        <!-- Canvas Area -->
        <div class="canvas-outer" id="design-canvas" onclick="deselectAll(event)">
            <div class="canvas-background-layer" id="canvas-bg"></div>
            <!-- Placed Elements dynamically rendered -->
        </div>

    </div>

</div>

<!-- JS Implementation of Canva Canvas Engine -->
<script>
// Main state configuration
let state = {
    width: 794,
    height: 1122,
    orientation: 'portrait',
    background: null,
    elements: [],
    pageBorderStyle: 'none',
    pageBorderWidth: 0,
    pageBorderColor: '#000000',
    pageBorderRadius: 0,
    pageBorderInset: 0
};

// Undo / Redo histories
let historyStack = [];
let historyIndex = -1;

let zoomLevel = 0.75;
let activeElementId = null;
let copiedStyle = null;

// Drag & Resize Tracker
let dragTracker = {
    isDragging: false,
    isResizing: false,
    elemId: null,
    handle: null,
    startX: 0,
    startY: 0,
    startW: 0,
    startH: 0,
    startLeft: 0,
    startTop: 0
};

// Pre-load default template on launch
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    const initialType = urlParams.get('type') || 'certificate';
    const selectEl = document.getElementById('doc-type-select');
    if (selectEl) {
        selectEl.value = initialType;
    }
    loadTemplate(initialType);
});

// Load template from DB via API
async function loadTemplate(type) {
    deselectAll(null);
    try {
        const response = await fetch(`actions/get_template.php?document_type=${type}`);
        const data = await response.json();
        if (data.success) {
            state = data.canvas_data;
            if (!state.elements) state.elements = [];
        } else {
            // fallback defaults
            state = {
                width: 794,
                height: 1122,
                orientation: 'portrait',
                background: null,
                elements: [],
                pageBorderStyle: 'none',
                pageBorderWidth: 0,
                pageBorderColor: '#000000',
                pageBorderRadius: 0,
                pageBorderInset: 0
            };
        }
        initHistory();
        renderCanvas();
    } catch (e) {
        console.error(e);
        alert('Failed to load template.');
    }
}

// Save Template layout to DB
async function saveTemplate() {
    const type = document.getElementById('doc-type-select').value;
    const formData = new FormData();
    formData.append('document_type', type);
    formData.append('canvas_data', JSON.stringify(state));

    try {
        Swal.fire({
            title: 'Saving Template...',
            didOpen: () => { Swal.showLoading(); },
            background: '#1a1a1a',
            color: '#fff'
        });
        const res = await fetch('actions/save_template.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Saved!',
                text: 'Template changes saved and updated successfully.',
                background: '#1a1a1a',
                color: '#fff',
                confirmButtonColor: '#ADB5BD'
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Failed',
                text: data.message,
                background: '#1a1a1a',
                color: '#fff'
            });
        }
    } catch (e) {
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: 'An error occurred while saving.',
            background: '#1a1a1a',
            color: '#fff'
        });
    }
}

// Background uploader API integration
async function uploadBackground(input) {
    if (!input.files || !input.files[0]) return;
    const type = document.getElementById('doc-type-select').value;
    
    const formData = new FormData();
    formData.append('document_type', type);
    formData.append('background_image', input.files[0]);
    formData.append('canvas_data', JSON.stringify(state));

    try {
        Swal.fire({
            title: 'Uploading background...',
            didOpen: () => { Swal.showLoading(); },
            background: '#1a1a1a',
            color: '#fff'
        });
        
        const res = await fetch('actions/save_template.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            state.background = data.background_path;
            pushHistory();
            renderCanvas();
            Swal.close();
        } else {
            Swal.fire({ icon: 'error', title: 'Upload Failed', text: data.message, background: '#1a1a1a', color: '#fff' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Upload failed.', background: '#1a1a1a', color: '#fff' });
    }
}

// Logo uploader API integration
async function uploadLogo(input) {
    if (!input.files || !input.files[0]) return;
    const type = document.getElementById('doc-type-select').value;

    const formData = new FormData();
    formData.append('document_type', type);
    formData.append('logo_image', input.files[0]);
    formData.append('canvas_data', JSON.stringify(state));

    try {
        Swal.fire({
            title: 'Uploading logo...',
            didOpen: () => { Swal.showLoading(); },
            background: '#1a1a1a',
            color: '#fff'
        });

        const res = await fetch('actions/save_template.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            addNewElement('image', data.logo_path);
            Swal.close();
        } else {
            Swal.fire({ icon: 'error', title: 'Upload Failed', text: data.message, background: '#1a1a1a', color: '#fff' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Logo upload failed.', background: '#1a1a1a', color: '#fff' });
    }
}

// Add elements on canvas
function addNewElement(type, contentOrSrc = '') {
    let el = {
        id: type + '_' + Date.now(),
        type: type,
        x: 100,
        y: 100,
        width: type === 'photo' ? 120 : (type === 'table' ? 600 : (type === 'line' ? 600 : 250)),
        height: type === 'photo' ? 150 : (type === 'table' ? 120 : (type === 'line' ? 4 : 50)),
        zIndex: state.elements.length + 10,
        borderWidth: 0,
        borderColor: '#000000',
        borderStyle: 'none',
        borderRadius: 0,
        lockRatio: type === 'photo' || type === 'image'
    };

    if (type === 'text') {
        el.content = contentOrSrc || 'Double click to edit';
        el.fontSize = 16;
        el.fontFamily = 'Inter';
        el.color = '#000000';
        el.backgroundColor = 'transparent';
        el.fontWeight = 'normal';
        el.fontStyle = 'normal';
        el.textAlign = 'left';
    } else if (type === 'line') {
        el.color = '#000000';
        el.backgroundColor = '#000000';
    } else if (type === 'image') {
        el.src = contentOrSrc;
        el.width = 100;
        el.height = 100;
    } else if (type === 'photo') {
        el.borderWidth = 1;
        el.borderStyle = 'solid';
        el.borderColor = '#ADB5BD';
    } else if (type === 'table') {
        el.fontFamily = 'Inter';
        el.fontSize = 12;
        el.color = '#000000';
        el.borderWidth = 1;
        el.borderStyle = 'solid';
        el.borderColor = '#cccccc';
        el.headers = ['BIB', 'Shooter', 'S1', 'Total'];
        el.rows = [
            ['101', 'Competitor Alpha', '95', '195'],
            ['102', 'Competitor Beta', '92', '185']
        ];
    }

    state.elements.push(el);
    selectElement(el.id);
    pushHistory();
    renderCanvas();
}

// Duplicate dynamic selection
function duplicateSelected() {
    if (!activeElementId) return;
    const source = state.elements.find(e => e.id === activeElementId);
    if (!source) return;

    let copy = JSON.parse(JSON.stringify(source));
    copy.id = copy.type + '_' + Date.now();
    copy.x += 20;
    copy.y += 20;
    copy.zIndex = state.elements.length + 10;
    
    state.elements.push(copy);
    selectElement(copy.id);
    pushHistory();
    renderCanvas();
}

// Delete elements
function deleteSelected() {
    if (!activeElementId) return;
    state.elements = state.elements.filter(e => e.id !== activeElementId);
    deselectAll(null);
    pushHistory();
    renderCanvas();
}

// Render canvas with actual layout and styles
function renderCanvas() {
    const canvas = document.getElementById('design-canvas');
    canvas.style.width = state.width + 'px';
    canvas.style.height = state.height + 'px';

    const bgLayer = document.getElementById('canvas-bg');
    if (state.background) {
        if (state.background.startsWith('#') || state.background.startsWith('rgb')) {
            bgLayer.style.backgroundImage = 'none';
            bgLayer.style.backgroundColor = state.background;
        } else {
            bgLayer.style.backgroundImage = `url('../${state.background}')`;
        }
    } else {
        bgLayer.style.backgroundImage = 'none';
        bgLayer.style.backgroundColor = '#ffffff';
    }

    // Set page border styles
    const pStyle = state.pageBorderStyle || 'none';
    const pWidth = parseInt(state.pageBorderWidth) || 0;
    const pColor = state.pageBorderColor || '#000000';
    const pRadius = parseInt(state.pageBorderRadius) || 0;
    const pInset = parseInt(state.pageBorderInset) || 0;
    
    // Remove any existing page borders
    const oldBorders = canvas.querySelectorAll('.page-inset-border-render');
    oldBorders.forEach(b => b.remove());
    
    if (pStyle !== 'none' && pWidth > 0) {
        if (pInset > 0) {
            canvas.style.border = 'none';
            canvas.style.borderRadius = '0px';
            
            // Create inset border overlay div
            const borderOverlay = document.createElement('div');
            borderOverlay.className = 'page-inset-border-render';
            borderOverlay.style.position = 'absolute';
            borderOverlay.style.left = pInset + 'px';
            borderOverlay.style.top = pInset + 'px';
            borderOverlay.style.width = (state.width - (pInset * 2)) + 'px';
            borderOverlay.style.height = (state.height - (pInset * 2)) + 'px';
            borderOverlay.style.border = `${pWidth}px ${pStyle} ${pColor}`;
            borderOverlay.style.borderRadius = pRadius + 'px';
            borderOverlay.style.pointerEvents = 'none';
            borderOverlay.style.boxSizing = 'border-box';
            borderOverlay.style.zIndex = '2'; // sits right above background
            canvas.appendChild(borderOverlay);
        } else {
            canvas.style.border = `${pWidth}px ${pStyle} ${pColor}`;
            canvas.style.borderRadius = pRadius + 'px';
        }
    } else {
        canvas.style.border = 'none';
        canvas.style.borderRadius = '0px';
    }

    // Clear previous elements except the background layer and inset border
    const oldElems = canvas.querySelectorAll('.canvas-element');
    oldElems.forEach(e => e.remove());

    // Render each element
    state.elements.forEach(el => {
        const div = document.createElement('div');
        div.className = `canvas-element ${el.id === activeElementId ? 'selected' : ''}`;
        div.id = el.id;
        div.style.left = el.x + 'px';
        div.style.top = el.y + 'px';
        div.style.width = el.width + 'px';
        div.style.height = el.height + 'px';
        div.style.zIndex = el.zIndex;

        // Custom borders
        if (el.borderStyle !== 'none' && el.borderWidth > 0) {
            div.style.border = `${el.borderWidth}px ${el.borderStyle} ${el.borderColor}`;
        } else {
            div.style.border = 'none';
        }
        div.style.borderRadius = (el.borderRadius || 0) + 'px';

        // Element content structure
        const inner = document.createElement('div');
        inner.className = 'canvas-element-content';
        
        if (el.type === 'text') {
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.backgroundColor = el.backgroundColor || 'transparent';

            inner.innerHTML = el.content;
            inner.style.fontFamily = el.fontFamily || 'Inter';
            inner.style.fontSize = el.fontSize + 'px';
            inner.style.color = el.color;
            inner.style.backgroundColor = 'transparent';
            inner.style.fontWeight = el.fontWeight || 'normal';
            inner.style.fontStyle = el.fontStyle || 'normal';
            inner.style.textAlign = el.textAlign || 'left';
            
            inner.style.display = 'block';
            inner.style.height = 'auto';
            inner.style.width = '100%';
            inner.style.whiteSpace = 'pre-wrap';
            inner.style.lineHeight = '1.4';
            if (el.borderWidth && parseInt(el.borderWidth) > 0) {
                inner.style.padding = '6px 12px';
            } else {
                inner.style.padding = '0px';
            }
            
            if (el.id === activeElementId) {
                inner.contentEditable = "true";
                inner.style.outline = "none";
                inner.style.cursor = "text";
                
                inner.addEventListener('input', () => {
                    el.content = inner.innerHTML;
                    document.getElementById('elem-text').value = inner.innerHTML;
                });
            } else {
                inner.contentEditable = "false";
            }
        } else if (el.type === 'line') {
            inner.style.backgroundColor = el.backgroundColor || el.color || '#000000';
            inner.style.height = '100%';
            inner.style.width = '100%';
        } else if (el.type === 'image') {
            const src = el.src || '';
            if (src.includes('signature}') || src === '{shooter_signature}' || src === '{official_signature}') {
                const sigBox = document.createElement('div');
                sigBox.style.width = '100%';
                sigBox.style.height = '100%';
                sigBox.style.border = '1.5px dashed #c9a84c';
                sigBox.style.borderRadius = '4px';
                sigBox.style.background = 'rgba(201, 168, 76, 0.12)';
                sigBox.style.display = 'flex';
                sigBox.style.alignItems = 'center';
                sigBox.style.justifyContent = 'center';
                sigBox.style.color = '#c9a84c';
                sigBox.style.fontSize = '12px';
                sigBox.style.fontWeight = 'bold';
                sigBox.style.flexDirection = 'column';
                sigBox.style.gap = '2px';
                sigBox.innerHTML = `<span>✍️ E-Sign Placeholder</span><span style="font-size:10px; opacity:0.8;">${src}</span>`;
                inner.appendChild(sigBox);
            } else {
                const img = document.createElement('img');
                let realSrc = src;
                if (!src.startsWith('data:') && !src.startsWith('http://') && !src.startsWith('https://')) {
                    realSrc = '../' + src.replace(/^\/+/, '');
                }
                img.src = realSrc;
                img.style.maxWidth = '100%';
                img.style.maxHeight = '100%';
                img.style.objectFit = 'contain';
                img.style.width = '100%';
                img.style.height = '100%';
                inner.appendChild(img);
            }
        } else if (el.type === 'photo') {
            const img = document.createElement('img');
            img.src = '../images/gallery/ssa_action_1.jpg';
            img.style.objectFit = 'cover';
            img.style.width = '100%';
            img.style.height = '100%';
            inner.appendChild(img);
        } else if (el.type === 'table') {
            normalizeTable(el);
            const isDark = (el.color === '#ffffff' || el.color === '#fff' || el.color === '#ADB5BD' || el.color === '#ddb25e');
            const headerBg = isDark ? '#ADB5BD' : '#f4f4f4';
            const headerTextColor = isDark ? '#000000' : el.color;
            const rowBg = el.backgroundColor || (isDark ? '#16171e' : '#ffffff');

            inner.style.flexDirection = 'column';
            inner.style.justifyContent = 'flex-start';
            inner.style.background = rowBg;
            inner.style.fontFamily = el.fontFamily || 'Inter';
            inner.style.fontSize = el.fontSize + 'px';
            inner.style.color = el.color;
            
            const table = document.createElement('table');
            table.style.width = '100%';
            table.style.borderCollapse = 'collapse';
            table.style.height = '100%';
            
            const gStyle = el.gridStyle || 'full';
            const bStyle = el.borderStyle || 'solid';
            const bWidth = el.borderWidth || 1;
            const bColor = el.borderColor || '#cccccc';
            
            let cellStyle = 'border: 1px solid #cccccc;';
            if (bStyle !== 'none' && bWidth > 0) {
                if (gStyle === 'full') {
                    cellStyle = `border: ${bWidth}px ${bStyle} ${bColor};`;
                } else if (gStyle === 'horizontal') {
                    cellStyle = `border-top: none; border-bottom: ${bWidth}px ${bStyle} ${bColor}; border-left: none; border-right: none;`;
                } else if (gStyle === 'vertical') {
                    cellStyle = `border-top: none; border-bottom: none; border-left: ${bWidth}px ${bStyle} ${bColor}; border-right: ${bWidth}px ${bStyle} ${bColor};`;
                } else if (gStyle === 'outer' || gStyle === 'none') {
                    cellStyle = 'border: none;';
                }
            }
            
            if (gStyle === 'outer' && bStyle !== 'none' && bWidth > 0) {
                table.style.border = `${bWidth}px ${bStyle} ${bColor}`;
            } else {
                table.style.border = 'none';
            }
            
            const R = el.rows.length;
            const C = el.headers.length;
            const occupied = Array.from({ length: R }, () => Array(C).fill(false));
            
            let tableHtml = `<thead style="background:${headerBg}; color:${headerTextColor};"><tr>`;
            el.headers.forEach((h, cIdx) => {
                let hColspan = h.colspan || 1;
                let hRowspan = h.rowspan || 1;
                let hStyle = '';
                if (h.style && h.style.backgroundColor) {
                    hStyle += `background-color: ${h.style.backgroundColor} !important; background: ${h.style.backgroundColor} !important;`;
                }
                if (h.style && h.style.color) {
                    hStyle += `color: ${h.style.color} !important;`;
                }
                if (h.style && h.style.fontFamily) {
                    hStyle += `font-family: '${h.style.fontFamily}', sans-serif !important;`;
                }
                if (h.style && h.style.fontSize) {
                    hStyle += `font-size: ${h.style.fontSize}px !important;`;
                }
                if (h.style && h.style.fontWeight) {
                    hStyle += `font-weight: ${h.style.fontWeight} !important;`;
                }
                if (h.style && h.style.fontStyle) {
                    hStyle += `font-style: ${h.style.fontStyle} !important;`;
                }
                
                let isCellSelected = (activeCell && activeCell.elemId === el.id && activeCell.rowIndex === -1 && activeCell.colIndex === cIdx);
                let selectedClass = isCellSelected ? 'selected-cell' : '';
                
                if (hColspan > 0 && hRowspan > 0) {
                    tableHtml += `<th class="${selectedClass}" style="padding:4px; text-align:center; ${cellStyle} ${hStyle}" colspan="${hColspan}" rowspan="${hRowspan}" onmousedown="selectTableCell(event, '${el.id}', -1, ${cIdx})">${h.text}</th>`;
                }
            });
            tableHtml += '</tr></thead><tbody>';
            
            el.rows.forEach((row, rIdx) => {
                tableHtml += `<tr>`;
                row.forEach((cell, cIdx) => {
                    if (occupied[rIdx] && occupied[rIdx][cIdx]) {
                        return;
                    }
                    
                    let cColspan = cell.colspan || 1;
                    let cRowspan = cell.rowspan || 1;
                    
                    if (cColspan > 0 && cRowspan > 0) {
                        for (let i = 0; i < cRowspan; i++) {
                            for (let j = 0; j < cColspan; j++) {
                                if (rIdx + i < R && cIdx + j < C) {
                                    if (i > 0 || j > 0) {
                                        occupied[rIdx + i][cIdx + j] = true;
                                    }
                                }
                            }
                        }
                        
                        let cStyle = '';
                        if (cell.style && cell.style.backgroundColor) {
                            cStyle += `background-color: ${cell.style.backgroundColor} !important; background: ${cell.style.backgroundColor} !important;`;
                        }
                        if (cell.style && cell.style.color) {
                            cStyle += `color: ${cell.style.color} !important;`;
                        }
                        if (cell.style && cell.style.fontFamily) {
                            cStyle += `font-family: '${cell.style.fontFamily}', sans-serif !important;`;
                        }
                        if (cell.style && cell.style.fontSize) {
                            cStyle += `font-size: ${cell.style.fontSize}px !important;`;
                        }
                        if (cell.style && cell.style.fontWeight) {
                            cStyle += `font-weight: ${cell.style.fontWeight} !important;`;
                        }
                        if (cell.style && cell.style.fontStyle) {
                            cStyle += `font-style: ${cell.style.fontStyle} !important;`;
                        }
                        
                        let isCellSelected = (activeCell && activeCell.elemId === el.id && activeCell.rowIndex === rIdx && activeCell.colIndex === cIdx);
                        let selectedClass = isCellSelected ? 'selected-cell' : '';
                        
                        tableHtml += `<td class="${selectedClass}" style="padding:4px; text-align:center; ${cellStyle} ${cStyle}" colspan="${cColspan}" rowspan="${cRowspan}" onmousedown="selectTableCell(event, '${el.id}', ${rIdx}, ${cIdx})">${cell.text}</td>`;
                    }
                });
                tableHtml += '</tr>';
            });
            tableHtml += '</tbody>';
            
            table.innerHTML = tableHtml;
            inner.appendChild(table);
        }

        div.appendChild(inner);

        // Add 8 corner handles for resize
        const handles = ['tl', 'tr', 'bl', 'br', 't', 'b', 'l', 'r'];
        handles.forEach(h => {
            const span = document.createElement('span');
            span.className = `resize-handle handle-${h}`;
            span.addEventListener('mousedown', (e) => startResize(e, el.id, h));
            div.appendChild(span);
        });

        // Click to select
        div.addEventListener('mousedown', (e) => {
            if (e.target.classList.contains('resize-handle')) return;
            e.stopPropagation();
            selectElement(el.id);
            startDrag(e, el.id);
        });

        canvas.appendChild(div);
    });

    // Update ribbon selection elements
    updateElementSelectorDropdown();
    updateZoomTransform();
}

// Select element details & show on inspector panel
function selectElement(id) {
    activeElementId = id;
    const el = state.elements.find(e => e.id === id);
    
    // Update Style Painter buttons state
    const copyBtn = document.getElementById('btn-copy-style');
    if (copyBtn) {
        copyBtn.disabled = !el;
    }
    const pasteBtn = document.getElementById('btn-paste-style');
    if (pasteBtn) {
        pasteBtn.disabled = !el || !copiedStyle;
    }
    
    // Refresh canvas highlights
    renderCanvas();

    const textTools = document.getElementById('ribbon-text-tools');
    const borderTools = document.getElementById('ribbon-border-tools');
    const tableTools = document.getElementById('ribbon-table-tools');
    const tableActions = document.getElementById('ribbon-table-actions');
    const quickEditor = document.getElementById('quick-text-editor');

    if (!el) {
        textTools.style.display = 'none';
        borderTools.style.display = 'none';
        tableTools.style.display = 'none';
        if (tableActions) tableActions.style.display = 'none';
        quickEditor.style.display = 'none';
        return;
    }

    // Enable border panel
    borderTools.style.display = 'flex';

    // Populate border inputs
    document.getElementById('elem-border-style').value = el.borderStyle || 'none';
    document.getElementById('elem-border-width').value = el.borderWidth || 0;
    document.getElementById('elem-border-color').value = rgbToHex(el.borderColor) || '#000000';
    document.getElementById('elem-border-radius').value = el.borderRadius || 0;
    document.getElementById('elem-z').value = el.zIndex || 5;

    // Show contextual toolbars
    if (el.type === 'text') {
        textTools.style.display = 'flex';
        tableTools.style.display = 'none';
        if (tableActions) tableActions.style.display = 'none';
        quickEditor.style.display = 'block';

        document.getElementById('elem-text').value = el.content;
        document.getElementById('elem-font-family').value = el.fontFamily || 'Inter';
        document.getElementById('elem-font-size').value = el.fontSize || 16;
        document.getElementById('elem-color').value = rgbToHex(el.color) || '#000000';
        
        if (el.backgroundColor === 'transparent') {
            document.getElementById('elem-bg-none').checked = true;
            document.getElementById('elem-bg-color').disabled = true;
        } else {
            document.getElementById('elem-bg-none').checked = false;
            document.getElementById('elem-bg-color').disabled = false;
            document.getElementById('elem-bg-color').value = rgbToHex(el.backgroundColor) || '#ffffff';
        }

        document.getElementById('elem-lock-ratio').checked = !!el.lockRatio;
        
        // Display coordinates
        document.getElementById('coords-display').innerText = `X: ${Math.round(el.x)}, Y: ${Math.round(el.y)}`;

        // Button toggles
        document.getElementById('toggle-bold').classList.toggle('active', el.fontWeight === 'bold');
        document.getElementById('toggle-italic').classList.toggle('active', el.fontStyle === 'italic');
        document.getElementById('toggle-align-left').classList.toggle('active', el.textAlign === 'left');
        document.getElementById('toggle-align-center').classList.toggle('active', el.textAlign === 'center');
        document.getElementById('toggle-align-right').classList.toggle('active', el.textAlign === 'right');
    } else if (el.type === 'line') {
        textTools.style.display = 'flex';
        tableTools.style.display = 'none';
        if (tableActions) tableActions.style.display = 'none';
        quickEditor.style.display = 'block';

        document.getElementById('elem-text').value = '';
        document.getElementById('elem-color').value = rgbToHex(el.color) || '#000000';
        document.getElementById('elem-bg-color').value = rgbToHex(el.backgroundColor) || '#000000';
        document.getElementById('elem-bg-none').checked = false;
        document.getElementById('elem-bg-color').disabled = false;
        document.getElementById('elem-lock-ratio').checked = !!el.lockRatio;
        document.getElementById('coords-display').innerText = `X: ${Math.round(el.x)}, Y: ${Math.round(el.y)}`;
    } else if (el.type === 'table') {
        normalizeTable(el);
        textTools.style.display = 'flex'; // Let user style table fonts
        tableTools.style.display = 'flex';
        if (tableActions) tableActions.style.display = 'flex';
        quickEditor.style.display = 'block';

        document.getElementById('elem-table-headers').value = el.headers.map(h => h.text).join(', ');
        document.getElementById('elem-table-grid').value = el.gridStyle || 'full';
        
        // Show cell text in quickEditor if a cell of this table is selected
        if (activeCell && activeCell.elemId === el.id) {
            const cellText = getTableCellText(el, activeCell.rowIndex, activeCell.colIndex);
            document.getElementById('elem-text').value = cellText;
        } else {
            document.getElementById('elem-text').value = '';
        }

        document.getElementById('elem-font-family').value = el.fontFamily || 'Inter';
        document.getElementById('elem-font-size').value = el.fontSize || 12;
        document.getElementById('elem-color').value = rgbToHex(el.color) || '#000000';
        document.getElementById('elem-bg-none').checked = false;
        document.getElementById('elem-bg-color').value = '#ffffff';
        document.getElementById('elem-table-bg-color').value = rgbToHex(el.backgroundColor) || '#ffffff';
        document.getElementById('elem-lock-ratio').checked = !!el.lockRatio;
        document.getElementById('coords-display').innerText = `X: ${Math.round(el.x)}, Y: ${Math.round(el.y)}`;
        
        updateTableCellToolsState();
    } else {
        textTools.style.display = 'none';
        tableTools.style.display = 'none';
        if (tableActions) tableActions.style.display = 'none';
        quickEditor.style.display = 'block'; 
        document.getElementById('elem-text').value = '';
        document.getElementById('elem-lock-ratio').checked = !!el.lockRatio;
        document.getElementById('coords-display').innerText = `X: ${Math.round(el.x)}, Y: ${Math.round(el.y)}`;
    }
}

// Global state for selected table cell
let activeCell = null; // { elemId, rowIndex, colIndex }

// Normalize old array tables into array of cell objects
function normalizeTable(el) {
    if (!el.headers) el.headers = ['BIB', 'Shooter', 'S1', 'Total'];
    if (!el.rows) el.rows = [['101', 'Competitor Alpha', '95', '195']];
    
    el.headers = el.headers.map(h => {
        if (typeof h === 'string') {
            return { text: h, rowspan: 1, colspan: 1, style: {} };
        }
        if (!h.style || Array.isArray(h.style) || typeof h.style !== 'object') h.style = {};
        if (h.rowspan === undefined) h.rowspan = 1;
        if (h.colspan === undefined) h.colspan = 1;
        return h;
    });
    
    el.rows = el.rows.map(row => {
        return row.map(cell => {
            if (typeof cell === 'string') {
                return { text: cell, rowspan: 1, colspan: 1, style: {} };
            }
            if (!cell.style || Array.isArray(cell.style) || typeof cell.style !== 'object') cell.style = {};
            if (cell.rowspan === undefined) cell.rowspan = 1;
            if (cell.colspan === undefined) cell.colspan = 1;
            return cell;
        });
    });
}

function selectTableCell(event, elemId, rowIndex, colIndex) {
    if (event) {
        event.stopPropagation();
    }
    
    activeCell = { elemId, rowIndex, colIndex };
    selectElement(elemId);
    updateTableCellToolsState();
    renderCanvas();
}

function getTableCellText(el, rIdx, cIdx) {
    normalizeTable(el);
    if (rIdx === -1) {
        return el.headers[cIdx] ? el.headers[cIdx].text : '';
    } else {
        return (el.rows[rIdx] && el.rows[rIdx][cIdx]) ? el.rows[rIdx][cIdx].text : '';
    }
}

function setTableCellText(el, rIdx, cIdx, txt) {
    normalizeTable(el);
    if (rIdx === -1) {
        if (el.headers[cIdx]) el.headers[cIdx].text = txt;
    } else {
        if (el.rows[rIdx] && el.rows[rIdx][cIdx]) el.rows[rIdx][cIdx].text = txt;
    }
}

function updateTableCellToolsState() {
    const isCellSelected = !!activeCell;
    
    const cellBgColor = document.getElementById('elem-cell-bg-color');
    const paintRow = document.getElementById('btn-paint-row');
    const paintCol = document.getElementById('btn-paint-col');
    
    if (cellBgColor) cellBgColor.disabled = !isCellSelected;
    if (paintRow) paintRow.disabled = !isCellSelected;
    if (paintCol) paintCol.disabled = !isCellSelected;
    
    const mergeRight = document.getElementById('btn-merge-right');
    const mergeDown = document.getElementById('btn-merge-down');
    const splitCell = document.getElementById('btn-split-cell');
    
    if (!isCellSelected) {
        if (mergeRight) mergeRight.disabled = true;
        if (mergeDown) mergeDown.disabled = true;
        if (splitCell) splitCell.disabled = true;
        return;
    }
    
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const r = activeCell.rowIndex;
    const c = activeCell.colIndex;
    
    let hasRight = false;
    if (r === -1) {
        const cell = el.headers[c];
        const next = c + (cell.colspan || 1);
        hasRight = !!el.headers[next];
    } else {
        const cell = el.rows[r][c];
        const next = c + (cell.colspan || 1);
        hasRight = !!(el.rows[r] && el.rows[r][next]);
    }
    
    let hasBottom = false;
    if (r !== -1) {
        const cell = el.rows[r][c];
        const next = r + (cell.rowspan || 1);
        hasBottom = !!el.rows[next];
    }
    
    let isMerged = false;
    if (r === -1) {
        isMerged = (el.headers[c].colspan > 1 || el.headers[c].rowspan > 1);
    } else {
        isMerged = (el.rows[r][c].colspan > 1 || el.rows[r][c].rowspan > 1);
    }
    
    if (mergeRight) mergeRight.disabled = !hasRight;
    if (mergeDown) mergeDown.disabled = !hasBottom;
    if (splitCell) splitCell.disabled = !isMerged;
    
    let currentBg = '#ffffff';
    let currentTextColor = el.color || '#000000';
    let currentFontFamily = el.fontFamily || 'Inter';
    let currentFontSize = el.fontSize || 12;
    let isBold = el.fontWeight === 'bold';
    let isItalic = el.fontStyle === 'italic';

    const cellObj = (r === -1) ? el.headers[c] : el.rows[r][c];
    if (cellObj && cellObj.style) {
        if (cellObj.style.backgroundColor) currentBg = cellObj.style.backgroundColor;
        if (cellObj.style.color) currentTextColor = cellObj.style.color;
        if (cellObj.style.fontFamily) currentFontFamily = cellObj.style.fontFamily;
        if (cellObj.style.fontSize) currentFontSize = cellObj.style.fontSize;
        if (cellObj.style.fontWeight) isBold = cellObj.style.fontWeight === 'bold';
        if (cellObj.style.fontStyle) isItalic = cellObj.style.fontStyle === 'italic';
    }

    if (cellBgColor) cellBgColor.value = rgbToHex(currentBg) || '#ffffff';
    document.getElementById('elem-color').value = rgbToHex(currentTextColor) || '#000000';
    document.getElementById('elem-font-family').value = currentFontFamily;
    document.getElementById('elem-font-size').value = currentFontSize;
    document.getElementById('toggle-bold').classList.toggle('active', isBold);
    document.getElementById('toggle-italic').classList.toggle('active', isItalic);
}

function insertTableRowAbove() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    let rIdx = activeCell.rowIndex;
    if (rIdx === -1) rIdx = 0;
    
    const newRow = Array.from({ length: el.headers.length }, () => ({ text: 'Sample', rowspan: 1, colspan: 1, style: {} }));
    el.rows.splice(rIdx, 0, newRow);
    
    pushHistory();
    renderCanvas();
    selectTableCell(null, el.id, rIdx, activeCell.colIndex);
}

function insertTableRowBelow() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    let rIdx = activeCell.rowIndex;
    if (rIdx === -1) rIdx = 0;
    else rIdx = rIdx + 1;
    
    const newRow = Array.from({ length: el.headers.length }, () => ({ text: 'Sample', rowspan: 1, colspan: 1, style: {} }));
    el.rows.splice(rIdx, 0, newRow);
    
    pushHistory();
    renderCanvas();
    selectTableCell(null, el.id, rIdx, activeCell.colIndex);
}

function insertTableColumnLeft() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const cIdx = activeCell.colIndex;
    
    el.headers.splice(cIdx, 0, { text: 'New Col', rowspan: 1, colspan: 1, style: {} });
    el.rows.forEach(row => {
        row.splice(cIdx, 0, { text: 'Sample', rowspan: 1, colspan: 1, style: {} });
    });
    
    pushHistory();
    renderCanvas();
    selectTableCell(null, el.id, activeCell.rowIndex, cIdx);
}

function insertTableColumnRight() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const cIdx = activeCell.colIndex + 1;
    
    el.headers.splice(cIdx, 0, { text: 'New Col', rowspan: 1, colspan: 1, style: {} });
    el.rows.forEach(row => {
        row.splice(cIdx, 0, { text: 'Sample', rowspan: 1, colspan: 1, style: {} });
    });
    
    pushHistory();
    renderCanvas();
    selectTableCell(null, el.id, activeCell.rowIndex, cIdx);
}

function mergeCellRight() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const r = activeCell.rowIndex;
    const c = activeCell.colIndex;
    
    if (r === -1) {
        const cell = el.headers[c];
        const nextColIdx = c + (cell.colspan || 1);
        const neighbor = el.headers[nextColIdx];
        if (neighbor) {
            cell.colspan = (cell.colspan || 1) + (neighbor.colspan || 1);
            neighbor.colspan = 0;
            neighbor.rowspan = 0;
        }
    } else {
        const cell = el.rows[r][c];
        const nextColIdx = c + (cell.colspan || 1);
        const neighbor = el.rows[r][nextColIdx];
        if (neighbor) {
            cell.colspan = (cell.colspan || 1) + (neighbor.colspan || 1);
            neighbor.colspan = 0;
            neighbor.rowspan = 0;
        }
    }
    
    pushHistory();
    renderCanvas();
    updateTableCellToolsState();
}

function mergeCellDown() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table' || activeCell.rowIndex === -1) return;
    normalizeTable(el);
    
    const r = activeCell.rowIndex;
    const c = activeCell.colIndex;
    
    const cell = el.rows[r][c];
    const nextRowIdx = r + (cell.rowspan || 1);
    const neighborRow = el.rows[nextRowIdx];
    if (neighborRow) {
        const neighbor = neighborRow[c];
        if (neighbor) {
            cell.rowspan = (cell.rowspan || 1) + (neighbor.rowspan || 1);
            neighbor.colspan = 0;
            neighbor.rowspan = 0;
        }
    }
    
    pushHistory();
    renderCanvas();
    updateTableCellToolsState();
}

function splitSelectedCell() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const r = activeCell.rowIndex;
    const c = activeCell.colIndex;
    
    if (r === -1) {
        const cell = el.headers[c];
        const spanC = cell.colspan || 1;
        for (let j = 0; j < spanC; j++) {
            if (el.headers[c + j]) {
                el.headers[c + j].colspan = 1;
                el.headers[c + j].rowspan = 1;
            }
        }
    } else {
        const cell = el.rows[r][c];
        const spanR = cell.rowspan || 1;
        const spanC = cell.colspan || 1;
        for (let i = 0; i < spanR; i++) {
            for (let j = 0; j < spanC; j++) {
                if (el.rows[r + i] && el.rows[r + i][c + j]) {
                    el.rows[r + i][c + j].colspan = 1;
                    el.rows[r + i][c + j].rowspan = 1;
                }
            }
        }
    }
    
    pushHistory();
    renderCanvas();
    updateTableCellToolsState();
}

function paintCellBg(color) {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const r = activeCell.rowIndex;
    const c = activeCell.colIndex;
    
    if (r === -1) {
        if (el.headers[c]) {
            if (!el.headers[c].style) el.headers[c].style = {};
            el.headers[c].style.backgroundColor = color;
        }
    } else {
        if (el.rows[r] && el.rows[r][c]) {
            if (!el.rows[r][c].style) el.rows[r][c].style = {};
            el.rows[r][c].style.backgroundColor = color;
        }
    }
    
    pushHistory();
    renderCanvas();
}

function paintRowBg() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const r = activeCell.rowIndex;
    const color = document.getElementById('elem-cell-bg-color').value;
    
    if (r === -1) {
        el.headers.forEach(h => {
            if (!h.style) h.style = {};
            h.style.backgroundColor = color;
        });
    } else {
        if (el.rows[r]) {
            el.rows[r].forEach(c => {
                if (!c.style) c.style = {};
                c.style.backgroundColor = color;
            });
        }
    }
    
    pushHistory();
    renderCanvas();
}

function paintColBg() {
    if (!activeElementId || !activeCell) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);
    
    const c = activeCell.colIndex;
    const color = document.getElementById('elem-cell-bg-color').value;
    
    if (el.headers[c]) {
        if (!el.headers[c].style) el.headers[c].style = {};
        el.headers[c].style.backgroundColor = color;
    }
    el.rows.forEach(row => {
        if (row[c]) {
            if (!row[c].style) row[c].style = {};
            row[c].style.backgroundColor = color;
        }
    });
    
    pushHistory();
    renderCanvas();
}

// Deselect element
function deselectAll(e) {
    if (e && e.target !== document.getElementById('design-canvas') && e.target !== document.getElementById('workspace-container')) {
        return;
    }
    activeElementId = null;
    activeCell = null;
    document.getElementById('ribbon-text-tools').style.display = 'none';
    document.getElementById('ribbon-border-tools').style.display = 'none';
    document.getElementById('ribbon-table-tools').style.display = 'none';
    const tableActions = document.getElementById('ribbon-table-actions');
    if (tableActions) tableActions.style.display = 'none';
    document.getElementById('quick-text-editor').style.display = 'none';
    
    const copyBtn = document.getElementById('btn-copy-style');
    if (copyBtn) copyBtn.disabled = true;
    const pasteBtn = document.getElementById('btn-paste-style');
    if (pasteBtn) pasteBtn.disabled = true;
    
    renderCanvas();
}

// Update Active Element properties from input fields
function updateActiveElementProps() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el) return;

    el.borderStyle = document.getElementById('elem-border-style').value;
    el.borderWidth = parseInt(document.getElementById('elem-border-width').value) || 0;
    el.borderColor = document.getElementById('elem-border-color').value;
    el.borderRadius = parseInt(document.getElementById('elem-border-radius').value) || 0;
    el.zIndex = parseInt(document.getElementById('elem-z').value) || 5;
    el.lockRatio = document.getElementById('elem-lock-ratio').checked;

    if (el.type === 'text' || el.type === 'table' || el.type === 'line') {
        if (el.type === 'text') {
            el.fontFamily = document.getElementById('elem-font-family').value;
            el.fontSize = parseInt(document.getElementById('elem-font-size').value) || 12;
            el.color = document.getElementById('elem-color').value;
            el.content = document.getElementById('elem-text').value;
            const isNone = document.getElementById('elem-bg-none').checked;
            el.backgroundColor = isNone ? 'transparent' : document.getElementById('elem-bg-color').value;
        } else if (el.type === 'line') {
            el.backgroundColor = document.getElementById('elem-bg-color').value;
            el.color = el.backgroundColor; // sync color
        } else if (el.type === 'table') {
            el.backgroundColor = document.getElementById('elem-table-bg-color').value;
            if (activeCell && activeCell.elemId === el.id) {
                const r = activeCell.rowIndex;
                const c = activeCell.colIndex;
                const cellObj = (r === -1) ? el.headers[c] : el.rows[r][c];
                if (cellObj) {
                    if (!cellObj.style || Array.isArray(cellObj.style)) cellObj.style = {};
                    cellObj.style.fontFamily = document.getElementById('elem-font-family').value;
                    cellObj.style.fontSize = parseInt(document.getElementById('elem-font-size').value) || 12;
                    cellObj.style.color = document.getElementById('elem-color').value;
                    
                    const txt = document.getElementById('elem-text').value;
                    cellObj.text = txt;
                }
            } else {
                el.fontFamily = document.getElementById('elem-font-family').value;
                el.fontSize = parseInt(document.getElementById('elem-font-size').value) || 12;
                el.color = document.getElementById('elem-color').value;
            }
        }
    }

    renderCanvas();
}

// Table column headers manager
function updateTableHeaders() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);

    const val = document.getElementById('elem-table-headers').value;
    const list = val.split(',').map(h => h.trim()).filter(h => h !== '');

    el.headers = list.map((txt, idx) => {
        if (el.headers[idx]) {
            el.headers[idx].text = txt;
            return el.headers[idx];
        }
        return { text: txt, rowspan: 1, colspan: 1, style: {} };
    });

    if (!el.rows) el.rows = [];
    el.rows.forEach(row => {
        while (row.length < el.headers.length) {
            row.push({ text: '—', rowspan: 1, colspan: 1, style: {} });
        }
        if (row.length > el.headers.length) {
            row.length = el.headers.length;
        }
    });

    renderCanvas();
}

function updateTableGridStyle() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;

    el.gridStyle = document.getElementById('elem-table-grid').value;
    pushHistory();
    renderCanvas();
}

function toggleBgNone() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el) return;

    const isNone = document.getElementById('elem-bg-none').checked;
    document.getElementById('elem-bg-color').disabled = isNone;
    el.backgroundColor = isNone ? 'transparent' : document.getElementById('elem-bg-color').value;

    pushHistory();
    renderCanvas();
}

// Add row visually
function addTableRow() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);

    const newRow = Array.from({ length: el.headers.length }, () => ({ text: 'Sample', rowspan: 1, colspan: 1, style: {} }));
    el.rows.push(newRow);

    pushHistory();
    renderCanvas();
}

// Add column visually
function addTableColumn() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    normalizeTable(el);

    const newColName = 'Col ' + (el.headers.length + 1);
    el.headers.push({ text: newColName, rowspan: 1, colspan: 1, style: {} });
    el.rows.forEach(row => row.push({ text: 'Sample', rowspan: 1, colspan: 1, style: {} }));

    pushHistory();
    renderCanvas();
    selectElement(activeElementId);
}

// Delete row visually
function deleteTableRow() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table' || !el.rows || el.rows.length === 0) return;

    normalizeTable(el);

    if (activeCell && activeCell.elemId === el.id && activeCell.rowIndex >= 0) {
        const rIdx = activeCell.rowIndex;
        el.rows.splice(rIdx, 1);
        activeCell = null;
    } else {
        el.rows.pop();
    }
    
    pushHistory();
    renderCanvas();
    updateTableCellToolsState();
}

// Delete column visually
function deleteTableColumn() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table' || !el.headers || el.headers.length === 0) return;

    normalizeTable(el);

    if (activeCell && activeCell.elemId === el.id) {
        const cIdx = activeCell.colIndex;
        if (cIdx >= 0 && cIdx < el.headers.length) {
            el.headers.splice(cIdx, 1);
            if (el.rows) {
                el.rows.forEach(row => {
                    if (row && row.length > cIdx) {
                        row.splice(cIdx, 1);
                    }
                });
            }
        }
        activeCell = null;
    } else {
        el.headers.pop();
        if (el.rows) {
            el.rows.forEach(row => row.pop());
        }
    }
    
    pushHistory();
    renderCanvas();
    selectElement(activeElementId);
    updateTableCellToolsState();
}

// Font modifiers
function toggleTextStyle(style) {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el) return;
    if (el.type === 'table') {
        if (activeCell && activeCell.elemId === el.id) {
            const r = activeCell.rowIndex;
            const c = activeCell.colIndex;
            const targetCell = (r === -1) ? el.headers[c] : el.rows[r][c];
            if (targetCell) {
                if (!targetCell.style || Array.isArray(targetCell.style)) targetCell.style = {};
                if (style === 'bold') {
                    targetCell.style.fontWeight = (targetCell.style.fontWeight === 'bold') ? 'normal' : 'bold';
                } else if (style === 'italic') {
                    targetCell.style.fontStyle = (targetCell.style.fontStyle === 'italic') ? 'normal' : 'italic';
                }
                pushHistory();
                renderCanvas();
                updateTableCellToolsState();
            }
        }
        return;
    }
    if (el.type !== 'text') return;

    const textarea = document.getElementById('elem-text');
    const sel = window.getSelection();
    let isCanvasSelection = false;
    
    if (sel.toString().length > 0) {
        const div = document.getElementById(activeElementId);
        if (div && div.contains(sel.anchorNode)) {
            isCanvasSelection = true;
        }
    }

    if (isCanvasSelection) {
        const div = document.getElementById(activeElementId);
        if (div) {
            const inner = div.querySelector('.canvas-element-content');
            if (inner) {
                inner.focus();
                document.execCommand(style, false);
                el.content = inner.innerHTML;
                if (textarea) textarea.value = el.content;
                pushHistory();
                return;
            }
        }
    }

    if (textarea && textarea.selectionStart !== textarea.selectionEnd) {
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const text = textarea.value;
        const selectedText = text.substring(start, end);
        
        let isWrapped = false;
        let openLen = 0;
        let closeLen = 0;
        let stripOuter = false;
        
        const selLower = selectedText.toLowerCase();
        const preText = text.substring(0, start);
        const postText = text.substring(end);

        if (style === 'bold') {
            // Case A: Selection starts/ends with tags
            if (selLower.startsWith('<b>') && selLower.endsWith('</b>')) {
                isWrapped = true;
                openLen = 3;
                closeLen = 4;
            } else if (selLower.startsWith('<strong>') && selLower.endsWith('</strong>')) {
                isWrapped = true;
                openLen = 8;
                closeLen = 9;
            }
            // Case B: Surrounding text has tags (ignoring spaces)
            else {
                const matchPreB = preText.match(/<b>\s*$/i);
                const matchPostB = postText.match(/^\s*<\/b>/i);
                if (matchPreB && matchPostB) {
                    isWrapped = true;
                    stripOuter = true;
                    openLen = matchPreB[0].length;
                    closeLen = matchPostB[0].length;
                } else {
                    const matchPreStr = preText.match(/<strong>\s*$/i);
                    const matchPostStr = postText.match(/^\s*<\/strong>/i);
                    if (matchPreStr && matchPostStr) {
                        isWrapped = true;
                        stripOuter = true;
                        openLen = matchPreStr[0].length;
                        closeLen = matchPostStr[0].length;
                    }
                }
            }
        } else if (style === 'italic') {
            // Case A: Selection starts/ends with tags
            if (selLower.startsWith('<i>') && selLower.endsWith('</i>')) {
                isWrapped = true;
                openLen = 3;
                closeLen = 4;
            } else if (selLower.startsWith('<em>') && selLower.endsWith('</em>')) {
                isWrapped = true;
                openLen = 4;
                closeLen = 5;
            }
            // Case B: Surrounding text has tags (ignoring spaces)
            else {
                const matchPreI = preText.match(/<i>\s*$/i);
                const matchPostI = postText.match(/^\s*<\/i>/i);
                if (matchPreI && matchPostI) {
                    isWrapped = true;
                    stripOuter = true;
                    openLen = matchPreI[0].length;
                    closeLen = matchPostI[0].length;
                } else {
                    const matchPreEm = preText.match(/<em>\s*$/i);
                    const matchPostEm = postText.match(/^\s*<\/em>/i);
                    if (matchPreEm && matchPostEm) {
                        isWrapped = true;
                        stripOuter = true;
                        openLen = matchPreEm[0].length;
                        closeLen = matchPostEm[0].length;
                    }
                }
            }
        }

        let newPre, newPost, replacement;
        if (isWrapped) {
            if (stripOuter) {
                newPre = text.substring(0, start - openLen);
                newPost = text.substring(end + closeLen);
                replacement = selectedText;
            } else {
                newPre = text.substring(0, start);
                newPost = text.substring(end);
                replacement = selectedText.substring(openLen, selectedText.length - closeLen);
            }
        } else {
            newPre = text.substring(0, start);
            newPost = text.substring(end);
            const openTag = style === 'bold' ? '<b>' : '<i>';
            const closeTag = style === 'bold' ? '</b>' : '</i>';
            replacement = `${openTag}${selectedText}${closeTag}`;
        }
        
        textarea.value = newPre + replacement + newPost;
        el.content = textarea.value;
        
        textarea.focus();
        textarea.selectionStart = newPre.length;
        textarea.selectionEnd = newPre.length + replacement.length;
        
        renderCanvas();
        pushHistory();
        return;
    }

    // Fallback: Apply to entire text element
    const div = document.getElementById(activeElementId);
    if (div) {
        const inner = div.querySelector('.canvas-element-content');
        if (inner) {
            inner.focus();
            document.execCommand('selectAll', false);
            document.execCommand(style, false);
            sel.removeAllRanges();
            el.content = inner.innerHTML;
            if (textarea) textarea.value = el.content;
            pushHistory();
        }
    }
}

function toggleTextAlign(align) {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'text') return;

    el.textAlign = align;
    pushHistory();
    selectElement(activeElementId);
}

// Insert dynamic tags at text cursor position or table cell
function insertDynamicPlaceholder(tag) {
    if (!activeElementId) {
        addNewElement('text', tag);
        return;
    }
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el) {
        addNewElement('text', tag);
        return;
    }

    if (el.type === 'table' && activeCell && activeCell.elemId === el.id) {
        normalizeTable(el);
        const r = activeCell.rowIndex;
        const c = activeCell.colIndex;
        let currentTxt = getTableCellText(el, r, c);
        setTableCellText(el, r, c, currentTxt + tag);
        document.getElementById('elem-text').value = currentTxt + tag;
        pushHistory();
        renderCanvas();
        return;
    }

    if (el.type !== 'text') {
        addNewElement('text', tag);
        return;
    }

    const textarea = document.getElementById('elem-text');
    const start = textarea ? textarea.selectionStart : 0;
    const end = textarea ? textarea.selectionEnd : 0;
    const text = textarea ? textarea.value : (el.content || '');
    
    el.content = text.substring(0, start) + tag + text.substring(end);
    pushHistory();
    selectElement(activeElementId);
}

function insertESignBox(type) {
    const tag = type === 'official' ? '{official_signature}' : '{shooter_signature}';
    addNewElement('image', tag);
}

// E-Signature uploader API integration
async function uploadESignature(input) {
    if (!input.files || !input.files[0]) return;
    const type = document.getElementById('doc-type-select').value;

    const formData = new FormData();
    formData.append('document_type', type);
    formData.append('esign_image', input.files[0]);
    formData.append('canvas_data', JSON.stringify(state));

    try {
        Swal.fire({
            title: 'Uploading signature...',
            didOpen: () => { Swal.showLoading(); },
            background: '#1a1a1a',
            color: '#fff'
        });

        const res = await fetch('actions/save_template.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            addNewElement('image', data.esign_path);
            Swal.close();
        } else {
            Swal.fire({ icon: 'error', title: 'Upload Failed', text: data.message, background: '#1a1a1a', color: '#fff' });
        }
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'Error', text: 'Signature upload failed.', background: '#1a1a1a', color: '#fff' });
    }
}

// Clipboard Paste event listener for Document Designer Canvas
document.addEventListener('paste', (e) => {
    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
    for (let index in items) {
        const item = items[index];
        if (item.kind === 'file' && item.type.startsWith('image/')) {
            const blob = item.getAsFile();
            const reader = new FileReader();
            reader.onload = function(evt) {
                addNewElement('image', evt.target.result);
            };
            reader.readAsDataURL(blob);
            break;
        }
    }
});

// Drag & Drop Controls
function startDrag(e, id) {
    const el = state.elements.find(elem => elem.id === id);
    if (!el) return;

    dragTracker.isDragging = true;
    dragTracker.elemId = id;
    dragTracker.startX = e.clientX;
    dragTracker.startY = e.clientY;
    dragTracker.startLeft = el.x;
    dragTracker.startTop = el.y;

    document.addEventListener('mousemove', dragMove);
    document.addEventListener('mouseup', endDragOrResize);
}

function dragMove(e) {
    if (!dragTracker.isDragging) return;
    const el = state.elements.find(elem => elem.id === dragTracker.elemId);
    if (!el) return;

    const dx = (e.clientX - dragTracker.startX) / zoomLevel;
    const dy = (e.clientY - dragTracker.startY) / zoomLevel;

    el.x = dragTracker.startLeft + dx;
    el.y = dragTracker.startTop + dy;

    // Display coordinates
    document.getElementById('coords-display').innerText = `X: ${Math.round(el.x)}, Y: ${Math.round(el.y)}`;

    renderCanvas();
}

// Resize Controls
function startResize(e, id, handle) {
    e.stopPropagation();
    const el = state.elements.find(elem => elem.id === id);
    if (!el) return;

    dragTracker.isResizing = true;
    dragTracker.elemId = id;
    dragTracker.handle = handle;
    dragTracker.startX = e.clientX;
    dragTracker.startY = e.clientY;
    dragTracker.startW = el.width;
    dragTracker.startH = el.height;
    dragTracker.startLeft = el.x;
    dragTracker.startTop = el.y;

    document.addEventListener('mousemove', resizeMove);
    document.addEventListener('mouseup', endDragOrResize);
}

function resizeMove(e) {
    if (!dragTracker.isResizing) return;
    const el = state.elements.find(elem => elem.id === dragTracker.elemId);
    if (!el) return;

    const dx = (e.clientX - dragTracker.startX) / zoomLevel;
    const dy = (e.clientY - dragTracker.startY) / zoomLevel;

    let nw = el.width;
    let nh = el.height;
    const ratio = dragTracker.startW / dragTracker.startH;

    if (dragTracker.handle.includes('r')) {
        nw = Math.max(20, dragTracker.startW + dx);
    }
    if (dragTracker.handle.includes('l')) {
        const potentialW = dragTracker.startW - dx;
        if (potentialW > 20) {
            nw = potentialW;
            el.x = dragTracker.startLeft + dx;
        }
    }
    if (dragTracker.handle.includes('b')) {
        nh = Math.max(20, dragTracker.startH + dy);
    }
    if (dragTracker.handle.includes('t')) {
        const potentialH = dragTracker.startH - dy;
        if (potentialH > 20) {
            nh = potentialH;
            el.y = dragTracker.startTop + dy;
        }
    }

    if (el.lockRatio) {
        if (dragTracker.handle === 'r' || dragTracker.handle === 'l' || dragTracker.handle === 'b' || dragTracker.handle === 't') {
            if (dragTracker.handle === 'r' || dragTracker.handle === 'l') {
                nh = nw / ratio;
            } else {
                nw = nh * ratio;
            }
        } else {
            const scale = Math.max(nw / dragTracker.startW, nh / dragTracker.startH);
            nw = dragTracker.startW * scale;
            nh = dragTracker.startH * scale;
        }
    }

    el.width = nw;
    el.height = nh;

    renderCanvas();
}

function endDragOrResize() {
    if (dragTracker.isDragging || dragTracker.isResizing) {
        pushHistory();
    }
    dragTracker.isDragging = false;
    dragTracker.isResizing = false;
    document.removeEventListener('mousemove', dragMove);
    document.removeEventListener('mousemove', resizeMove);
    document.removeEventListener('mouseup', endDragOrResize);
}

// Z-index layer actions
function arrangeLayer(direction) {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el) return;

    if (direction === 'front') {
        el.zIndex = Math.max(...state.elements.map(e => e.zIndex), 0) + 1;
    } else {
        el.zIndex = Math.min(...state.elements.map(e => e.zIndex), 0) - 1;
    }
    document.getElementById('elem-z').value = el.zIndex;
    pushHistory();
    renderCanvas();
}

// Canvas general size configuration
function updatePageSize() {
    state.width = parseInt(document.getElementById('page-width').value) || 794;
    state.height = parseInt(document.getElementById('page-height').value) || 1122;
    pushHistory();
    renderCanvas();
}

function updateOrientation() {
    state.orientation = document.getElementById('page-orientation').value;
    const w = state.width;
    const h = state.height;
    
    if (state.orientation === 'landscape' && w < h) {
        state.width = h;
        state.height = w;
    } else if (state.orientation === 'portrait' && w > h) {
        state.width = h;
        state.height = w;
    }
    document.getElementById('page-width').value = state.width;
    document.getElementById('page-height').value = state.height;
    pushHistory();
    renderCanvas();
}

// Page borders editor logic
function updatePageBorder() {
    state.pageBorderStyle = document.getElementById('page-border-style').value;
    state.pageBorderWidth = parseInt(document.getElementById('page-border-width').value) || 0;
    state.pageBorderColor = document.getElementById('page-border-color').value;
    state.pageBorderRadius = parseInt(document.getElementById('page-border-radius').value) || 0;
    state.pageBorderInset = parseInt(document.getElementById('page-border-inset').value) || 0;
    
    renderCanvas();
}

// Zoom controller
function setZoom(val) {
    zoomLevel = parseFloat(val);
    updateZoomTransform();
}

// Fit screen logic
function fitToScreen() {
    const container = document.getElementById('workspace-container');
    const availableW = container.clientWidth - 80;
    const scale = availableW / state.width;
    
    zoomLevel = Math.min(Math.max(Math.floor(scale * 100) / 100, 0.4), 1.5);
    document.getElementById('zoom-select').value = zoomLevel;
    updateZoomTransform();
}

function updateZoomTransform() {
    const canvas = document.getElementById('design-canvas');
    canvas.style.transform = `scale(${zoomLevel})`;
}

// Selection Ribbon Dropdown Refresh
function updateElementSelectorDropdown() {
    const sel = document.getElementById('ribbon-element-selector');
    if (!sel) return;
    sel.innerHTML = '<option value="">-- Select Element --</option>';
    
    state.elements.forEach(el => {
        const opt = document.createElement('option');
        opt.value = el.id;
        let title = el.type.toUpperCase();
        if (el.type === 'text') {
            title = el.content.substring(0, 16) || 'Empty Text';
        }
        opt.textContent = title;
        opt.selected = (el.id === activeElementId);
        sel.appendChild(opt);
    });

    // Populate Page Setup Fields
    document.getElementById('page-width').value = state.width;
    document.getElementById('page-height').value = state.height;
    document.getElementById('page-orientation').value = state.orientation || 'portrait';
    
    document.getElementById('page-border-style').value = state.pageBorderStyle || 'none';
    document.getElementById('page-border-width').value = state.pageBorderWidth || 0;
    document.getElementById('page-border-color').value = rgbToHex(state.pageBorderColor) || '#000000';
    document.getElementById('page-border-radius').value = state.pageBorderRadius || 0;
    document.getElementById('page-border-inset').value = state.pageBorderInset || 0;

    const colorPicker = document.getElementById('page-bg-color');
    if (colorPicker) {
        if (state.background && (state.background.startsWith('#') || state.background.startsWith('rgb'))) {
            colorPicker.value = rgbToHex(state.background);
        } else {
            colorPicker.value = '#ffffff';
        }
    }
}

// Undo / Redo core mechanics
function initHistory() {
    historyStack = [JSON.stringify(state)];
    historyIndex = 0;
    updateHistoryButtons();
}

function pushHistory() {
    historyStack = historyStack.slice(0, historyIndex + 1);
    historyStack.push(JSON.stringify(state));
    historyIndex++;
    updateHistoryButtons();
}

function undo() {
    if (historyIndex > 0) {
        historyIndex--;
        state = JSON.parse(historyStack[historyIndex]);
        renderCanvas();
        updateHistoryButtons();
        if (activeElementId) selectElement(activeElementId);
    }
}

function redo() {
    if (historyIndex < historyStack.length - 1) {
        historyIndex++;
        state = JSON.parse(historyStack[historyIndex]);
        renderCanvas();
        updateHistoryButtons();
        if (activeElementId) selectElement(activeElementId);
    }
}

// History stack button check
function updateHistoryButtons() {
    document.getElementById('btn-undo').disabled = historyIndex <= 0;
    document.getElementById('btn-redo').disabled = historyIndex >= historyStack.length - 1;
}

// Page background color update
function updatePageBgColor(color) {
    state.background = color;
    pushHistory();
    renderCanvas();
}

// Table background color update
function updateTableBgColor(color) {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el || el.type !== 'table') return;
    el.backgroundColor = color;
    pushHistory();
    renderCanvas();
}

// Style Painter: Copy Selected Element Style
function copyElementStyle() {
    if (!activeElementId) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el) return;

    copiedStyle = {
        fontFamily: el.fontFamily,
        fontSize: el.fontSize,
        color: el.color,
        backgroundColor: el.backgroundColor,
        fontWeight: el.fontWeight,
        fontStyle: el.fontStyle,
        textAlign: el.textAlign,
        borderStyle: el.borderStyle,
        borderWidth: el.borderWidth,
        borderColor: el.borderColor,
        borderRadius: el.borderRadius
    };

    const pasteBtn = document.getElementById('btn-paste-style');
    if (pasteBtn) {
        pasteBtn.disabled = false;
    }

    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Style copied!',
        showConfirmButton: false,
        timer: 1500,
        background: '#1a1a1a',
        color: '#fff'
    });
}

// Style Painter: Paste Copied Style to Active Element
function pasteElementStyle() {
    if (!activeElementId || !copiedStyle) return;
    const el = state.elements.find(e => e.id === activeElementId);
    if (!el) return;

    if (el.type === 'text' || el.type === 'table' || el.type === 'line') {
        if (copiedStyle.fontFamily !== undefined && el.type !== 'line') el.fontFamily = copiedStyle.fontFamily;
        if (copiedStyle.fontSize !== undefined && el.type !== 'line') el.fontSize = copiedStyle.fontSize;
        if (copiedStyle.color !== undefined) el.color = copiedStyle.color;
        if (copiedStyle.backgroundColor !== undefined) el.backgroundColor = copiedStyle.backgroundColor;
        if (copiedStyle.fontWeight !== undefined && el.type === 'text') el.fontWeight = copiedStyle.fontWeight;
        if (copiedStyle.fontStyle !== undefined && el.type === 'text') el.fontStyle = copiedStyle.fontStyle;
        if (copiedStyle.textAlign !== undefined && el.type === 'text') el.textAlign = copiedStyle.textAlign;

        if (copiedStyle.borderStyle !== undefined) el.borderStyle = copiedStyle.borderStyle;
        if (copiedStyle.borderWidth !== undefined) el.borderWidth = copiedStyle.borderWidth;
        if (copiedStyle.borderColor !== undefined) el.borderColor = copiedStyle.borderColor;
        if (copiedStyle.borderRadius !== undefined) el.borderRadius = copiedStyle.borderRadius;
    }

    pushHistory();
    // Refresh inspector fields for the active element
    selectElement(activeElementId);

    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Style applied!',
        showConfirmButton: false,
        timer: 1500,
        background: '#1a1a1a',
        color: '#fff'
    });
}

// Utility color converter helper
function rgbToHex(color) {
    if (!color) return '#000000';
    color = color.trim();
    if (color.startsWith('#')) {
        if (color.length === 4) {
            return '#' + color[1] + color[1] + color[2] + color[2] + color[3] + color[3];
        }
        return color;
    }
    if (color === 'transparent') return '#ffffff';
    
    const match = color.match(/^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*[\d.]+)?\)$/i);
    if (match) {
        const r = parseInt(match[1]).toString(16).padStart(2, '0');
        const g = parseInt(match[2]).toString(16).padStart(2, '0');
        const b = parseInt(match[3]).toString(16).padStart(2, '0');
        return `#${r}${g}${b}`;
    }
    return color;
}
</script>

<?php
require_once 'includes/footer.php';
?>

