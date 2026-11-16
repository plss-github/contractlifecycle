/**
 * Contract Lifecycle — comportamento das telas do plugin.
 *  - "Personalizar" nas regras: liga/desliga a sobrescrita de cada campo
 *  - filtro de tabelas (data-clc-filter) e "marcar todos" (data-clc-checkall)
 */
(function () {
    'use strict';

    function syncOverride(row) {
        const toggle = row.querySelector('[data-clc-override]');
        if (!toggle) {
            return;
        }
        row.classList.toggle('clc-inherited', !toggle.checked);
        row.querySelectorAll('.clc-input input, .clc-input select, .clc-input textarea').forEach((el) => {
            el.disabled = !toggle.checked;
        });
        row.querySelectorAll('.clc-input select').forEach((el) => {
            if (window.jQuery && jQuery(el).data('select2')) {
                jQuery(el).prop('disabled', !toggle.checked).trigger('change.select2');
            }
        });
    }

    // barra do contrato no chamado: primeiro item da seção principal do painel lateral (continua dentro do form)
    function placeTicketBar(bar) {
        const section = bar.closest('#item-main .accordion-body');
        if (section && section.firstElementChild !== bar) {
            section.prepend(bar);
        }
    }

    function init(root) {
        root.querySelectorAll('[data-clc-field]').forEach(syncOverride);
        root.querySelectorAll('[data-clc-ticket-bar]').forEach(placeTicketBar);
    }

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-clc-override]')) {
            syncOverride(event.target.closest('[data-clc-field]'));
        }
        if (event.target.matches('[data-clc-toggle-inactive]')) {
            event.target.closest('[data-clc-balance]').classList.toggle('clc-hide-inactive', !event.target.checked);
        }
        if (event.target.matches('[data-clc-checkall]')) {
            event.target.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach((cb) => {
                cb.checked = event.target.checked;
            });
        }
    });

    // ------------------------------------------------------------------ calendário de lançamentos
    function fmtHours(seconds, signed) {
        const sign = seconds < 0 ? '-' : (signed && seconds > 0 ? '+' : '');
        const abs = Math.abs(seconds);
        const h = Math.floor(abs / 3600);
        const m = Math.floor((abs % 3600) / 60);
        return sign + h + 'h' + (m ? String(m).padStart(2, '0') : '');
    }

    function esc(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    function renderCalendar(root) {
        const data = JSON.parse(root.dataset.clcCalendar);
        const t = JSON.parse(root.dataset.clcLabels);
        const byDay = {};
        data.entries.forEach((e) => (byDay[e.date] = byDay[e.date] || []).push(e));
        const holidays = new Set(data.holidays);
        const businessDays = new Set(data.business_days);

        if (!root.clcState) {
            const last = data.entries.length ? data.entries.map((e) => e.date).sort().pop() : data.today;
            root.clcState = {year: +last.slice(0, 4), month: +last.slice(5, 7) - 1, selected: null};
        }
        const st = root.clcState;
        const pad = (n) => String(n).padStart(2, '0');
        const key = (d) => `${st.year}-${pad(st.month + 1)}-${pad(d)}`;
        const first = new Date(st.year, st.month, 1).getDay();
        const days = new Date(st.year, st.month + 1, 0).getDate();

        let month = {count: 0, real: 0, debited: 0, credit: 0, out: 0, amount: 0};
        let cells = '';
        for (let i = 0; i < first; i++) {
            cells += '<div class="clc-cal-cell clc-cal-empty"></div>';
        }
        for (let d = 1; d <= days; d++) {
            const k = key(d);
            const list = byDay[k] || [];
            const dow = new Date(st.year, st.month, d).getDay();
            const sum = list.reduce((a, e) => {
                a.real += e.real; a.debited += e.debited; a.amount += e.amount;
                if (e.credit > 0) a.credit += e.credit; else a.out -= e.credit; // ajuste negativo = retirada
                a.count += e.type === 'debit' || e.type === 'reversal' ? 1 : 0;
                return a;
            }, {count: 0, real: 0, debited: 0, credit: 0, out: 0, amount: 0});
            Object.keys(month).forEach((m) => (month[m] += sum[m]));
            const classes = ['clc-cal-cell'];
            if (!businessDays.has(dow)) classes.push('clc-cal-weekend');
            if (holidays.has(k)) classes.push('clc-cal-holiday');
            if (k === data.today) classes.push('clc-cal-today');
            if (k === st.selected) classes.push('clc-cal-selected');
            if (list.length) classes.push('clc-cal-has');
            cells += `<button type="button" class="${classes.join(' ')}" data-clc-cal-day="${k}">
                <span class="clc-cal-num">${d}${holidays.has(k) ? ` <small class="text-muted">${esc(t.holiday)}</small>` : ''}</span>
                ${sum.count ? `<span class="badge bg-blue-lt">${sum.count}</span>
                    <span class="clc-cal-line">${esc(t.spent)} ${fmtHours(sum.real)}</span>
                    <span class="clc-cal-line fw-bold">${esc(t.debited)} ${fmtHours(sum.debited)}</span>` : ''}
                ${sum.credit ? `<span class="clc-cal-line text-success">${fmtHours(sum.credit, true)}</span>` : ''}
                ${sum.out ? `<span class="clc-cal-line text-danger">${fmtHours(-sum.out, true)}</span>` : ''}
            </button>`;
        }

        let detail = `<div class="text-muted small">${esc(t.pick)}</div>`;
        if (st.selected) {
            const list = byDay[st.selected] || [];
            detail = list.length === 0 ? `<div class="text-muted small">${esc(t.none)}</div>` :
                `<table class="table table-sm card-table mb-0"><thead><tr>
                    <th>${esc(t.time)}</th><th>${esc(t.origin)}</th><th>${esc(t.author)}</th>
                    <th class="text-end">${esc(t.spent)}</th><th class="text-end">${esc(t.debited)}</th>
                    ${data.billing ? `<th class="text-end">${esc(t.value)}</th>` : ''}<th>${esc(t.obs)}</th></tr></thead><tbody>` +
                list.map((e) => `<tr class="${e.type === 'reversal' ? 'text-muted' : ''}">
                    <td>${esc(e.time)}</td>
                    <td>${e.ticket ? `<a href="${esc(data.ticket_url)}?id=${e.ticket}">${esc(e.origin)}</a>` : esc(e.origin)}
                        ${e.band ? `<span class="badge bg-secondary-lt ms-1">${esc(e.band)}</span>` : ''}</td>
                    <td>${esc(e.author)}</td>
                    <td class="text-end">${e.credit ? '—' : fmtHours(e.real)}</td>
                    <td class="text-end">${e.credit ? `<span class="${e.credit > 0 ? 'text-success' : 'text-danger'}">${fmtHours(e.credit, true)}</span>` : fmtHours(e.debited, true)}</td>
                    ${data.billing ? `<td class="text-end">${e.amount ? 'R$ ' + e.amount.toLocaleString('pt-BR', {minimumFractionDigits: 2}) : '—'}</td>` : ''}
                    <td class="small text-muted">${esc(e.comment)}</td></tr>`).join('') + '</tbody></table>';
        }

        root.innerHTML = `
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-clc-cal-nav="-1"><i class="ti ti-chevron-left"></i></button>
                <h4 class="m-0" style="min-width: 11rem; text-align: center">${esc(t.months[st.month])} ${st.year}</h4>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-clc-cal-nav="1"><i class="ti ti-chevron-right"></i></button>
                <button type="button" class="btn btn-sm btn-ghost-secondary" data-clc-cal-nav="today">${esc(t.today)}</button>
                <span class="ms-auto small text-muted">
                    ${month.count} ${esc(t.entries)} · ${esc(t.spent)} ${fmtHours(month.real)} · ${esc(t.debited)} ${fmtHours(month.debited)}
                    ${month.credit ? ` · ${esc(t.credits)} ${fmtHours(month.credit, true)}` : ''}
                    ${month.out ? ` · ${esc(t.withdrawals)} ${fmtHours(-month.out, true)}` : ''}
                </span>
            </div>
            <div class="clc-cal-grid">${t.weekdays.map((w) => `<div class="clc-cal-head">${esc(w)}</div>`).join('')}${cells}</div>
            <div class="mt-3">${detail}</div>`;
    }

    document.addEventListener('click', (event) => {
        const open = event.target.closest('[data-clc-calendar-open]');
        if (open) {
            const modal = document.querySelector(open.dataset.clcCalendarOpen);
            if (!modal) return;
            document.body.appendChild(modal); // fora do conteúdo da aba (evita z-index/transform do layout)
            renderCalendar(modal.querySelector('[data-clc-calendar]'));
            bootstrap.Modal.getOrCreateInstance(modal).show();
            return;
        }
        const nav = event.target.closest('[data-clc-cal-nav]');
        const day = event.target.closest('[data-clc-cal-day]');
        const root = event.target.closest('[data-clc-calendar]');
        if (!root || (!nav && !day)) return;
        const st = root.clcState;
        if (nav) {
            if (nav.dataset.clcCalNav === 'today') {
                const today = JSON.parse(root.dataset.clcCalendar).today;
                st.year = +today.slice(0, 4);
                st.month = +today.slice(5, 7) - 1;
            } else {
                st.month += +nav.dataset.clcCalNav;
                if (st.month < 0) { st.month = 11; st.year--; }
                if (st.month > 11) { st.month = 0; st.year++; }
            }
        } else {
            st.selected = day.dataset.clcCalDay;
        }
        renderCalendar(root);
    });

    // sub-abas do plugin: mecanismo próprio (o GLPI desativa todo .tab-pane aninhado ao trocar de aba principal)
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-clc-subtab]');
        if (!button) {
            return;
        }
        event.preventDefault();
        const pane = document.querySelector(button.dataset.clcSubtab);
        if (!pane) {
            return;
        }
        button.closest('.clc-subtabs').querySelectorAll('[data-clc-subtab]').forEach((b) => b.classList.toggle('active', b === button));
        [...pane.parentElement.children].forEach((p) => p.classList.toggle('is-active', p === pane));
    });

    // botão que posta fora de qualquer formulário da página (data-clc-post + data-clc-fields)
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-clc-post]');
        if (!button) {
            return;
        }
        event.preventDefault();
        const form = document.createElement('form');
        form.method = 'post';
        form.action = button.dataset.clcPost;
        Object.entries(JSON.parse(button.dataset.clcFields || '{}')).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        button.disabled = true;
        form.submit();
    });

    document.addEventListener('input', (event) => {
        if (!event.target.matches('[data-clc-filter]')) {
            return;
        }
        const term = event.target.value.trim().toLowerCase();
        document.querySelectorAll(event.target.dataset.clcFilter).forEach((table) => {
            table.querySelectorAll('tbody tr').forEach((tr) => {
                tr.hidden = term !== '' && !tr.textContent.toLowerCase().includes(term);
            });
        });
    });

    document.addEventListener('DOMContentLoaded', () => init(document));
    // abas do GLPI carregam por ajax
    new MutationObserver((mutations) => {
        mutations.forEach((m) => m.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
                init(node);
            }
        }));
    }).observe(document.documentElement, {childList: true, subtree: true});
})();
