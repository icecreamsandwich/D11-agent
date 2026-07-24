/**
 * @file
 * Datepicker behaviour for the Content Explorer "Authored on" exposed filter.
 *
 * Responsibilities:
 *  1. Enforce the min ≤ max constraint between the two datetime pickers so
 *     users cannot accidentally select an inverted range.
 *  2. On form submission, rewrite each picker value from the browser's native
 *     ISO 8601 format ("YYYY-MM-DDTHH:MM:SS") to Drupal's preferred machine-
 *     readable format ("CCYY-MM-DD HH:MM:SS") via a hidden input, keeping
 *     the visible datetime-local field intact for client-side validation.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.contentExplorerDatepicker = {
    attach(context) {

      // console.log('Attaching Content Explorer datepicker behavior');
      // Guard: run once per page load, not on every AJAX refresh.
      const [form] = once(
        'ce-datepicker',
        '#views-exposed-form-content-explorer-page-1',
        context,
      );
      if (!form) {
        return;
      }

      const minInput = form.querySelector('[name="created[min]"]');
      const maxInput = form.querySelector('[name="created[max]"]');

      if (!minInput || !maxInput) {
        return;
      }

      // ── Min/max constraint ────────────────────────────────────────────────

      minInput.addEventListener('change', () => {
        // Prevent max from being set to an earlier date than min.
        maxInput.min = minInput.value || '';
        if (maxInput.value && maxInput.value < minInput.value) {
          maxInput.value = minInput.value;
        }
      });

      maxInput.addEventListener('change', () => {
        // Prevent min from being set to a later date than max.
        minInput.max = maxInput.value || '';
        if (minInput.value && minInput.value > maxInput.value) {
          minInput.value = maxInput.value;
        }
      });

      // ── Format normalisation on submit ────────────────────────────────────
      //
      // datetime-local inputs submit "YYYY-MM-DDTHH:MM:SS" (when step=1).
      // Drupal's date filter prefers "CCYY-MM-DD HH:MM:SS" (space separator).
      //
      // Strategy: create a hidden input carrying the formatted value and
      // strip the `name` attribute from the datetime-local field so only the
      // hidden input is included in the POST payload.

      form.addEventListener('submit', () => {
        [minInput, maxInput].forEach((input) => {
          if (!input.value) {
            return;
          }

          // Replace the 'T' separator with a space.
          let formatted = input.value.replace('T', ' ');

          // Some browsers omit seconds even with step="1"; pad if needed.
          if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(formatted)) {
            formatted += ':00';
          }

          // Inject a hidden input with the corrected value.
          const hidden = document.createElement('input');
          hidden.type = 'hidden';
          hidden.name = input.name;
          hidden.value = formatted;

          // Remove the name from the picker so it does not double-submit.
          input.removeAttribute('name');

          form.appendChild(hidden);
        });
      });
    },
  };

}(Drupal, once));
