/**
 * @file
 * Client-side numeric/string sorting for .xp-sortable tables.
 */
(function (Drupal, once) {
  'use strict';

  function sortTable(table, colIndex, th) {
    const tbody = table.tBodies[0];
    if (!tbody) {
      return;
    }
    const rows = Array.from(tbody.rows);
    const asc = !th.classList.contains('xp-sorted-asc');

    table.querySelectorAll('th').forEach(function (h) {
      h.classList.remove('xp-sorted-asc', 'xp-sorted-desc');
    });
    th.classList.add(asc ? 'xp-sorted-asc' : 'xp-sorted-desc');

    rows.sort(function (a, b) {
      const av = a.cells[colIndex] ? a.cells[colIndex].textContent.trim() : '';
      const bv = b.cells[colIndex] ? b.cells[colIndex].textContent.trim() : '';
      const an = parseFloat(av);
      const bn = parseFloat(bv);
      let cmp;
      if (!isNaN(an) && !isNaN(bn)) {
        cmp = an - bn;
      }
      else {
        cmp = av.localeCompare(bv);
      }
      return asc ? cmp : -cmp;
    });

    rows.forEach(function (row) {
      tbody.appendChild(row);
    });
  }

  Drupal.behaviors.xhprofPocSort = {
    attach(context) {
      once('xp-sortable', 'table.xp-sortable', context).forEach(function (table) {
        table.querySelectorAll('thead th').forEach(function (th, i) {
          th.addEventListener('click', function () {
            sortTable(table, i, th);
          });
        });
      });
    }
  };
})(Drupal, once);
