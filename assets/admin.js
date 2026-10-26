(function () {
  'use strict';
  function find(el, selector) { return el ? el.querySelector(selector) : null; }

  // Native WordPress media library picker: standard media and custom image fields.
  document.addEventListener('click', function (event) {
    var choose = event.target.closest('.bst-media-choose');
    var clear = event.target.closest('.bst-media-clear');
    var custom = event.target.closest('.bst-field-image');
    if (clear) {
      event.preventDefault();
      var control = clear.closest('.bst-media-control');
      find(control, '.bst-media-id').value = '0';
      var img = find(control, '.bst-media-preview img');
      img.src = '';
      img.hidden = true;
      return;
    }
    if (!choose && !custom) { return; }
    event.preventDefault();
    if (!window.wp || !wp.media) { return; }
    var frame = wp.media({title:'Vybrat obrázek',button:{text:'Použít obrázek'},library:{type:'image'},multiple:false});
    frame.on('select', function () {
      var attachment = frame.state().get('selection').first().toJSON();
      if (choose) {
        var control = choose.closest('.bst-media-control');
        find(control, '.bst-media-id').value = attachment.id;
        var img = find(control, '.bst-media-preview img');
        img.src = (attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url);
        img.hidden = false;
      } else {
        find(custom.closest('.bst-field-row'), '.bst-field-value').value = attachment.id;
      }
    });
    frame.open();
  });

  // Repeatable arbitrary fields. Names are indexed, and PHP validates all slugs/types.
  var list = document.getElementById('bst-fields');
  var add = document.querySelector('.bst-add-field');
  var template = document.getElementById('bst-field-template');
  var nextIndex = 0;
  if (list) {
    list.querySelectorAll('.bst-field-row input[name]').forEach(function (el) {
      var match = el.name.match(/bst\[fields\]\[(\d+)\]/);
      if (match) { nextIndex = Math.max(nextIndex, Number(match[1]) + 1); }
    });
  }
  function updateEmpty() {
    var empty = document.querySelector('.bst-fields-empty');
    if (empty && list) { empty.hidden = !!list.querySelector('.bst-field-row'); }
  }
  if (list && add && template) {
    add.addEventListener('click', function () {
      var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
      list.insertAdjacentHTML('beforeend', html);
      updateEmpty();
      var last = list.lastElementChild;
      if (last) { var input = find(last, 'input'); if (input) { input.focus(); } }
    });
    list.addEventListener('click', function (event) {
      if (event.target.closest('.bst-remove-field')) {
        event.preventDefault();
        event.target.closest('.bst-field-row').remove();
        updateEmpty();
      }
    });
    list.addEventListener('change', function (event) {
      if (event.target.matches('.bst-field-type')) {
        var row = event.target.closest('.bst-field-row');
        var button = find(row, '.bst-field-image');
        if (button) { button.hidden = event.target.value !== 'image'; }
      }
    });
  }

  document.addEventListener('click', function (event) {
    var copy = event.target.closest('[data-bst-copy]');
    if (!copy) { return; }
    event.preventDefault();
    var value = copy.getAttribute('data-bst-copy');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(function () {
        var icon = find(copy, '.dashicons');
        if (icon) { icon.classList.remove('dashicons-admin-page'); icon.classList.add('dashicons-yes'); }
      }).catch(function () {});
    } else {
      var input = document.createElement('textarea'); input.value = value; document.body.appendChild(input);
      input.select(); document.execCommand('copy'); input.remove();
    }
  });
}());

// Upgrade colours to WordPress' accessible picker rather than large browser-native controls.
if (window.jQuery && window.jQuery.fn && window.jQuery.fn.wpColorPicker) {
  window.jQuery(function ($) { $('.bst-color').wpColorPicker(); });
}

// Permanent comment deletion: explicit confirmation + short WordPress API batches.
(function () {
  'use strict';
  var buttons = document.querySelectorAll('[data-bst-purge]');
  if (!buttons.length || !window.BSTAdmin) { return; }
  var status = document.querySelector('.bst-purge-status');
  function inform(message, error) {
    if (!status) { return; }
    status.textContent = message;
    status.classList.toggle('is-error', !!error);
  }
  Array.prototype.forEach.call(buttons, function (button) {
    button.addEventListener('click', async function () {
      var scope = button.getAttribute('data-bst-purge');
      var all = scope === 'all';
      var message = all
        ? 'Nenávratně SMAZAT VŠECHNY komentáře včetně hodnocení WooCommerce, spamu, pingbacků a metadat? Toto nelze vzít zpět.'
        : 'Nenávratně smazat VŠECHNY spamové komentáře včetně jejich metadat?';
      if (!window.confirm(message)) { return; }
      if (all && window.prompt('Pro potvrzení trvalého odstranění napište SMAZAT:') !== 'SMAZAT') { return; }
      var removed = 0;
      Array.prototype.forEach.call(buttons, function (el) { el.disabled = true; });
      inform('Probíhá mazání komentářů, neopouštějte tuto stránku…', false);
      try {
        for (;;) {
          var params = new URLSearchParams({action:'bst_purge_comments',nonce:BSTAdmin.purgeNonce,scope:scope});
          var response = await fetch(BSTAdmin.ajaxUrl, {method:'POST',credentials:'same-origin',body:params});
          var result = await response.json();
          if (!response.ok || !result.success) {
            throw new Error(result.data && result.data.message ? result.data.message : 'Nepodařilo se smazat komentáře.');
          }
          removed += Number(result.data.deleted) || 0;
          var remaining = Number(result.data.remaining) || 0;
          inform('Smazáno: ' + removed + ' · Zbývá: ' + remaining, false);
          if (remaining === 0) { break; }
          if (Number(result.data.deleted) === 0) { throw new Error('Mazání se zastavilo bez pokroku.'); }
        }
        inform('Hotovo. Trvale smazáno ' + removed + ' komentářů. Pro aktualizaci počtů obnovte stránku.', false);
      } catch (error) {
        inform('Chyba: ' + error.message + ' Dosud smazáno ' + removed + ' komentářů.', true);
      } finally {
        Array.prototype.forEach.call(buttons, function (el) { el.disabled = false; });
      }
    });
  });
}());
