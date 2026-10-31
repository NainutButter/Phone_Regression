document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm]').forEach((button) => {
        button.closest('form')?.addEventListener('submit', (event) => {
            if (!window.confirm(button.dataset.confirm)) event.preventDefault();
        });
    });

    document.querySelectorAll('.notice-close').forEach((button) => {
        button.addEventListener('click', () => button.closest('.notice')?.remove());
    });

    document.querySelectorAll('[data-lines]').forEach((container) => {
        let nextIndex = container.querySelectorAll('.line-item').length;
        const prefix = container.dataset.namePrefix;
        const addButton = container.parentElement.querySelector('.add-line');

        const updateRemoveButtons = () => {
            const rows = [...container.querySelectorAll('.line-item')];
            rows.forEach((row) => {
                row.querySelector('.remove-line').disabled = rows.length === 1;
            });
        };

        addButton?.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'line-item';
            row.innerHTML = `<label>Product<select name="${prefix}[${nextIndex}][product_id]" required><option value="">Select product</option>${container.dataset.productOptions}</select></label><label>Quantity<input name="${prefix}[${nextIndex}][quantity]" type="number" min="1" value="1" required></label><button class="icon-button remove-line" type="button" aria-label="Remove item">×</button>`;
            nextIndex += 1;
            container.append(row);
            updateRemoveButtons();
        });

        container.addEventListener('click', (event) => {
            if (event.target.closest('.remove-line') && container.querySelectorAll('.line-item').length > 1) {
                event.target.closest('.line-item').remove();
                updateRemoveButtons();
            }
        });
    });

    const palette = ['#6578ef', '#39b8a6', '#eda74e', '#9171d7', '#4b9fe1', '#e67583'];
    const dashboard = document.getElementById('dashboard-data');
    if (dashboard && window.Chart) {
        const data = JSON.parse(dashboard.textContent);
        const monthlyCanvas = document.getElementById('salesChart');
        if (monthlyCanvas) {
            new Chart(monthlyCanvas, {
                type: 'line',
                data: {
                    labels: data.monthly.map((row) => row.label),
                    datasets: [{
                        label: 'Revenue (THB)',
                        data: data.monthly.map((row) => Number(row.total)),
                        borderColor: '#6376ed',
                        backgroundColor: 'rgba(99,118,237,.10)',
                        fill: true,
                        tension: .35,
                        pointRadius: 3,
                        pointBackgroundColor: '#fff',
                        pointBorderWidth: 2,
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#95a0b2', font: { size: 10 } } },
                        y: { beginAtZero: true, grid: { color: '#f0f2f6' }, ticks: { color: '#95a0b2', font: { size: 10 } } },
                    },
                },
            });
        }
        const stockCanvas = document.getElementById('stockChart');
        if (stockCanvas) {
            new Chart(stockCanvas, {
                type: 'doughnut',
                data: {
                    labels: data.stock.map((row) => row.brand),
                    datasets: [{ data: data.stock.map((row) => Number(row.units)), backgroundColor: palette, borderWidth: 0, hoverOffset: 4 }],
                },
                options: {
                    maintainAspectRatio: false,
                    cutout: '68%',
                    plugins: { legend: { position: 'right', labels: { color: '#718099', boxWidth: 8, usePointStyle: true, pointStyle: 'circle', padding: 13, font: { size: 10 } } } },
                },
            });
        }
        const brandCanvas = document.getElementById('brandSalesChart');
        if (brandCanvas) {
            new Chart(brandCanvas, {
                type: 'bar',
                data: {
                    labels: data.brandSales.map((row) => row.brand),
                    datasets: [{ label: 'Units sold', data: data.brandSales.map((row) => Number(row.units)), backgroundColor: palette, borderRadius: 5 }],
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#95a0b2', font: { size: 10 } } },
                        y: { beginAtZero: true, grid: { color: '#f0f2f6' }, ticks: { precision: 0, color: '#95a0b2', font: { size: 10 } } },
                    },
                },
            });
        }
    }

    const forecastElement = document.getElementById('forecast-data');
    const forecastCanvas = document.getElementById('forecastChart');
    if (forecastElement && forecastCanvas && window.Chart) {
        const data = JSON.parse(forecastElement.textContent);
        const history = data.history;
        const future = data.forecast;
        const labels = [...history.map((row) => row.sale_date), ...future.map((row) => row.date)];
        const actual = [...history.map((row) => Number(row.quantity)), ...future.map(() => null)];
        const predicted = [...history.map(() => null), ...future.map((row) => Number(row.quantity))];
        new Chart(forecastCanvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    { label: 'Observed sales', data: actual, borderColor: '#536df3', backgroundColor: '#536df3', pointRadius: 2, tension: .25 },
                    { label: 'Forecast units / day', data: predicted, borderColor: '#20a88e', backgroundColor: 'rgba(32,168,142,.12)', borderDash: [5, 4], pointRadius: 0, fill: true, tension: .25 },
                ],
            },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { labels: { color: '#718099', usePointStyle: true, boxWidth: 8, font: { size: 10 } } } },
                scales: {
                    x: { grid: { display: false }, ticks: { maxTicksLimit: 9, color: '#95a0b2', font: { size: 9 } } },
                    y: { beginAtZero: true, grid: { color: '#f0f2f6' }, ticks: { color: '#95a0b2', font: { size: 10 } } },
                },
            },
        });
    }
});
