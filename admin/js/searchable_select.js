/**
 * searchable_select.js
 * Shared searchable dropdown widget for admin event selectors.
 * Converts a native <select> into a type-to-search dropdown with an "All Events" entry.
 *
 * Usage:
 *   initSearchableSelect(selectElement, { placeholder, allLabel, onSelect });
 *
 *   placeholder  – text shown when nothing is selected (default: '🔍 Search events…')
 *   allLabel     – text for the "all" option  (default: '— All Events —')
 *   onSelect(val)– callback fired when an option is chosen (optional)
 */
(function () {
  'use strict';

  // Inject shared styles once
  function injectStyles() {
    if (document.getElementById('ss-shared-styles')) return;
    const style = document.createElement('style');
    style.id = 'ss-shared-styles';
    style.textContent = `
      .meta-card, .rl-toolbar, .field, .input-wrap {
        overflow: visible !important;
      }
      .ss-wrapper {
        position: relative;
        width: 100%;
        box-sizing: border-box;
        z-index: 10;
        cursor: pointer !important;
      }
      .ss-wrapper.open-active {
        z-index: 99999 !important;
      }
      .ss-input {
        width: 100%;
        padding: 10px 38px 10px 14px;
        background: rgba(0, 0, 0, 0.55);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: #fff;
        border-radius: 6px;
        font-family: inherit;
        font-size: 14px;
        outline: none;
        cursor: pointer !important;
        box-sizing: border-box;
        transition: border-color 0.2s, box-shadow 0.2s;
        -webkit-appearance: none;
      }
      .ss-input[readonly] {
        cursor: pointer !important;
      }
      .ss-input:focus, .ss-input.open {
        border-color: rgba(201, 168, 76, 0.55);
        box-shadow: 0 0 0 2px rgba(201, 168, 76, 0.12);
      }
      .ss-input::placeholder {
        color: rgba(255, 255, 255, 0.35);
      }
      .ss-arrow {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 10px;
        opacity: 0.55;
        color: #c9a84c;
        pointer-events: none;
        transition: transform 0.2s;
        line-height: 1;
        cursor: pointer !important;
      }
      .ss-arrow.rotated {
        transform: translateY(-50%) rotate(180deg);
      }
      .ss-clear {
        position: absolute;
        right: 30px;
        top: 50%;
        transform: translateY(-50%);
        font-size: 14px;
        opacity: 0.45;
        color: #fff;
        cursor: pointer !important;
        display: none;
        line-height: 1;
        padding: 2px 4px;
        transition: opacity 0.15s;
      }
      .ss-clear:hover { opacity: 0.9; }
      .ss-dropdown {
        display: none;
        position: absolute;
        top: calc(100% + 4px);
        left: 0;
        right: 0;
        background: #141520 !important;
        border: 1px solid rgba(201, 168, 76, 0.4) !important;
        border-radius: 6px;
        z-index: 999999 !important;
        box-shadow: 0 12px 40px rgba(0, 0, 0, 0.9) !important;
        overflow: hidden;
        flex-direction: column;
        max-height: 280px;
      }
      .ss-dropdown.open {
        display: flex !important;
      }
      .ss-search-wrap {
        padding: 8px 10px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.07);
        background: rgba(255,255,255,0.02);
        flex-shrink: 0;
      }
      .ss-search-input {
        width: 100%;
        padding: 7px 10px;
        background: rgba(0, 0, 0, 0.4);
        border: 1px solid rgba(255, 255, 255, 0.1);
        color: #fff;
        border-radius: 4px;
        font-size: 13px;
        font-family: inherit;
        outline: none;
        box-sizing: border-box;
        transition: border-color 0.15s;
        cursor: text !important;
      }
      .ss-search-input:focus {
        border-color: rgba(201, 168, 76, 0.45);
      }
      .ss-search-input::placeholder {
        color: rgba(255, 255, 255, 0.3);
        font-style: italic;
      }
      .ss-options {
        overflow-y: auto;
        flex: 1;
        background: #141520 !important;
      }
      .ss-option {
        padding: 10px 14px;
        cursor: pointer !important;
        font-size: 13px;
        color: rgba(255, 255, 255, 0.88) !important;
        transition: background 0.12s, color 0.12s;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        line-height: 1.4;
      }
      .ss-option * {
        cursor: pointer !important;
      }
      .ss-option:last-child { border-bottom: none; }
      .ss-option:hover, .ss-option.highlighted {
        background: rgba(201, 168, 76, 0.18) !important;
        color: #fff !important;
      }
      .ss-option.selected {
        background: rgba(201, 168, 76, 0.28) !important;
        color: #c9a84c !important;
        font-weight: 700;
      }
      .ss-option.all-option {
        color: rgba(255, 255, 255, 0.6) !important;
        font-style: italic;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
      }
      .ss-option.all-option.selected {
        color: #c9a84c !important;
        font-style: normal;
      }
      .ss-no-results {
        padding: 12px 14px;
        font-size: 13px;
        color: rgba(255, 255, 255, 0.4);
        text-align: center;
        font-style: italic;
      }
      .ss-option em {
        color: #c9a84c !important;
        font-style: normal;
        font-weight: 700;
      }
    `;
    document.head.appendChild(style);
  }

  /**
   * @param {HTMLSelectElement} select
   * @param {object} opts
   */
  function initSearchableSelect(select, opts) {
    if (!select) return;
    if (select.dataset.ssInitialized === 'true') return;
    select.dataset.ssInitialized = 'true';

    const nativeEmptyOpt = Array.from(select.options).find(o => o.value === '' || (o.value === '0' && (select.name.includes('relay') || select.id.includes('relay'))));
    const nativeEmptyText = (nativeEmptyOpt && nativeEmptyOpt.textContent.trim()) ? nativeEmptyOpt.textContent.trim() : '';

    let itemType = 'event';
    if (select.name.includes('participant') || select.id.includes('participant')) itemType = 'participant';
    else if (select.name.includes('club') || select.id.includes('club')) itemType = 'club';
    else if (select.name.includes('event') || select.id.includes('event')) itemType = 'event';
    else if (select.name.includes('date') || select.id.includes('date')) itemType = 'date';
    else if (select.name.includes('relay') || select.id.includes('relay')) itemType = 'relay';

    let defaultAllLabel = '— All ' + itemType.charAt(0).toUpperCase() + itemType.slice(1) + 's —';
    if (nativeEmptyText) {
      defaultAllLabel = nativeEmptyText;
    }

    const placeholder = (opts && opts.placeholder) || ('Search ' + itemType + 's…');
    const allLabel    = (opts && opts.allLabel)    || defaultAllLabel;
    const onSelect    = (opts && opts.onSelect)    || null;

    injectStyles();

    // Hide the native select
    select.style.display = 'none';

    // Build wrapper
    const wrapper = document.createElement('div');
    wrapper.className = 'ss-wrapper';
    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(select);

    // Trigger input (displays currently selected value)
    const triggerInput = document.createElement('input');
    triggerInput.type = 'text';
    triggerInput.className = 'ss-input';
    triggerInput.placeholder = placeholder;
    triggerInput.autocomplete = 'off';
    triggerInput.autocorrect = 'off';
    triggerInput.spellcheck = false;
    triggerInput.readOnly = true;
    triggerInput.setAttribute('aria-haspopup', 'listbox');
    triggerInput.setAttribute('role', 'combobox');
    wrapper.appendChild(triggerInput);

    // Clear button
    const clearBtn = document.createElement('span');
    clearBtn.className = 'ss-clear';
    clearBtn.title = 'Clear selection';
    clearBtn.innerHTML = '&times;';
    wrapper.appendChild(clearBtn);

    // Arrow icon
    const arrow = document.createElement('span');
    arrow.className = 'ss-arrow';
    arrow.textContent = '▼';
    wrapper.appendChild(arrow);

    // Dropdown panel
    const dropdown = document.createElement('div');
    dropdown.className = 'ss-dropdown';
    dropdown.setAttribute('role', 'listbox');
    wrapper.appendChild(dropdown);

    // Search field inside dropdown
    const searchWrap = document.createElement('div');
    searchWrap.className = 'ss-search-wrap';
    const searchInput = document.createElement('input');
    searchInput.type = 'text';
    searchInput.className = 'ss-search-input';
    searchInput.placeholder = 'Type to search ' + itemType + 's…';
    searchInput.autocomplete = 'off';
    searchWrap.appendChild(searchInput);
    dropdown.appendChild(searchWrap);

    // Options list container
    const optionsList = document.createElement('div');
    optionsList.className = 'ss-options';
    dropdown.appendChild(optionsList);

    // ── Helpers ────────────────────────────────────────────────────────────────

    function getOptions() {
      return Array.from(select.options);
    }

    function currentLabel() {
      const idx = select.selectedIndex;
      if (idx < 0) return allLabel;
      const opt = select.options[idx];
      if (!opt) return allLabel;
      const txt = opt.textContent.trim();
      return txt ? txt : allLabel;
    }

    function highlight(text, query) {
      if (!query) return document.createTextNode(text);
      const i = text.toLowerCase().indexOf(query.toLowerCase());
      if (i === -1) return document.createTextNode(text);
      const span = document.createElement('span');
      span.appendChild(document.createTextNode(text.slice(0, i)));
      const em = document.createElement('em');
      em.textContent = text.slice(i, i + query.length);
      span.appendChild(em);
      span.appendChild(document.createTextNode(text.slice(i + query.length)));
      return span;
    }

    function renderOptions(filter) {
      optionsList.innerHTML = '';
      filter = (filter || '').toLowerCase().trim();

      const opts = getOptions();
      let count = 0;

      // "All" row
      const allDiv = document.createElement('div');
      allDiv.className = 'ss-option all-option';
      if (select.value === '') allDiv.classList.add('selected');
      allDiv.textContent = allLabel;
      allDiv.dataset.value = '';
      allDiv.addEventListener('mousedown', (e) => { e.preventDefault(); selectOption('', allLabel); });
      optionsList.appendChild(allDiv);

      let currentGroupLabel = null;
      opts.forEach(opt => {
        if (opt.value === '') return; // skip placeholder
        const text = opt.textContent.trim();
        const matches = !filter ||
          text.toLowerCase().includes(filter) ||
          opt.value.toLowerCase().includes(filter);
        if (!matches) return;

        if (opt.parentElement && opt.parentElement.tagName === 'OPTGROUP') {
          const groupLabel = opt.parentElement.label;
          if (groupLabel !== currentGroupLabel) {
            currentGroupLabel = groupLabel;
            const groupHeader = document.createElement('div');
            groupHeader.className = 'ss-optgroup-header';
            groupHeader.style.cssText = 'padding:8px 12px 4px 12px; font-weight:700; font-size:11px; color:#c9a84c; background:rgba(255,255,255,0.03); text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid rgba(255,255,255,0.05);';
            groupHeader.textContent = groupLabel;
            optionsList.appendChild(groupHeader);
          }
        }

        count++;
        const div = document.createElement('div');
        div.className = 'ss-option';
        div.dataset.value = opt.value;
        if (opt.value === select.value) div.classList.add('selected');
        div.appendChild(highlight(text, filter));
        div.addEventListener('mousedown', (e) => { e.preventDefault(); selectOption(opt.value, text); });
        optionsList.appendChild(div);
      });

      if (count === 0 && filter) {
        const noRes = document.createElement('div');
        noRes.className = 'ss-no-results';
        noRes.textContent = 'No ' + itemType + 's match "' + filter + '"';
        optionsList.appendChild(noRes);
      }
    }

    function updateDropdownPosition() {
      const rect = triggerInput.getBoundingClientRect();
      dropdown.style.position = 'fixed';
      dropdown.style.top = (rect.bottom + 4) + 'px';
      dropdown.style.left = rect.left + 'px';
      dropdown.style.width = rect.width + 'px';
      dropdown.style.zIndex = '9999999';
    }

    function openDropdown() {
      if (dropdown.parentNode !== document.body) {
        document.body.appendChild(dropdown);
      }
      updateDropdownPosition();
      wrapper.classList.add('open-active');
      dropdown.classList.add('open');
      triggerInput.classList.add('open');
      arrow.classList.add('rotated');
      searchInput.value = '';
      renderOptions('');
      setTimeout(() => searchInput.focus(), 30);
    }

    function closeDropdown() {
      wrapper.classList.remove('open-active');
      dropdown.classList.remove('open');
      triggerInput.classList.remove('open');
      arrow.classList.remove('rotated');
      searchInput.value = '';
      // Restore display text
      triggerInput.value = currentLabel();
      clearBtn.style.display = select.value ? 'block' : 'none';
    }

    function selectOption(value, label) {
      select.value = value;
      closeDropdown();
      clearBtn.style.display = value ? 'block' : 'none';
      triggerInput.value = label || allLabel;
      // Fire native change event so existing page handlers run
      select.dispatchEvent(new Event('change', { bubbles: true }));
      if (onSelect) onSelect(value);
    }

    // ── Initial state ──────────────────────────────────────────────────────────

    const initialLabel = currentLabel();
    triggerInput.value = initialLabel;
    clearBtn.style.display = select.value ? 'block' : 'none';

    // ── Events ─────────────────────────────────────────────────────────────────

    triggerInput.addEventListener('click', () => {
      if (dropdown.classList.contains('open')) {
        closeDropdown();
      } else {
        openDropdown();
      }
    });

    triggerInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown') {
        e.preventDefault();
        openDropdown();
      }
      if (e.key === 'Escape') closeDropdown();
    });

    searchInput.addEventListener('input', () => {
      renderOptions(searchInput.value);
    });

    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeDropdown();
    });

    clearBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      selectOption('', allLabel);
    });

    window.addEventListener('scroll', () => {
      if (dropdown.classList.contains('open')) {
        updateDropdownPosition();
      }
    }, { passive: true });

    window.addEventListener('resize', () => {
      if (dropdown.classList.contains('open')) {
        updateDropdownPosition();
      }
    });

    // Close when clicking outside
    document.addEventListener('mousedown', (e) => {
      if (!wrapper.contains(e.target) && !dropdown.contains(e.target)) {
        closeDropdown();
      }
    });
  }

  function autoInitAll() {
    document.querySelectorAll('select.searchable-select, select#event_select, select#eventDropdown').forEach((select) => {
      if (!select.dataset.ssInitialized) {
        initSearchableSelect(select);
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoInitAll);
  } else {
    autoInitAll();
  }

  // Expose globally
  window.initSearchableSelect = initSearchableSelect;
  window.autoInitSearchableSelects = autoInitAll;

})();
