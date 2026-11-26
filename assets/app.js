(function () {
  // Transaction form: switch categories / labels by type, date chips
  document.querySelectorAll('form.txnform').forEach(function (f) {
    var cats = { expense: JSON.parse(f.dataset.catsExpense || '[]'), income: JSON.parse(f.dataset.catsIncome || '[]') };
    var sel = f.querySelector('select[name=category]');
    var from = f.querySelector('.lbl-from');
    var date = f.querySelector('input[name=date]');
    var current = { expense: null, income: null };
    function type() { var r = f.querySelector('input[name=type]:checked'); return r ? r.value : 'expense'; }
    var last = type();
    current[last === 'income' ? 'income' : 'expense'] = sel.value;
    function apply() {
      var t = type();
      f.classList.toggle('is-transfer', t === 'transfer');
      from.textContent = t === 'income' ? 'Received into' : (t === 'transfer' ? 'From' : 'Paid from');
      if (t !== 'transfer' && t !== last && (last !== 'transfer' || true)) {
        var list = cats[t];
        var want = current[t] || list[0];
        if (Array.from(sel.options).map(function (o) { return o.value; }).join('|') !== list.join('|')) {
          sel.innerHTML = '';
          list.forEach(function (c) { var o = document.createElement('option'); o.textContent = c; if (c === want) o.selected = true; sel.appendChild(o); });
        }
      }
      if (t !== 'transfer') last = t;
      chips();
    }
    sel.addEventListener('change', function () { current[type() === 'income' ? 'income' : 'expense'] = sel.value; });
    f.querySelectorAll('input[name=type]').forEach(function (r) { r.addEventListener('change', apply); });
    function chips() {
      f.querySelectorAll('.chips button').forEach(function (b) { b.classList.toggle('on', b.dataset.date === date.value); });
    }
    f.querySelectorAll('.chips button').forEach(function (b) {
      b.addEventListener('click', function () { date.value = b.dataset.date; chips(); });
    });
    date.addEventListener('change', chips);
    apply();
  });

  // Account form: show card fields only for credit cards
  document.querySelectorAll('form.acctform').forEach(function (f) {
    var t = f.querySelector('select[name=type]'), box = f.querySelector('.cardonly');
    function sync() { box.style.display = t.value === 'card' ? '' : 'none'; }
    t.addEventListener('change', sync); sync();
  });

  // Confirm destructive forms
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
  });

  // Auto-submit filters
  document.querySelectorAll('[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // Hide toast after a few seconds
  var toast = document.querySelector('.toast');
  if (toast) setTimeout(function () { toast.style.transition = 'opacity .4s'; toast.style.opacity = '0'; }, 4000);
})();
