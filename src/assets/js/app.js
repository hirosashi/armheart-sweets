// メニュー以外を押したら「登録・確認」を閉じる
document.addEventListener('click', function (e) {
  document.querySelectorAll('details.mainmenu-drop[open]').forEach(function (d) {
    if (!d.contains(e.target)) { d.removeAttribute('open'); }
  });
});

// 検索つきプルダウン（<select class="select-search"> を、文字で絞り込める入力欄に置き換える）
(function () {
  function normalize(s) {
    return (s || '').toString().toLowerCase()
      .replace(/[\u30a1-\u30f6]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0x60); }) // カナ→かな
      .replace(/[\uff01-\uff5e]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xfee0); }) // 全角英数→半角
      .replace(/\s+/g, '');
  }

  function enhance(select) {
    var options = Array.prototype.slice.call(select.options);
    var placeholderOpt = options.length && options[0].value === '' ? options[0] : null;
    var items = options.filter(function (o) { return o.value !== ''; });

    var wrap = document.createElement('div');
    wrap.className = 'ss-wrap';
    if (select.classList.contains('w-400')) { wrap.classList.add('w-400'); }
    if (select.classList.contains('w-300')) { wrap.classList.add('w-300'); }

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'ss-input';
    input.autocomplete = 'off';
    input.placeholder = (placeholderOpt ? placeholderOpt.text.replace(/^-+\s*|\s*-+$/g, '') : '選ぶ') + '（文字で絞り込み）';
    input.setAttribute('aria-label', input.placeholder);

    var clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'ss-clear';
    clear.title = '選択を解除';
    clear.textContent = '×';

    var list = document.createElement('ul');
    list.className = 'ss-list';
    list.hidden = true;

    select.hidden = true;
    select.tabIndex = -1;
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(input);
    wrap.appendChild(clear);
    wrap.appendChild(list);
    wrap.appendChild(select);

    var active = -1;
    var shown = [];

    function syncFromSelect() {
      var o = select.options[select.selectedIndex];
      input.value = (o && o.value !== '') ? o.text : '';
      wrap.classList.toggle('ss-selected', !!(o && o.value !== ''));
    }

    function choose(o) {
      select.value = o.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      syncFromSelect();
      close();
    }

    function close() { list.hidden = true; active = -1; }

    function render(keyword) {
      var kw = normalize(keyword);
      shown = items.filter(function (o) { return kw === '' || normalize(o.text).indexOf(kw) !== -1; });
      list.innerHTML = '';
      if (shown.length === 0) {
        var li = document.createElement('li');
        li.className = 'ss-empty';
        li.textContent = '該当がありません';
        list.appendChild(li);
      }
      shown.slice(0, 200).forEach(function (o, i) {
        var li = document.createElement('li');
        li.textContent = o.text;
        li.dataset.index = i;
        if (o.value === select.value) { li.classList.add('ss-current'); }
        li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(o); });
        list.appendChild(li);
      });
      if (shown.length > 200) {
        var more = document.createElement('li');
        more.className = 'ss-empty';
        more.textContent = 'ほか ' + (shown.length - 200) + ' 件（さらに文字を入れて絞り込んでください）';
        list.appendChild(more);
      }
      active = -1;
      list.hidden = false;
    }

    function highlight(n) {
      var lis = list.querySelectorAll('li:not(.ss-empty)');
      if (!lis.length) { return; }
      active = (n + lis.length) % lis.length;
      lis.forEach(function (li, i) { li.classList.toggle('ss-active', i === active); });
      lis[active].scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('focus', function () { input.select(); render(''); });
    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (list.hidden) { render(input.value); } highlight(active + 1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(active - 1); }
      else if (e.key === 'Enter') {
        if (!list.hidden) {
          e.preventDefault();
          if (active >= 0 && shown[active]) { choose(shown[active]); }
          else if (shown.length === 1) { choose(shown[0]); }
        }
      }
      else if (e.key === 'Escape') { close(); syncFromSelect(); }
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        close();
        // 入力途中で離れたら、選択中の値に表示を戻す
        syncFromSelect();
      }, 150);
    });
    clear.addEventListener('click', function () {
      select.value = '';
      select.dispatchEvent(new Event('change', { bubbles: true }));
      syncFromSelect();
      input.focus();
    });

    // required の select は、値が空のまま送信できないようにする
    if (select.required) {
      select.form && select.form.addEventListener('submit', function (e) {
        if (select.value === '') {
          e.preventDefault();
          input.focus();
          input.classList.add('ss-error');
          setTimeout(function () { input.classList.remove('ss-error'); }, 1500);
        }
      });
    }

    syncFromSelect();
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('select.select-search').forEach(enhance);
  });
})();
