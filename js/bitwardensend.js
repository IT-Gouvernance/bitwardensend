/**
 * -------------------------------------------------------------------------
 * Bitwarden Send plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Bitwarden Send.
 *
 * Bitwarden Send is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Bitwarden Send is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Bitwarden Send. If not, see <https://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 *
 * @copyright Copyright (C) 2026 by IT Gouvernance.
 * @license   GPLv3+ https://www.gnu.org/licenses/gpl-3.0.html
 * @link      https://github.com/IT-Gouvernance/bitwardensend/
 * -------------------------------------------------------------------------
 */

/* global glpi_ajax_dialog, bootstrap */
(function () {
   if (window.pluginBitwardensendLoaded) {
      return;
   }
   window.pluginBitwardensendLoaded = true;

   /**
    * Open the Bitwarden Send creation dialog.
    */
   window.pluginBitwardensendDialog = function (itemtype, items_id) {
      var url = CFG_GLPI.root_doc + '/plugins/bitwardensend/ajax/form.php';
      // Both the glpi_ajax_dialog title and the fallback modal's <h5> insert
      // this as raw HTML, not escaped text.
      var title = '<i class="ti ti-shield-lock me-2"></i>Bitwarden Send';

      if (typeof glpi_ajax_dialog === 'function') {
         glpi_ajax_dialog({
            url: url,
            params: { itemtype: itemtype, items_id: items_id },
            dialogclass: 'modal-lg',
            title: title
         });
         window.pluginBitwardensendStartFollowupPreviewPolling();
         return;
      }

      // Fallback when the GLPI dialog helper is not available.
      var id = 'plugin-bitwardensend-modal';
      var existing = document.getElementById(id);
      if (existing) {
         existing.remove();
      }

      var wrapper = document.createElement('div');
      wrapper.innerHTML =
         '<div class="modal fade" id="' + id + '" tabindex="-1">' +
         '  <div class="modal-dialog modal-lg modal-dialog-scrollable">' +
         '    <div class="modal-content">' +
         '      <div class="modal-header">' +
         '        <h5 class="modal-title">' + title + '</h5>' +
         '        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
         '      </div>' +
         '      <div class="modal-body">' +
         '        <div class="text-center p-4"><span class="spinner-border"></span></div>' +
         '      </div>' +
         '    </div>' +
         '  </div>' +
         '</div>';
      document.body.appendChild(wrapper.firstChild);

      var modalEl = document.getElementById(id);
      new bootstrap.Modal(modalEl).show();

      var query = 'itemtype=' + encodeURIComponent(itemtype) + '&items_id=' + encodeURIComponent(items_id);
      fetch(url + '?' + query, { credentials: 'same-origin' })
         .then(function (response) { return response.text(); })
         .then(function (html) {
            modalEl.querySelector('.modal-body').innerHTML = html;
            // A <script> tag set through innerHTML never runs, unlike the
            // glpi_ajax_dialog path above (also means GLPI's own TinyMCE
            // bootstrap script for the followup field won't run here either —
            // it stays a plain textarea in this fallback, still functional).
            window.pluginBitwardensendStartFollowupPreviewPolling();
         })
         .catch(function () {
            modalEl.querySelector('.modal-body').innerHTML =
               '<div class="alert alert-danger">Could not load the form.</div>';
         });
   };

   /**
    * Confirm a destructive action in a GLPI-styled modal, then submit the
    * surrounding form. Labels come from data attributes so the strings stay
    * translatable in Twig.
    *
    * Built on Bootstrap directly rather than through the core glpi_confirm()
    * helper: the markup here is the same Tabler/Bootstrap the rest of GLPI uses,
    * and the callback is guaranteed to run. To use the core helper instead,
    * replace the body with:
    *    glpi_confirm({title: title, message: message, confirm_callback: submit});
    */
   window.pluginBitwardensendConfirm = function (button) {
      var form = button.closest('form');
      if (!form) {
         return;
      }

      var title   = button.dataset.bwsTitle || 'Confirm';
      var message = button.dataset.bwsMessage || '';
      var okLabel = button.dataset.bwsConfirm || 'OK';
      var koLabel = button.dataset.bwsCancel || 'Cancel';

      var submit = function () {
         form.submit();
      };

      if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
         // No Bootstrap: better a native prompt than no confirmation at all.
         if (window.confirm(message ? title + '\n\n' + message : title)) {
            submit();
         }
         return;
      }

      var id = 'plugin-bitwardensend-confirm';
      var previous = document.getElementById(id);
      if (previous) {
         previous.remove();
      }

      var modalEl = document.createElement('div');
      modalEl.className = 'modal fade';
      modalEl.id = id;
      modalEl.tabIndex = -1;
      modalEl.innerHTML =
         '<div class="modal-dialog modal-sm modal-dialog-centered">' +
         '  <div class="modal-content">' +
         '    <div class="modal-header">' +
         '      <h5 class="modal-title"></h5>' +
         '      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
         '    </div>' +
         '    <div class="modal-body"></div>' +
         '    <div class="modal-footer">' +
         '      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"></button>' +
         '      <button type="button" class="btn btn-danger"></button>' +
         '    </div>' +
         '  </div>' +
         '</div>';

      // textContent, not innerHTML: the labels are translated strings, not markup.
      modalEl.querySelector('.modal-title').textContent = title;
      modalEl.querySelector('.modal-body').textContent = message;

      var buttons = modalEl.querySelectorAll('.modal-footer button');
      buttons[0].textContent = koLabel;
      buttons[1].textContent = okLabel;

      document.body.appendChild(modalEl);

      var modal = new bootstrap.Modal(modalEl);
      var confirmed = false;

      buttons[1].addEventListener('click', function () {
         confirmed = true;
         modal.hide();
      });

      modalEl.addEventListener('hidden.bs.modal', function () {
         modalEl.remove();
         if (confirmed) {
            submit();
         }
      });

      modal.show();
      buttons[1].focus();
   };

   /**
    * Show only the connection fields for the selected Send driver (cli vs
    * native). Rows opt in with data-bws-driver="cli", "native", or a space
    * separated list.
    *
    * Hidden inputs are still submitted, deliberately: switching drivers to
    * look around does not wipe the other driver's settings.
    */
   window.pluginBitwardensendToggleSendDriver = function () {
      var select = document.getElementById('cfg_send_driver');
      if (!select) {
         return;
      }

      var driver = select.value;
      var rows = document.querySelectorAll('[data-bws-driver]');

      Array.prototype.forEach.call(rows, function (row) {
         var applies = row.getAttribute('data-bws-driver').split(/\s+/);
         row.style.display = applies.indexOf(driver) === -1 ? 'none' : '';
      });

      window.pluginBitwardensendUpdateRequiredFields();
   };

   /**
    * Keep the "required" property in sync with actual visibility.
    *
    * A field can carry the HTML required attribute while sitting inside a
    * data-bws-driver row that is currently display:none — the
    * browser still counts it during constraint validation (display:none does
    * NOT exempt a field from that, only e.g. type="hidden" or disabled do),
    * but can't focus it to report the error, so it just logs "An invalid
    * form control is not focusable" to the console and silently refuses to
    * submit. Fields opt in with data-bws-required="1" (or "0" for a secret
    * that is only required until a value is already stored server-side);
    * this runs after every visibility change so only the fields actually
    * showing on screen can ever carry "required".
    */
   window.pluginBitwardensendUpdateRequiredFields = function () {
      var fields = document.querySelectorAll('[data-bws-required]');

      Array.prototype.forEach.call(fields, function (field) {
         var wanted = field.getAttribute('data-bws-required') === '1';
         field.required = wanted && field.offsetParent !== null;
      });
   };

   /**
    * Copy a link to the clipboard.
    */
   window.pluginBitwardensendCopy = function (button, value) {
      var done = function () {
         var icon = button.querySelector('i');
         if (!icon) {
            return;
         }
         var previous = icon.className;
         icon.className = 'ti ti-check text-green';
         window.setTimeout(function () { icon.className = previous; }, 1500);
      };

      if (navigator.clipboard && window.isSecureContext) {
         navigator.clipboard.writeText(value).then(done);
         return;
      }

      var field = document.createElement('textarea');
      field.value = value;
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      document.execCommand('copy');
      field.remove();
      done();
   };

   /**
    * A random string drawn from charset, length characters long. Uses
    * crypto.getRandomValues rather than Math.random — these fill fields
    * meant to be secrets.
    */
   function pluginBitwardensendRandomString(charset, length) {
      var randomValues = new Uint32Array(length);
      window.crypto.getRandomValues(randomValues);

      var value = '';
      for (var i = 0; i < length; i++) {
         value += charset[randomValues[i] % charset.length];
      }
      return value;
   }

   /**
    * Fill the Link password field with a random password.
    *
    * Fixed charset and length, unlike the "Content to share" generator
    * below: this password only ever needs to be read once, over the phone
    * or a text message per the field's own hint, so it excludes visually
    * ambiguous characters (0/O, 1/l/I) instead of offering the same
    * character-class options.
    */
   window.pluginBitwardensendGeneratePassword = function () {
      var field = document.getElementById('bws_password');
      if (!field) {
         return;
      }

      var charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_=+';
      field.value = pluginBitwardensendRandomString(charset, 20);
      field.dispatchEvent(new Event('input', { bubbles: true }));
   };

   /**
    * Fill the "Content to share" field with a random string built from the
    * generator panel's own length and character-class checkboxes — unlike
    * the Link password generator, this content can be anything from an API
    * key to a service password with its own character rules, so the
    * technician picks what to include rather than a fixed recipe.
    */
   window.pluginBitwardensendGenerateSecret = function () {
      var field = document.getElementById('bws_secret');
      if (!field) {
         return;
      }

      var lengthInput = document.getElementById('bws_secret_gen_length');
      var length = parseInt(lengthInput && lengthInput.value, 10) || 20;
      length = Math.min(128, Math.max(4, length));

      var classes = [
         { id: 'bws_secret_gen_upper', chars: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ' },
         { id: 'bws_secret_gen_lower', chars: 'abcdefghijklmnopqrstuvwxyz' },
         { id: 'bws_secret_gen_numbers', chars: '0123456789' },
         { id: 'bws_secret_gen_symbols', chars: '!@#$%^&*()-_=+[]{}<>?' }
      ];

      var charset = classes.reduce(function (chars, charClass) {
         var checkbox = document.getElementById(charClass.id);
         return (checkbox && checkbox.checked) ? chars + charClass.chars : chars;
      }, '');

      // Every class unchecked would otherwise generate nothing at all —
      // letters and digits is a friendlier fallback than refusing to act.
      if (!charset) {
         charset = classes[0].chars + classes[1].chars + classes[2].chars;
      }

      field.value = pluginBitwardensendRandomString(charset, length);
      field.dispatchEvent(new Event('input', { bubbles: true }));
   };

   /**
    * Refresh the followup preview, substituting {expiration}/{max_access}
    * with the current form values. {url} stays a placeholder — the real
    * link doesn't exist until the Send is created.
    *
    * Translated strings come through data-bws-* attributes on
    * #bws_followup_block, not hardcoded (no __() in plain JS).
    *
    * Handles both a TinyMCE editor (used as-is, already HTML) and a plain
    * <textarea> fallback (escaped first, since it's user-typed text).
    */
   window.pluginBitwardensendUpdateFollowupPreview = function () {
      var block = document.getElementById('bws_followup_block');
      var preview = document.getElementById('bws_followup_preview');
      var daysSelect = document.getElementById('bws_days');
      var maxAccessInput = document.getElementById('bws_max_access');
      var textarea = document.getElementById('bws_followup');
      if (!block || !preview || !daysSelect || !maxAccessInput || !textarea) {
         return;
      }

      var editor = (window.tinymce && window.tinymce.get) ? window.tinymce.get('bws_followup') : null;

      var escapeHtml = function (value) {
         var div = document.createElement('div');
         div.textContent = value;
         return div.innerHTML;
      };

      var days = parseInt(daysSelect.value, 10) || 0;
      var maxAccess = parseInt(maxAccessInput.value, 10) || 0;
      var expiration = new Date(Date.now() + days * 86400000).toLocaleString();
      var maxAccessText = maxAccess > 0 ? String(maxAccess) : block.dataset.bwsUnlimited;
      // {url} in the actual followup becomes a whole clickable link, so its
      // preview gets an <em> wrapper too — but {url_raw} exists specifically
      // to be dropped inside a raw href="..." (a custom link, e.g. from a
      // GLPI followup template with its own wording), where any tag would
      // break the markup exactly like {url} would. Its preview stays plain
      // escaped text for the same reason.
      var urlPlaceholderText = escapeHtml(block.dataset.bwsLinkPlaceholder);
      var urlHtml = '<em class="text-secondary">' + urlPlaceholderText + '</em>';

      var html = editor ? editor.getContent() : escapeHtml(textarea.value).replace(/\n/g, '<br>');
      // Must come before the bare '{url_raw}' split below, for the same reason
      // as in Send::addFollowup() server-side: it would otherwise consume the
      // token this one still needs to match.
      html = html.split('https://bitwardensend.invalid/{url_raw}').join(urlPlaceholderText);
      html = html.split('{url}').join(urlHtml);
      html = html.split('{url_raw}').join(urlPlaceholderText);
      html = html.split('{expiration}').join(escapeHtml(expiration));
      html = html.split('{max_access}').join(escapeHtml(maxAccessText));

      preview.innerHTML = html;

      // Lazily capture the "nothing edited yet" baseline for the followup
      // template selector's overwrite confirmation, the first time the rich
      // text editor is actually up (see pluginBitwardensendApplyFollowupTemplate
      // for why this only ever happens through the editor, never the plain
      // textarea fallback).
      var templateSelect = document.getElementById('bws_followup_template');
      if (templateSelect) {
         // The <select> itself is capped to a narrow width (see .bws-side-control
         // in css/bitwardensend.css) so a long GLPI template name gets clipped
         // with no way to read it — mirroring the selected option's own text
         // onto the element's title gives that back as a native hover tooltip.
         var selectedOption = templateSelect.options[templateSelect.selectedIndex];
         templateSelect.title = selectedOption ? selectedOption.textContent.trim() : '';

         if (templateSelect._bwsBaseline === undefined && editor) {
            templateSelect._bwsBaseline = editor.getContent();
         }
      }
   };

   /**
    * Current followup content as TinyMCE sees it, or null before it's
    * initialized. Not the raw <textarea> value: TinyMCE reformats markup on
    * load, so comparing a pre-init baseline to a post-init textarea value
    * would falsely look like an edit. Always read through this same
    * accessor for both sides of that comparison.
    */
   function pluginBitwardensendGetFollowupEditorContent() {
      var editor = (window.tinymce && window.tinymce.get) ? window.tinymce.get('bws_followup') : null;
      return editor ? editor.getContent() : null;
   }

   /**
    * Load the selected followup template — this plugin's own default, or one
    * of GLPI's followup templates ("gabarits de suivi") — into the followup
    * editor, replacing its current content.
    *
    * Asks for confirmation first if the field holds edits made since the last
    * template was applied (or since the dialog opened). Silently replaces
    * otherwise — including when running on the plain <textarea> fallback,
    * where that check cannot be done reliably (see
    * pluginBitwardensendGetFollowupEditorContent): no worse than before this
    * selector existed.
    *
    * The {url}/{expiration}/{max_access} substitution happens server-side at
    * submission time, on whatever text ends up in the field: a GLPI template
    * using those same placeholders works exactly like the plugin's own
    * default, no special-casing needed here.
    */
   window.pluginBitwardensendApplyFollowupTemplate = function (select) {
      var editorContent = pluginBitwardensendGetFollowupEditorContent();

      if (editorContent !== null && select._bwsBaseline !== undefined && editorContent !== select._bwsBaseline) {
         var confirmMessage = select.dataset.bwsConfirmReplace || 'Replace the current text?';
         if (!window.confirm(confirmMessage)) {
            // Revert the dropdown: the field itself was never touched.
            select.value = select._bwsLastValue || '';
            return;
         }
      }

      var option = select.options[select.selectedIndex];
      var content = option ? (option.getAttribute('data-bws-content') || '') : '';
      var editor = (window.tinymce && window.tinymce.get) ? window.tinymce.get('bws_followup') : null;
      var textarea = document.getElementById('bws_followup');

      if (editor) {
         editor.setContent(content);
         select._bwsBaseline = editor.getContent();
      } else if (textarea) {
         textarea.value = content;
      }

      select._bwsLastValue = select.value;

      window.pluginBitwardensendUpdateFollowupPreview();
   };

   /**
    * TinyMCE renders its editable area in an <iframe>: keystrokes inside it
    * never bubble up to this document, so the delegated 'input' listener
    * below cannot see them. Polling is simpler and more robust than hooking
    * TinyMCE's own event API from here, given the editor initializes
    * asynchronously (via a <script> GLPI injects when the field first
    * renders) and this file has no reliable signal for exactly when that
    * finishes.
    *
    * Exported on window (unlike the other internal-only helpers below):
    * pluginBitwardensendDialog starts it for the tab's modal, and the
    * timeline's inline answer_form.html.twig — which never goes through
    * that function — calls it directly from its own inline <script>.
    */
   var followupPreviewInterval = null;

   window.pluginBitwardensendStartFollowupPreviewPolling = function () {
      if (followupPreviewInterval !== null) {
         window.clearInterval(followupPreviewInterval);
      }
      followupPreviewInterval = window.setInterval(window.pluginBitwardensendUpdateFollowupPreview, 800);
   };

   /**
    * Hide the followup text area when posting a followup is turned off, react to
    * the Send driver selector, and load a followup template when one is picked.
    * Delegated, so it works on markup injected later by a tab load.
    */
   document.addEventListener('change', function (event) {
      if (!event.target) {
         return;
      }

      if (event.target.id === 'cfg_send_driver') {
         window.pluginBitwardensendToggleSendDriver();
         return;
      }

      if (event.target.id === 'bws_add_followup') {
         var block = document.getElementById('bws_followup_block');
         // #bws_followup_private_row is a child of this block now (the
         // compact icon+switch next to the followup text, not a separate
         // row above it), so hiding the block already hides it — no need to
         // toggle it separately here anymore.
         if (block) {
            // A class toggle, not style.display: this block also carries
            // Bootstrap/Tabler's "d-flex" utility class, which is itself
            // !important and would otherwise keep overriding a plain inline
            // display style regardless of what this sets it to. See
            // css/bitwardensend.css for the matching #id.bws-d-none rule.
            block.classList.toggle('bws-d-none', !event.target.checked);
         }
         return;
      }

      if (event.target.id === 'bws_followup_template') {
         window.pluginBitwardensendApplyFollowupTemplate(event.target);
         return;
      }

      if (['bws_followup', 'bws_days', 'bws_max_access'].indexOf(event.target.id) !== -1) {
         window.pluginBitwardensendUpdateFollowupPreview();
      }
   });

   // 'change' alone only fires on blur for text inputs/textareas: 'input' keeps
   // the preview in sync while typing.
   document.addEventListener('input', function (event) {
      if (event.target && ['bws_followup', 'bws_days', 'bws_max_access'].indexOf(event.target.id) !== -1) {
         window.pluginBitwardensendUpdateFollowupPreview();
      }
   });

   /**
    * Guard against a double submit: creating a Send calls out to the
    * Bitwarden API, which can be slow enough for an impatient second click to
    * land before the page has navigated away, creating a second Send.
    * Delegated, so it covers both places this form is shown (the tab's own
    * modal and the timeline's inline answer form).
    */
   document.addEventListener('submit', function (event) {
      var form = event.target;
      if (!form.classList || !form.classList.contains('plugin-bitwardensend-form')) {
         return;
      }

      var button = form.querySelector('button[name="create_send"]');
      if (!button || button.disabled) {
         return;
      }

      // Deferred rather than disabled right away: some browsers exclude a
      // disabled control's name/value pair from a submission already in
      // flight if it is disabled synchronously within its own submit
      // handler, which would drop create_send from the POST data.
      window.setTimeout(function () {
         button.disabled = true;
         var icon = button.querySelector('i');
         if (icon) {
            icon.outerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>';
         }
      }, 0);
   });

   // Delegated so a click on the icon inside a button (not the button
   // itself) still counts — closest() reaches the button either way.
   document.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('#bws_generate_password')) {
         window.pluginBitwardensendGeneratePassword();
      }
      if (event.target.closest && event.target.closest('#bws_generate_secret')) {
         window.pluginBitwardensendGenerateSecret();
      }
   });
})();
