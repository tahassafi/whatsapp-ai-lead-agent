/* ============================================================================
   WEBSITE CHATBOT WIDGET
   Talks to /api/chat.php (SSE streaming). No dependencies.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.getElementById('ai-chat-widget');
  if (!root) return;

  var ENDPOINT = root.getAttribute('data-endpoint') || '/api/chat.php';
  var WHATSAPP = root.getAttribute('data-whatsapp') || '';
  var SITENAME = root.getAttribute('data-sitename') || 'our showroom';
  var LS_TOKEN = 'dcb_token';
  var LS_HIST  = 'dcb_history';
  var LS_SEEN  = 'dcb_seen';
  var LS_VISITOR = 'dcb_visitor';
  var LS_STARTED = 'dcb_started';
  var CHAT_TTL = 24 * 60 * 60 * 1000;   // conversations reset after 24 hours

  function getVisitor() {
    try { return JSON.parse(localStorage.getItem(LS_VISITOR) || 'null'); } catch (e) { return null; }
  }

  /* Expire old conversations: clear the thread but keep the visitor verified */
  try {
    var startedAt = parseInt(localStorage.getItem(LS_STARTED) || '0', 10);
    if (startedAt && Date.now() - startedAt > CHAT_TTL) {
      localStorage.removeItem(LS_TOKEN);
      localStorage.removeItem(LS_HIST);
      localStorage.removeItem(LS_STARTED);
      history = [];
    }
  } catch (e) {}

  var busy = false;
  var history = [];      // [{role:'user'|'bot'|'cars'|'wa', content:string|array}]
  try { history = JSON.parse(localStorage.getItem(LS_HIST) || '[]'); } catch (e) { history = []; }

  /* ---- DOM ---------------------------------------------------------------- */

  var chatSvg = '<svg viewBox="0 0 24 24"><path d="M12 3C6.5 3 2 6.8 2 11.5c0 2.6 1.4 5 3.7 6.6-.1.9-.5 2.3-1.6 3.4 0 0 2.4-.1 4.5-1.6 1.1.3 2.2.5 3.4.5 5.5 0 10-3.7 10-8.4S17.5 3 12 3zm-5 9.7c-.7 0-1.2-.5-1.2-1.2S6.3 10.3 7 10.3s1.2.5 1.2 1.2-.5 1.2-1.2 1.2zm5 0c-.7 0-1.2-.5-1.2-1.2s.5-1.2 1.2-1.2 1.2.5 1.2 1.2-.5 1.2-1.2 1.2zm5 0c-.7 0-1.2-.5-1.2-1.2s.5-1.2 1.2-1.2 1.2.5 1.2 1.2-.5 1.2-1.2 1.2z"/></svg>';
  var waSvg = '<svg viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12c0 1.8.5 3.5 1.3 5L2 22l5.2-1.3c1.4.8 3.1 1.3 4.8 1.3 5.5 0 10-4.5 10-10S17.5 2 12 2zm5.9 14.1c-.3.7-1.4 1.3-2 1.4-.5.1-1.1.1-1.8-.1-.4-.1-.9-.3-1.6-.6-2.8-1.2-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.8s.7-2 .9-2.3c.3-.3.6-.4.8-.4h.6c.2 0 .4 0 .6.5.2.5.7 1.8.8 1.9.1.2.1.3 0 .5-.1.2-.1.3-.3.5l-.4.5c-.1.2-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.5 1.5.3.1.4.1.6-.1.1-.2.6-.7.8-1 .2-.3.4-.2.6-.1.2.1 1.5.7 1.7.8.2.1.4.2.4.3.1.2.1.7-.2 1.4z"/></svg>';

  var launcher = document.createElement('button');
  launcher.className = 'dcb-launcher';
  launcher.setAttribute('aria-label', 'Chat with our AI assistant');
  launcher.innerHTML =
    '<span class="dcb-launcher-icon">' + chatSvg + '</span>' +
    '<span class="dcb-launcher-label">Chat with our AI</span>' +
    '<span class="dcb-badge">1</span>';

  var panel = document.createElement('div');
  panel.className = 'dcb-panel';
  panel.innerHTML =
    '<div class="dcb-head">' +
      '<div class="dcb-head-avatar">' + chatSvg + '</div>' +
      '<div class="dcb-head-titles"><strong>AI Assistant</strong>' +
        '<span><i class="dcb-dot"></i>Online &middot; any language</span></div>' +
      (WHATSAPP ? '<button type="button" class="dcb-head-wa" title="Continue on WhatsApp">' + waSvg + '</button>' : '') +
      '<button type="button" class="dcb-head-close" title="Close">' +
        '<svg viewBox="0 0 24 24"><path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7 2.9 18.3 9.2 12 2.9 5.7l1.4-1.4 6.3 6.3 6.3-6.3z"/></svg></button>' +
    '</div>' +
    '<div class="dcb-msgs"></div>' +
    '<div class="dcb-inputrow">' +
      '<textarea class="dcb-input" rows="1" placeholder="Ask about any car..." maxlength="2000"></textarea>' +
      '<button type="button" class="dcb-send" aria-label="Send">' +
        '<svg viewBox="0 0 24 24"><path d="M2 21 23 12 2 3v7l15 2-15 2v7z"/></svg></button>' +
    '</div>' +
    '<div class="dcb-note">AI assistant &ndash; the sales team confirms final prices.</div>';

  root.appendChild(launcher);
  root.appendChild(panel);

  var msgs  = panel.querySelector('.dcb-msgs');
  var input = panel.querySelector('.dcb-input');
  var send  = panel.querySelector('.dcb-send');

  /* ---- Helpers -------------------------------------------------------------- */

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* escape first, then bold + safe links + bullets */
  function md(s) {
    var h = esc(s);
    h = h.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    h = h.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+|\/[^\s)]*)\)/g,
      function (m, txt, url) { return '<a href="' + url + '" target="_blank" rel="noopener">' + txt + '</a>'; });
    h = h.replace(/^[-•]\s+/gm, '<span class="dcb-bullet">•</span> ');
    return h;
  }

  function scrollDown() { msgs.scrollTop = msgs.scrollHeight; }

  function saveHistory() {
    try { localStorage.setItem(LS_HIST, JSON.stringify(history.slice(-60))); } catch (e) {}
  }

  function addBubble(role, html) {
    var el = document.createElement('div');
    el.className = 'dcb-msg ' + (role === 'user' ? 'dcb-user' : 'dcb-bot');
    el.innerHTML = html;
    msgs.appendChild(el);
    scrollDown();
    return el;
  }

  function addCars(items) {
    var wrap = document.createElement('div');
    wrap.className = 'dcb-cars';
    items.forEach(function (c) {
      var a = document.createElement('a');
      a.className = 'dcb-car';
      a.href = c.url || '#';
      a.target = '_blank';
      a.rel = 'noopener';
      a.innerHTML =
        (c.image ? '<img loading="lazy" src="' + esc(c.image) + '" alt="' + esc(c.title) + '">' : '') +
        '<div class="dcb-car-body"><div class="dcb-car-title">' + esc(c.title) + '</div>' +
        '<div class="dcb-car-price">' + esc(c.price || '') + '</div>' +
        '<div class="dcb-car-meta">' + esc([c.year, c.mileage, c.color].filter(Boolean).join(' · ')) + '</div></div>';
      wrap.appendChild(a);
    });
    msgs.appendChild(wrap);
    scrollDown();
  }

  function addWhatsApp(text) {
    if (!WHATSAPP) return;
    var a = document.createElement('a');
    a.className = 'dcb-wa';
    a.target = '_blank';
    a.rel = 'noopener';
    a.href = 'https://wa.me/' + WHATSAPP + '?text=' + encodeURIComponent(text ||
      'Hello ' + SITENAME + ', I was chatting with your website assistant and would like to continue here.');
    a.innerHTML = waSvg + 'Continue on WhatsApp';
    msgs.appendChild(a);
    scrollDown();
  }

  function addChips(list) {
    var wrap = document.createElement('div');
    wrap.className = 'dcb-chips';
    list.forEach(function (c) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'dcb-chip';
      b.textContent = c.label;
      b.addEventListener('click', function () {
        wrap.remove();
        if (c.wa) { addWhatsApp(); return; }
        sendMessage(c.send);
      });
      wrap.appendChild(b);
    });
    msgs.appendChild(wrap);
    scrollDown();
  }

  function typing() {
    var el = document.createElement('div');
    el.className = 'dcb-msg dcb-bot';
    el.innerHTML = '<span class="dcb-typing"><i></i><i></i><i></i></span>';
    msgs.appendChild(el);
    scrollDown();
    return el;
  }

  /* ---- First open: greeting + quick replies ----------------------------------- */

  function renderHistory() {
    msgs.innerHTML = '';
    history.forEach(function (m) {
      if (m.role === 'cars') { addCars(m.content); }
      else if (m.role === 'wa') { addWhatsApp(); }
      else { addBubble(m.role, md(m.content)); }
    });
  }

  /* ---- Pre-chat gate: name + phone/email before the AI starts ------------------ */

  function showGate() {
    input.disabled = true;
    send.disabled = true;
    addBubble('bot', md('Welcome to **' + SITENAME + '** 👋\n' +
      'Before we chat, please leave your details below — a quick one-time check that keeps bots and spam out.'));

    var countries = window.DCB_COUNTRIES || [{ i: 'AE', n: 'United Arab Emirates', c: '+971', f: '🇦🇪' }];
    var options = '';
    countries.forEach(function (c) {
      options += '<option value="' + esc(c.c) + '"' + (c.i === 'AE' ? ' selected' : '') + '>' +
                 c.f + ' ' + esc(c.n) + ' (' + esc(c.c) + ')</option>';
    });

    var f = document.createElement('form');
    f.className = 'dcb-form';
    f.innerHTML =
      '<input class="dcb-f-in" name="dcbname" placeholder="Your name *" maxlength="100" autocomplete="name">' +
      '<div class="dcb-f-phonerow">' +
        '<select class="dcb-f-in dcb-f-cc" name="dcbcc" aria-label="Country code">' + options + '</select>' +
        '<input class="dcb-f-in dcb-f-phone" name="dcbphone" placeholder="Phone / WhatsApp *" maxlength="20" autocomplete="tel" inputmode="tel">' +
      '</div>' +
      '<input class="dcb-f-hp" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">' +
      '<div class="dcb-f-err"></div>' +
      '<button type="submit" class="dcb-f-btn">Start chat</button>' +
      '<div class="dcb-f-note">Your name and phone number are required.</div>';
    msgs.appendChild(f);
    scrollDown();
    f.querySelector('[name=dcbname]').focus();

    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var name = f.dcbname.value.trim();
      var cc   = f.dcbcc.value;
      var err  = f.querySelector('.dcb-f-err');
      /* national number: digits only, drop any leading zeros */
      var national = f.dcbphone.value.replace(/\D/g, '').replace(/^0+/, '');
      if (!name) { err.textContent = 'Please enter your name.'; return; }
      if (cc === '+971') {
        /* UAE mobiles: 9 digits, starting 50/52/53/54/55/56/57/58
           (adapt to your own market's numbering plan) */
        if (!/^5[02-8]\d{7}$/.test(national)) {
          err.textContent = 'Please enter a valid UAE mobile number, e.g. 050 123 4567.'; return;
        }
      } else if (national.length < 5 || national.length > 14) {
        err.textContent = 'Please enter a valid phone number.'; return;
      }
      try {
        localStorage.setItem(LS_VISITOR, JSON.stringify({
          name: name,
          phone: cc + national,
          website: f.website.value
        }));
      } catch (e2) {}
      f.remove();
      input.disabled = false;
      send.disabled = false;
      greet();
      input.focus();
    });
  }

  function greet() {
    var v = getVisitor();
    var first = v && v.name ? v.name.split(' ')[0] : '';
    var hi = 'Thanks' + (first ? ', **' + first + '**' : '') + ' ✅\n' +
             'I can show you our cars in stock, prices, and help you book a showroom visit. ' +
             'Ask me anything — in any language.';
    history.push({ role: 'bot', content: hi });
    saveHistory();
    addBubble('bot', md(hi));
    addChips([
      { label: '🚗 Browse cars in stock', send: 'What cars do you have in stock right now?' },
      { label: '💰 Browse by budget', send: 'Which cars do you have under 500,000?' },
      { label: '🏦 Bank finance options', send: 'How does bank finance work if I want to buy a car from you?' },
      { label: '📅 Book a showroom visit', send: 'I would like to book a showroom visit.' },
      { label: 'WhatsApp', send: '', wa: true }
    ]);
  }

  /* ---- Send + stream ------------------------------------------------------------ */

  function sendMessage(text) {
    text = (text || '').trim();
    if (!text || busy) return;
    busy = true;
    send.disabled = true;

    addBubble('user', md(text));
    history.push({ role: 'user', content: text });
    saveHistory();

    var typingEl = typing();
    var botEl = null;
    var botText = '';
    var buf = '';

    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: localStorage.getItem(LS_TOKEN) || null,
        message: text,
        page: location.href,
        visitor: getVisitor()
      })
    }).then(function (res) {
      if (!res.ok || !res.body) throw new Error('http ' + res.status);
      var reader = res.body.getReader();
      var dec = new TextDecoder();

      function pump() {
        return reader.read().then(function (r) {
          if (r.done) { finish(); return; }
          buf += dec.decode(r.value, { stream: true });
          var idx;
          while ((idx = buf.indexOf('\n\n')) !== -1) {
            var raw = buf.slice(0, idx);
            buf = buf.slice(idx + 2);
            raw.split('\n').forEach(function (line) {
              if (line.indexOf('data:') !== 0) return;
              var ev;
              try { ev = JSON.parse(line.slice(5)); } catch (e) { return; }
              handle(ev);
            });
          }
          return pump();
        });
      }

      function handle(ev) {
        switch (ev.type) {
          case 'token':
            try {
              localStorage.setItem(LS_TOKEN, ev.token);
              localStorage.setItem(LS_STARTED, String(Date.now()));
            } catch (e) {}
            break;
          case 'text':
            if (typingEl) { typingEl.remove(); typingEl = null; }
            if (!botEl) botEl = addBubble('bot', '');
            botText += ev.delta;
            botEl.innerHTML = md(botText);
            scrollDown();
            break;
          case 'cars':
            if (typingEl) { typingEl.remove(); typingEl = null; }
            /* flush current text bubble so cards appear in order */
            if (botEl && botText) {
              history.push({ role: 'bot', content: botText });
              botEl = null; botText = '';
            }
            addCars(ev.items);
            history.push({ role: 'cars', content: ev.items });
            saveHistory();
            break;
          case 'lead':
            if (ev.quality === 'hot' || ev.quality === 'warm') {
              addWhatsApp('Hello ' + SITENAME + ', I just left my details with your website assistant. I would like to speak with the sales team.');
              history.push({ role: 'wa' });
              saveHistory();
            }
            break;
          case 'error':
            if (typingEl) { typingEl.remove(); typingEl = null; }
            addBubble('bot', md(ev.message || 'Something went wrong.'));
            break;
        }
      }

      function finish() {
        if (typingEl) { typingEl.remove(); typingEl = null; }
        if (botText) {
          history.push({ role: 'bot', content: botText });
          saveHistory();
        }
        busy = false;
        send.disabled = false;
        input.focus();
      }

      return pump();
    }).catch(function () {
      if (typingEl) typingEl.remove();
      addBubble('bot', 'Sorry, I could not connect. Please try again or use the WhatsApp button below.');
      addWhatsApp();
      busy = false;
      send.disabled = false;
    });
  }

  /* ---- Events --------------------------------------------------------------------- */

  function setLock(on) {
    /* stop the page scrolling behind the full-screen chat on mobile */
    if (window.innerWidth <= 520) {
      document.documentElement.classList.toggle('dcb-lock', on);
    } else {
      document.documentElement.classList.remove('dcb-lock');
    }
  }

  function closePanel() {
    panel.classList.remove('dcb-open');
    setLock(false);
  }

  function openPanel() {
    removeTeaser(false);
    panel.classList.add('dcb-open');
    setLock(true);
    launcher.classList.remove('dcb-has-badge');
    try { localStorage.setItem(LS_SEEN, '1'); } catch (e) {}
    if (history.length) {
      input.disabled = false;
      send.disabled = false;
      renderHistory();
    } else if (!getVisitor()) {
      showGate();
    } else {
      greet();
    }
    scrollDown();
    if (window.innerWidth > 520 && !input.disabled) input.focus();
  }

  launcher.addEventListener('click', function () {
    if (panel.classList.contains('dcb-open')) closePanel();
    else openPanel();
  });
  panel.querySelector('.dcb-head-close').addEventListener('click', closePanel);
  var headWa = panel.querySelector('.dcb-head-wa');
  if (headWa) headWa.addEventListener('click', function () { addWhatsApp(); });

  send.addEventListener('click', function () {
    var t = input.value;
    input.value = '';
    sendMessage(t);
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      var t = input.value;
      input.value = '';
      sendMessage(t);
    }
  });

  /* teaser bubble for visitors who haven't opened the chat yet */
  var teaser = null;

  function removeTeaser(remember) {
    if (teaser) { teaser.remove(); teaser = null; }
    if (remember) { try { localStorage.setItem(LS_SEEN, '1'); } catch (e) {} }
  }

  function showTeaser() {
    teaser = document.createElement('div');
    teaser.className = 'dcb-teaser';
    teaser.setAttribute('role', 'button');
    teaser.innerHTML =
      '<span>Questions? <strong>Chat with our AI assistant</strong> — any language, instant answers.</span>' +
      '<button type="button" class="dcb-teaser-close" aria-label="Dismiss">✕</button>';
    teaser.addEventListener('click', function (e) {
      if (e.target.closest('.dcb-teaser-close')) { removeTeaser(true); return; }
      removeTeaser(false);
      openPanel();
    });
    root.appendChild(teaser);
    launcher.classList.add('dcb-has-badge');
  }

  try {
    if (!localStorage.getItem(LS_SEEN)) {
      setTimeout(function () {
        if (!panel.classList.contains('dcb-open') && !localStorage.getItem(LS_SEEN)) showTeaser();
      }, 3500);
    }
  } catch (e) {}
})();
