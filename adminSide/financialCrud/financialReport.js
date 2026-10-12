(() => {
    'use strict';
    const payload = document.getElementById('report-data');
    if (!payload) return;
    const report = JSON.parse(payload.textContent);
    const currency = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
    const money = value => currency.format(value);
    const pdfMoney = value => 'PHP ' + Number(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const selectedGroup = document.getElementById('selected-group');
    let period = selectedGroup.value;
    let financialChart = null;
    let itemChart = null;
    const rangeLabel = report.start + ' through ' + report.end;
    const notice = document.getElementById('export-message');
    const chartColors = ['#b9432d', '#147b4c', '#3279aa', '#845ba7', '#ac761b'];

    function renderTable() {
        const source = report.charts[period];
        const fragment = document.createDocumentFragment();
        source.labels.forEach((label, index) => {
            const row = document.createElement('tr');
            [label, money(source.revenue[index]), money(source.expenses[index]), money(source.revenue[index] - source.expenses[index])].forEach((value, column) => {
                const cell = document.createElement('td');
                cell.textContent = value;
                if (column) cell.className = 'text-end';
                row.appendChild(cell);
            });
            fragment.appendChild(row);
        });
        document.getElementById('financial-table-body').replaceChildren(fragment);
        document.getElementById('chart-period-label').textContent = 'Grouped ' + period + ' · ' + rangeLabel;
    }

    function selectPeriod(next) {
        if (!report.charts[next]) return;
        period = next;
        selectedGroup.value = next;
        document.querySelectorAll('#chart-group button').forEach(button => {
            const active = button.dataset.period === next;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', String(active));
        });
        if (financialChart) {
            financialChart.data.labels = report.charts[next].labels;
            financialChart.data.datasets[0].data = report.charts[next].revenue;
            financialChart.data.datasets[1].data = report.charts[next].expenses;
            financialChart.update();
        }
        renderTable();
    }
    document.querySelectorAll('#chart-group button').forEach(button => button.addEventListener('click', () => selectPeriod(button.dataset.period)));

    try {
        if (typeof Chart === 'undefined') throw new Error('Chart library unavailable');
        document.getElementById('financial-chart-wrap').hidden = false;
        financialChart = new Chart(document.getElementById('financialChart'), {
            type: 'bar',
            data: {
                labels: report.charts[period].labels,
                datasets: [
                    { label: 'Revenue', data: report.charts[period].revenue, backgroundColor: '#22895b', borderRadius: 3 },
                    { label: 'Expenses', data: report.charts[period].expenses, backgroundColor: '#cf5359', borderRadius: 3 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false, animation: false,
                plugins: {
                    legend: { labels: { color: '#18333b' } },
                    tooltip: { mode: 'index', intersect: false, callbacks: { label: ctx => ctx.dataset.label + ': ' + money(ctx.parsed.y) } }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: '#52696e', maxTicksLimit: 12, maxRotation: 45 } },
                    y: { beginAtZero: true, grid: { color: '#e6eeee' }, ticks: { color: '#52696e', callback: value => money(value) } }
                }
            }
        });
        if (report.items.length) {
            document.getElementById('items-chart-wrap').hidden = false;
            itemChart = new Chart(document.getElementById('topItemsChart'), {
                type: 'doughnut',
                data: { labels: report.items.map(item => item.item_name), datasets: [{ data: report.items.map(item => item.quantity), backgroundColor: chartColors, borderColor: '#fff', borderWidth: 2 }] },
                options: { responsive: true, maintainAspectRatio: false, animation: false, cutout: '65%', plugins: {
                    legend: { position: 'bottom', labels: { color: '#18333b', boxWidth: 12 } },
                    tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.raw + ' sold' } }
                } }
            });
        }
    } catch (error) {
        document.getElementById('chart-unavailable').hidden = false;
        document.getElementById('financial-data').open = true;
        if (!financialChart) document.getElementById('financial-chart-wrap').hidden = true;
        const itemsWrap = document.getElementById('items-chart-wrap');
        if (itemsWrap && !itemChart) itemsWrap.hidden = true;
        console.warn('Report charts unavailable:', error);
    }

    document.getElementById('btn-print').addEventListener('click', () => window.print());
    document.getElementById('btn-export-pdf').addEventListener('click', () => {
        const button = document.getElementById('btn-export-pdf');
        notice.hidden = true;
        if (!window.jspdf?.jsPDF || !window.jspdf.jsPDF.API.autoTable) {
            notice.textContent = 'PDF tools could not load. Use Print and choose Save as PDF, or check your connection and retry.';
            notice.hidden = false;
            return;
        }
        button.disabled = true;
        button.textContent = 'Preparing PDF…';
        try {
            const doc = new window.jspdf.jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
            const pageWidth = doc.internal.pageSize.getWidth();
            const title = text => {
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(17);
                doc.setTextColor(24, 51, 59);
                doc.text(text, 14, 16);
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(10);
                doc.text(rangeLabel, 14, 23);
            };
            const table = options => doc.autoTable({
                theme: 'grid', styles: { fontSize: 9, cellPadding: 3, textColor: [24, 51, 59] },
                headStyles: { fillColor: [24, 51, 59] }, margin: { top: 15, bottom: 15 }, ...options
            });
            title('La Elena - Sales & Financial Analytics');
            table({ startY: 30, head: [['Revenue', 'Recorded expenses', 'Net profit', 'Profit margin']], body: [[pdfMoney(report.revenue), pdfMoney(report.expenses), pdfMoney(report.profit), report.margin === null ? 'N/A' : report.margin.toFixed(2) + '%']] });
            doc.setFontSize(9);
            doc.text('Final totals for delivered/completed sales; pending, cancelled and refunded sales excluded.', 14, doc.lastAutoTable.finalY + 8);
            doc.text('Net profit = revenue less recorded expenses. Dates use Philippine time.', 14, doc.lastAutoTable.finalY + 14);
            table({ startY: doc.lastAutoTable.finalY + 21, head: [['Expense category', 'Amount', 'Share of expenses']], body: report.categories.length ? report.categories.map(item => [item.category, pdfMoney(item.total), item.percentage.toFixed(1) + '%']) : [['No expenses recorded', '', '']] });
            table({ startY: doc.lastAutoTable.finalY + 10, head: [['Top selling items (before discounts and tax)', 'Quantity', 'Item sales']], body: report.items.length ? report.items.map(item => [item.item_name, item.quantity, pdfMoney(item.amount)]) : [['No completed item sales', '', '']] });

            doc.addPage();
            title('Revenue vs expenses - grouped ' + period);
            if (financialChart) {
                doc.addImage(financialChart.toBase64Image(), 'PNG', 14, 30, pageWidth - 28, 105);
            } else {
                doc.text('Chart unavailable. See the report data on the following pages.', 14, 35);
            }
            doc.addPage();
            title('Revenue and expense data - grouped ' + period);
            const data = report.charts[period];
            table({ startY: 30, head: [['Period', 'Revenue', 'Expenses', 'Net profit']], body: data.labels.map((label, i) => [label, pdfMoney(data.revenue[i]), pdfMoney(data.expenses[i]), pdfMoney(data.revenue[i] - data.expenses[i])]) });
            const pageCount = doc.internal.getNumberOfPages();
            for (let page = 1; page <= pageCount; page++) {
                doc.setPage(page);
                doc.setFontSize(8);
                doc.setTextColor(82, 105, 110);
                doc.text('La Elena - Confidential financial report', 14, 203);
                doc.text('Page ' + page + ' of ' + pageCount, pageWidth - 14, 203, { align: 'right' });
            }
            doc.save('financial-report-' + report.start + '-to-' + report.end + '.pdf');
        } catch (error) {
            console.error('PDF export failed:', error);
            notice.textContent = 'The PDF could not be created. Use Print and choose Save as PDF to save this report.';
            notice.hidden = false;
        } finally {
            button.disabled = false;
            button.textContent = 'Save PDF';
        }
    });
})();
