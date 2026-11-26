// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Document assistant drawer: left-edge tab, resizable / collapsible / pinnable panel, chat.
 *
 * Written as a plain AMD module so it works without running grunt; amd/build/drawer.min.js
 * is a copy of this file.
 *
 * @module     local_ai_qa/drawer
 * @copyright  2026 Smart-LMS Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/str', 'core_user/repository', 'core_filters/events'],
function(Ajax, Str, UserRepo, FilterEvents) {
    'use strict';

    var PREF = 'local_ai_qa_';
    var MIN_WIDTH = 300;
    var DEFAULT_WIDTH = 420;

    var store = {
        /**
         * Save a drawer preference (width/open/pinned) as a Moodle user preference,
         * so it follows the user across pages and devices.
         *
         * @param {String} key
         * @param {*} value
         */
        set: function(key, value) {
            var v = typeof value === 'boolean' ? (value ? 1 : 0) : value;
            UserRepo.setUserPreference(PREF + key, v).catch(function() {
                // Not critical.
                return null;
            });
        },
        getSession: function(key) {
            try {
                return JSON.parse(window.sessionStorage.getItem(PREF + key) || '[]');
            } catch (e) {
                return [];
            }
        },
        setSession: function(key, value) {
            try {
                window.sessionStorage.setItem(PREF + key, JSON.stringify(value));
            } catch (e) {
                // Ignore.
            }
        }
    };

    var escapeHtml = function(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    };

    /**
     * Very small, safe markdown subset: bold, italic, code, lists, headings, citations, and LaTeX math.
     *
     * Math ($...$, $$...$$, \(...\), \[...\]) is taken out before markdown processing and wrapped
     * the way Moodle's MathJax filter expects, so it can be typeset after insertion.
     *
     * @param {String} text
     * @return {String} html
     */
    var renderMarkdown = function(text) {
        var maths = [];
        var keep = function(tex, display) {
            maths.push('<span class="filter_mathjaxloader_equation"><span class="nolink">' +
                (display ? '\\[' + escapeHtml(tex) + '\\]' : '\\(' + escapeHtml(tex) + '\\)') + '</span></span>');
            return '@@MATH' + (maths.length - 1) + '@@';
        };
        text = String(text)
            .replace(/\$\$([\s\S]+?)\$\$/g, function(m, tex) {
                return keep(tex, true);
            })
            .replace(/\\\[([\s\S]+?)\\\]/g, function(m, tex) {
                return keep(tex, true);
            })
            .replace(/\\\(([\s\S]+?)\\\)/g, function(m, tex) {
                return keep(tex, false);
            })
            .replace(/\$(?!\s)([^$\n]+?)(?<!\s)\$/g, function(m, tex) {
                return keep(tex, false);
            });
        var html = escapeHtml(text)
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/(^|\W)_(.+?)_(?=\W|$)/g, '$1<em>$2</em>')
            .replace(/`([^`]+)`/g, '<code>$1</code>')
            .replace(/\[(\d{1,2})\]/g, '<button type="button" class="local-ai-qa-cite" data-cite="$1">$1</button>');
        var out = [];
        var list = null;
        html.split('\n').forEach(function(line) {
            var m = line.match(/^\s*([-*•]|\d+[.)])\s+(.*)/);
            if (m) {
                var tag = /\d/.test(m[1]) ? 'ol' : 'ul';
                if (list !== tag) {
                    if (list) {
                        out.push('</' + list + '>');
                    }
                    out.push('<' + tag + '>');
                    list = tag;
                }
                out.push('<li>' + m[2] + '</li>');
                return;
            }
            if (list) {
                out.push('</' + list + '>');
                list = null;
            }
            var h = line.match(/^#{1,4}\s+(.*)/);
            if (h) {
                out.push('<p class="fw-bold mb-1">' + h[1] + '</p>');
            } else if (line.trim()) {
                out.push('<p>' + line + '</p>');
            }
        });
        if (list) {
            out.push('</' + list + '>');
        }
        return out.join('').replace(/@@MATH(\d+)@@/g, function(m, i) {
            return maths[parseInt(i, 10)];
        });
    };

    /**
     * Ask Moodle's filters (MathJax) to process newly inserted content.
     *
     * @param {HTMLElement} node
     */
    var typeset = function(node) {
        if (node && node.querySelector('.filter_mathjaxloader_equation')) {
            FilterEvents.notifyFilterContentUpdated([node]);
        }
    };

    /**
     * Drawer controller.
     *
     * @param {HTMLElement} root
     * @param {Object} strings
     */
    var Drawer = function(root, strings) {
        this.root = root;
        this.s = strings;
        this.contextid = parseInt(root.dataset.contextid, 10);
        this.courseid = parseInt(root.dataset.courseid, 10);
        this.isteacher = root.dataset.isteacher === '1';
        this.tab = root.querySelector('[data-action="open"]');
        this.panel = root.querySelector('.local-ai-qa-panel');
        this.messages = root.querySelector('[data-region="messages"]');
        this.input = root.querySelector('[data-region="input"]');
        this.form = root.querySelector('[data-region="form"]');
        this.sendBtn = root.querySelector('[data-action="send"]');
        this.historyKey = 'history_' + this.contextid;
        this.history = store.getSession(this.historyKey);
        this.busy = false;
        this.docsLoaded = false;

        this.setWidth(parseInt(root.dataset.width, 10) || DEFAULT_WIDTH);
        this.setPinned(root.dataset.pinned === '1');
        this.bind();
        this.renderHistory();
        this.positionBelowNavbar();
        if (root.dataset.open === '1') {
            this.open(false, true);
        }
    };

    Drawer.prototype.bind = function() {
        var self = this;
        this.tab.addEventListener('click', function() {
            self.open(true);
        });
        this.root.querySelector('[data-action="close"]').addEventListener('click', function() {
            self.close();
        });
        this.root.querySelector('[data-action="pin"]').addEventListener('click', function() {
            self.setPinned(!self.pinned);
            store.set('pinned', self.pinned);
        });
        this.root.querySelector('[data-action="newchat"]').addEventListener('click', function() {
            self.history = [];
            store.setSession(self.historyKey, []);
            self.renderHistory();
            self.input.focus();
        });
        this.panel.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                self.close();
            }
        });
        this.form.addEventListener('submit', function(e) {
            e.preventDefault();
            self.ask(self.input.value);
        });
        this.input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                self.ask(self.input.value);
            }
        });
        this.root.querySelector('[data-region="suggestions"]').addEventListener('click', function(e) {
            var btn = e.target.closest('button[data-suggestion]');
            if (btn) {
                self.ask(btn.textContent);
            }
        });
        this.messages.addEventListener('click', function(e) {
            var cite = e.target.closest('.local-ai-qa-cite');
            if (!cite) {
                return;
            }
            var msg = cite.closest('.local-ai-qa-msg');
            var details = msg && msg.querySelector('details');
            var item = msg && msg.querySelector('[data-source="' + cite.dataset.cite + '"]');
            if (details && item) {
                details.open = true;
                item.classList.add('local-ai-qa-flash');
                item.scrollIntoView({behavior: 'smooth', block: 'nearest'});
                setTimeout(function() {
                    item.classList.remove('local-ai-qa-flash');
                }, 1500);
            }
        });
        this.root.querySelector('[data-region="docs"]').addEventListener('toggle', function(e) {
            if (e.target.open && !self.docsLoaded) {
                self.loadDocuments();
            }
        });
        var sync = this.root.querySelector('[data-action="sync"]');
        if (sync) {
            sync.addEventListener('click', function() {
                self.sync(sync);
            });
        }
        this.bindResizer();
        window.addEventListener('resize', function() {
            self.positionBelowNavbar();
            self.setWidth(self.width);
        });
    };

    Drawer.prototype.bindResizer = function() {
        var self = this;
        var handle = this.root.querySelector('[data-region="resizer"]');
        handle.addEventListener('pointerdown', function(e) {
            e.preventDefault();
            handle.setPointerCapture(e.pointerId);
            document.body.classList.add('local-ai-qa-resizing');
            var move = function(ev) {
                self.setWidth(ev.clientX);
            };
            var up = function() {
                handle.removeEventListener('pointermove', move);
                handle.removeEventListener('pointerup', up);
                handle.removeEventListener('pointercancel', up);
                document.body.classList.remove('local-ai-qa-resizing');
                store.set('width', self.width);
            };
            handle.addEventListener('pointermove', move);
            handle.addEventListener('pointerup', up);
            handle.addEventListener('pointercancel', up);
        });
        handle.addEventListener('dblclick', function() {
            self.setWidth(DEFAULT_WIDTH);
            store.set('width', self.width);
        });
        handle.addEventListener('keydown', function(e) {
            var step = e.shiftKey ? 80 : 20;
            if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                e.preventDefault();
                self.setWidth(self.width + (e.key === 'ArrowRight' ? step : -step));
                store.set('width', self.width);
            }
        });
    };

    Drawer.prototype.positionBelowNavbar = function() {
        var nav = document.querySelector('nav.navbar.fixed-top');
        var top = nav ? Math.max(0, nav.getBoundingClientRect().bottom) : 0;
        document.documentElement.style.setProperty('--local-ai-qa-top', top + 'px');
    };

    Drawer.prototype.setWidth = function(width) {
        var max = Math.max(MIN_WIDTH, Math.min(1000, window.innerWidth - 60));
        this.width = Math.round(Math.min(max, Math.max(MIN_WIDTH, width || DEFAULT_WIDTH)));
        document.documentElement.style.setProperty('--local-ai-qa-width', this.width + 'px');
        this.root.querySelector('[data-region="resizer"]').setAttribute('aria-valuenow', this.width);
    };

    Drawer.prototype.setPinned = function(pinned) {
        this.pinned = !!pinned;
        this.root.querySelector('[data-action="pin"]').setAttribute('aria-pressed', this.pinned ? 'true' : 'false');
        this.root.querySelector('[data-action="pin"]').classList.toggle('active', this.pinned);
        document.body.classList.toggle('local-ai-qa-pinned', this.pinned && !this.panel.hidden);
    };

    Drawer.prototype.open = function(focus, restoring) {
        this.panel.hidden = false;
        this.tab.setAttribute('aria-expanded', 'true');
        this.root.classList.add('is-open');
        document.body.classList.toggle('local-ai-qa-pinned', this.pinned);
        if (!restoring) {
            store.set('open', true);
        }
        this.positionBelowNavbar();
        if (focus) {
            this.input.focus();
        }
        this.messages.scrollTop = this.messages.scrollHeight;
    };

    Drawer.prototype.close = function() {
        this.panel.hidden = true;
        this.tab.setAttribute('aria-expanded', 'false');
        this.root.classList.remove('is-open');
        document.body.classList.remove('local-ai-qa-pinned');
        store.set('open', false);
        this.tab.focus();
    };

    Drawer.prototype.addMessage = function(role, html) {
        var el = document.createElement('div');
        el.className = 'local-ai-qa-msg local-ai-qa-msg-' + role;
        el.innerHTML = '<div class="local-ai-qa-bubble">' + html + '</div>';
        this.messages.appendChild(el);
        typeset(el);
        this.messages.scrollTop = this.messages.scrollHeight;
        return el;
    };

    Drawer.prototype.renderSources = function(sources) {
        if (!sources || !sources.length) {
            return '';
        }
        var s = this.s;
        var items = sources.map(function(src) {
            var title = escapeHtml(src.title);
            if (src.url) {
                title = '<a href="' + escapeHtml(src.url) + '">' + title + '</a>';
            }
            var meta = [s.page.replace('{$a}', src.page)];
            if (src.coursename) {
                meta.push(escapeHtml(src.coursename));
            }
            var excerpt = escapeHtml(src.excerpt.length > 300 ? src.excerpt.slice(0, 300) + '…' : src.excerpt);
            return '<li data-source="' + src.n + '"><span class="badge text-bg-primary me-1">' + src.n + '</span>' +
                title + ' <span class="text-body-secondary">· ' + meta.join(' · ') + '</span>' +
                '<div class="local-ai-qa-excerpt">' + excerpt + '</div></li>';
        }).join('');
        return '<details class="local-ai-qa-sources mt-2"><summary>' + s.sources.replace('{$a}', sources.length) +
            '</summary><ul class="list-unstyled mb-0">' + items + '</ul></details>';
    };

    Drawer.prototype.renderHistory = function() {
        var self = this;
        this.messages.innerHTML = '';
        this.addMessage('assistant', renderMarkdown(this.s.greeting.replace('{$a}', this.root.dataset.scopelabel)));
        this.history.forEach(function(turn) {
            if (turn.role === 'user') {
                self.addMessage('user', escapeHtml(turn.content).replace(/\n/g, '<br>'));
            } else {
                self.addMessage('assistant', renderMarkdown(turn.content) + self.renderSources(turn.sources));
            }
        });
        var sugg = this.root.querySelector('[data-region="suggestions"]');
        if (this.history.length) {
            sugg.innerHTML = '';
        } else {
            var role = this.isteacher ? 'teacher' : 'student';
            sugg.innerHTML = [1, 2, 3].map(function(i) {
                return '<button type="button" class="btn btn-outline-secondary btn-sm text-start mb-1 w-100" data-suggestion="1">' +
                    escapeHtml(self.s['suggestion' + i + role]) + '</button>';
            }).join('');
        }
    };

    Drawer.prototype.ask = function(question) {
        var self = this;
        question = (question || '').trim();
        if (!question || this.busy) {
            return;
        }
        this.busy = true;
        this.sendBtn.disabled = true;
        this.input.value = '';
        this.root.querySelector('[data-region="suggestions"]').innerHTML = '';
        this.addMessage('user', escapeHtml(question).replace(/\n/g, '<br>'));
        var pending = this.addMessage('assistant',
            '<span class="local-ai-qa-typing">' + escapeHtml(this.s.loading) + '</span>');
        var historyPayload = this.history.map(function(t) {
            return {role: t.role, content: t.content};
        });

        Ajax.call([{
            methodname: 'local_ai_qa_ask',
            args: {contextid: this.contextid, question: question, history: JSON.stringify(historyPayload)}
        }])[0].then(function(result) {
            pending.querySelector('.local-ai-qa-bubble').innerHTML =
                renderMarkdown(result.answer) + self.renderSources(result.sources);
            typeset(pending);
            self.history.push({role: 'user', content: question});
            self.history.push({role: 'assistant', content: result.answer, sources: result.sources});
            self.history = self.history.slice(-20);
            store.setSession(self.historyKey, self.history);
            return result;
        }).catch(function(err) {
            var msg = (err && (err.message || err.error)) || String(err);
            pending.querySelector('.local-ai-qa-bubble').innerHTML =
                '<span class="text-danger">' + escapeHtml(self.s.error.replace('{$a}', msg)) + '</span>';
        }).then(function() {
            self.busy = false;
            self.sendBtn.disabled = false;
            self.messages.scrollTop = self.messages.scrollHeight;
            self.input.focus();
            return null;
        });
    };

    Drawer.prototype.loadDocuments = function() {
        var self = this;
        var list = this.root.querySelector('[data-region="doclist"]');
        var count = this.root.querySelector('[data-region="doccount"]');
        return Ajax.call([{methodname: 'local_ai_qa_get_documents', args: {contextid: this.contextid}}])[0]
            .then(function(result) {
                self.docsLoaded = true;
                count.textContent = result.documents.length;
                if (!result.documents.length) {
                    list.innerHTML = '<li class="text-body-secondary">' +
                        escapeHtml(result.canmanage ? self.s.nodocumentsteacher : self.s.nodocuments) + '</li>';
                    return result;
                }
                list.innerHTML = result.documents.map(function(d) {
                    var name = d.url ? '<a href="' + escapeHtml(d.url) + '">' + escapeHtml(d.name) + '</a>' : escapeHtml(d.name);
                    return '<li class="text-truncate" title="' + escapeHtml(d.name) + '">' +
                        '<i class="icon fa fa-file-lines fa-fw" aria-hidden="true"></i>' + name +
                        (self.courseid ? '' : ' <span class="text-body-secondary">· ' + escapeHtml(d.coursename) + '</span>') +
                        '</li>';
                }).join('');
                return result;
            }).catch(function(err) {
                list.innerHTML = '<li class="text-danger">' + escapeHtml((err && err.message) || String(err)) + '</li>';
            });
    };

    Drawer.prototype.sync = function(button) {
        var self = this;
        var status = this.root.querySelector('[data-region="syncstatus"]');
        button.disabled = true;
        status.textContent = this.s.syncing;
        Ajax.call([{methodname: 'local_ai_qa_sync_course', args: {courseid: this.courseid}}])[0]
            .then(function(r) {
                var text = self.s.syncdone.replace('{$a->added}', r.added).replace('{$a->unchanged}', r.unchanged)
                    .replace('{$a->removed}', r.removed).replace('{$a->skipped}', r.skipped);
                status.textContent = text + (r.errors.length ? ' ⚠ ' + r.errors.join('; ') : '');
                self.docsLoaded = false;
                return self.loadDocuments();
            }).catch(function(err) {
                status.textContent = self.s.error.replace('{$a}', (err && err.message) || String(err));
            }).then(function() {
                button.disabled = false;
                return null;
            });
    };

    var STRING_KEYS = ['greeting', 'loading', 'error', 'sources', 'page', 'nodocuments', 'nodocumentsteacher',
        'syncing', 'syncdone', 'suggestion1student', 'suggestion2student', 'suggestion3student',
        'suggestion1teacher', 'suggestion2teacher', 'suggestion3teacher'];

    return {
        /**
         * Initialise the drawer.
         *
         * @param {String} rootId
         * @return {Promise}
         */
        init: function(rootId) {
            var root = document.getElementById(rootId);
            if (!root || root.dataset.initialised) {
                return Promise.resolve();
            }
            root.dataset.initialised = '1';
            // Move to <body> so the fixed panel is not clipped by footer containers.
            document.body.appendChild(root);
            // Never let the chat form do a native submit (would navigate away), even before init completes.
            root.querySelector('[data-region="form"]').addEventListener('submit', function(e) {
                e.preventDefault();
            });
            // A click on the tab before the strings have loaded is remembered and honoured once ready.
            var early = false;
            var tab = root.querySelector('[data-action="open"]');
            var earlyClick = function() {
                early = true;
            };
            tab.addEventListener('click', earlyClick);
            // Fetch raw strings: placeholders are filled in here.
            return Str.get_strings(STRING_KEYS.map(function(key) {
                return {key: key, component: 'local_ai_qa'};
            })).then(function(values) {
                var strings = {};
                STRING_KEYS.forEach(function(key, i) {
                    strings[key] = values[i];
                });
                tab.removeEventListener('click', earlyClick);
                var drawer = new Drawer(root, strings);
                if (early) {
                    drawer.open(true);
                }
                return drawer;
            }).catch(function(e) {
                window.console.error('local_ai_qa init failed', e);
                throw e;
            });
        }
    };
});
