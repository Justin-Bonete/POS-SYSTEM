// assets/js/sales.js - Sales management

// Sales state
let sales = [];
let currentSale = null;
let salesFilters = {
    start_date: '',
    end_date: '',
    payment_method: '',
    payment_status: '',
    search: ''
};

// Initialize sales page
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('sales-page')) {
        loadSales();
        setupSalesEventListeners();
    }
    
    if (document.getElementById('reports-page')) {
        initializeReports();
    }
});

// Load sales history
async function loadSales(page = 1) {
    try {
        let url = `${API_BASE_URL}/sales/history.php?page=${page}`;
        
        if (salesFilters.start_date && salesFilters.end_date) {
            url += `&start_date=${salesFilters.start_date}&end_date=${salesFilters.end_date}`;
        }
        if (salesFilters.payment_method) {
            url += `&payment_method=${salesFilters.payment_method}`;
        }
        if (salesFilters.payment_status) {
            url += `&payment_status=${salesFilters.payment_status}`;
        }
        if (salesFilters.search) {
            url += `&search=${encodeURIComponent(salesFilters.search)}`;
        }
        
        const response = await apiCall(url);
        sales = response.data || [];
        displaySales(sales);
        updatePagination(response.pagination);
    } catch (error) {
        console.error('Failed to load sales:', error);
    }
}

// Display sales in table
function displaySales(sales) {
    const tableBody = document.getElementById('sales-table-body');
    if (!tableBody) return;
    
    if (sales.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="7" class="text-center">No sales found</td></tr>';
        return;
    }
    
    tableBody.innerHTML = sales.map(sale => `
        <tr>
            <td>${sale.invoice_number}</td>
            <td>${formatDate(sale.created_at)}</td>
            <td>${sale.customer_name || 'Walk-in Customer'}</td>
            <td>${sale.item_count} items</td>
            <td>${formatCurrency(sale.total_amount)}</td>
            <td>
                <span class="badge badge-${getPaymentMethodClass(sale.payment_method)}">
                    ${sale.payment_method}
                </span>
            </td>
            <td>
                <span class="badge badge-${getPaymentStatusClass(sale.payment_status)}">
                    ${sale.payment_status}
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="viewSale(${sale.id})">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-info" onclick="printInvoice('${sale.invoice_number}')">
                    <i class="fas fa-print"></i>
                </button>
                ${sale.payment_status === 'paid' ? `
                    <button class="btn btn-sm btn-danger" onclick="cancelSale(${sale.id})">
                        <i class="fas fa-times"></i>
                    </button>
                ` : ''}
            </td>
        </tr>
    `).join('');
}

// Setup sales page event listeners
function setupSalesEventListeners() {
    // Date range filter
    const startDate = document.getElementById('filter-start-date');
    const endDate = document.getElementById('filter-end-date');
    
    if (startDate && endDate) {
        startDate.addEventListener('change', applyDateFilter);
        endDate.addEventListener('change', applyDateFilter);
    }
    
    // Payment method filter
    const paymentMethod = document.getElementById('filter-payment-method');
    if (paymentMethod) {
        paymentMethod.addEventListener('change', function(e) {
            salesFilters.payment_method = e.target.value;
            loadSales();
        });
    }
    
    // Payment status filter
    const paymentStatus = document.getElementById('filter-payment-status');
    if (paymentStatus) {
        paymentStatus.addEventListener('change', function(e) {
            salesFilters.payment_status = e.target.value;
            loadSales();
        });
    }
    
    // Search input
    const searchInput = document.getElementById('search-sales');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function(e) {
            salesFilters.search = e.target.value;
            loadSales();
        }, 300));
    }
}

function applyDateFilter() {
    const start = document.getElementById('filter-start-date').value;
    const end = document.getElementById('filter-end-date').value;
    
    if (start && end) {
        salesFilters.start_date = start;
        salesFilters.end_date = end;
        loadSales();
    }
}

// View sale details
async function viewSale(id) {
    try {
        const result = await apiCall(`${API_BASE_URL}/sales/details.php?id=${id}`);
        if (result.success) {
            currentSale = result.data;
            displaySaleDetails(result.data);
            openModal('viewSaleModal');
        }
    } catch (error) {
        console.error('Failed to load sale details:', error);
    }
}

// Display sale details
function displaySaleDetails(saleData) {
    const { sale, items } = saleData;
    
    // Sale info
    const infoDiv = document.getElementById('sale-info');
    if (infoDiv) {
        infoDiv.innerHTML = `
            <p><strong>Invoice:</strong> ${sale.invoice_number}</p>
            <p><strong>Date:</strong> ${formatDate(sale.created_at)}</p>
            <p><strong>Customer:</strong> ${sale.customer_name || 'Walk-in Customer'}</p>
            <p><strong>Payment Method:</strong> ${sale.payment_method}</p>
            <p><strong>Status:</strong> ${sale.payment_status}</p>
            ${sale.notes ? `<p><strong>Notes:</strong> ${sale.notes}</p>` : ''}
        `;
    }
    
    // Items table
    const itemsBody = document.getElementById('sale-items-body');
    if (itemsBody) {
        itemsBody.innerHTML = items.map(item => `
            <tr>
                <td>${item.product_name}</td>
                <td>${item.quantity}</td>
                <td>${formatCurrency(item.price_per_unit)}</td>
                <td>${formatCurrency(item.subtotal)}</td>
            </tr>
        `).join('');
    }
    
    // Totals
    const totalDiv = document.getElementById('sale-total');
    if (totalDiv) {
        totalDiv.innerHTML = `
            <p><strong>Subtotal:</strong> ${formatCurrency(sale.total_amount)}</p>
            <p><strong>Tax:</strong> ${formatCurrency(sale.total_amount * 0.1)}</p>
            <p><strong>Total:</strong> ${formatCurrency(sale.total_amount * 1.1)}</p>
        `;
    }
}

// Print invoice
async function printInvoice(invoiceNumber) {
    try {
        const result = await apiCall(`${API_BASE_URL}/sales/details.php?invoice=${invoiceNumber}`);
        if (result.success) {
            // Open invoice in new window for printing
            const printWindow = window.open('', '_blank');
            generateInvoiceHTML(printWindow, result.data);
            printWindow.print();
        }
    } catch (error) {
        console.error('Failed to print invoice:', error);
    }
}

// Generate invoice HTML
function generateInvoiceHTML(window, saleData) {
    const { sale, items } = saleData;
    
    const html = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Invoice ${sale.invoice_number}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                .invoice-header { text-align: center; margin-bottom: 30px; }
                .invoice-details { margin-bottom: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { padding: 10px; border-bottom: 1px solid #ddd; text-align: left; }
                th { background: #f5f5f5; }
                .total { text-align: right; margin-top: 20px; font-size: 1.2em; }
            </style>
        </head>
        <body>
            <div class="invoice-header">
                <h1>INVOICE</h1>
                <h2>${sale.invoice_number}</h2>
                <p>Date: ${formatDate(sale.created_at)}</p>
            </div>
            
            <div class="invoice-details">
                <p><strong>Customer:</strong> ${sale.customer_name || 'Walk-in Customer'}</p>
                <p><strong>Payment Method:</strong> ${sale.payment_method}</p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity</th>
                        <th>Price</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(item => `
                        <tr>
                            <td>${item.product_name}</td>
                            <td>${item.quantity}</td>
                            <td>${formatCurrency(item.price_per_unit)}</td>
                            <td>${formatCurrency(item.subtotal)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            
            <div class="total">
                <p><strong>Total:</strong> ${formatCurrency(sale.total_amount)}</p>
            </div>
        </body>
        </html>
    `;
    
    window.document.write(html);
}

// Cancel sale
async function cancelSale(id) {
    if (!confirm('Are you sure you want to cancel this sale?')) {
        return;
    }
    
    try {
        const result = await apiCall(`${API_BASE_URL}/sales/cancel.php`, 'POST', { id });
        if (result.success) {
            showAlert('Sale cancelled successfully', 'success');
            loadSales();
        }
    } catch (error) {
        console.error('Failed to cancel sale:', error);
    }
}

// Reports
function initializeReports() {
    // Report period selector
    const periodSelect = document.getElementById('report-period');
    if (periodSelect) {
        periodSelect.addEventListener('change', function(e) {
            loadReport(e.target.value);
        });
    }
    
    // Generate report button
    const generateBtn = document.getElementById('generate-report');
    if (generateBtn) {
        generateBtn.addEventListener('click', generateCustomReport);
    }
    
    // Load initial report
    loadReport('today');
}

async function loadReport(period) {
    try {
        const result = await apiCall(`${API_BASE_URL}/sales/report.php?period=${period}`);
        if (result.success) {
            displayReport(result.data);
        }
    } catch (error) {
        console.error('Failed to load report:', error);
    }
}

async function generateCustomReport() {
    const startDate = document.getElementById('report-start-date').value;
    const endDate = document.getElementById('report-end-date').value;
    
    if (!startDate || !endDate) {
        showAlert('Please select start and end dates', 'warning');
        return;
    }
    
    try {
        const result = await apiCall(`${API_BASE_URL}/sales/report.php?start_date=${startDate}&end_date=${endDate}`);
        if (result.success) {
            displayReport(result.data);
        }
    } catch (error) {
        console.error('Failed to generate report:', error);
    }
}

function displayReport(data) {
    // Update summary cards
    document.getElementById('report-total-sales').textContent = data.totals?.total_sales || 0;
    document.getElementById('report-total-revenue').textContent = formatCurrency(data.totals?.total_revenue || 0);
    document.getElementById('report-average-sale').textContent = formatCurrency(data.average_daily || 0);
    
    // Update chart
    updateReportChart(data.summary || []);
    
    // Update table
    const tableBody = document.getElementById('report-table-body');
    if (tableBody && data.summary) {
        tableBody.innerHTML = data.summary.map(day => `
            <tr>
                <td>${day.date}</td>
                <td>${day.total_sales}</td>
                <td>${formatCurrency(day.total_revenue)}</td>
                <td>${formatCurrency(day.cash_sales)}</td>
                <td>${formatCurrency(day.card_sales)}</td>
                <td>${formatCurrency(day.online_sales)}</td>
            </tr>
        `).join('');
    }
}

function updateReportChart(data) {
    // Chart.js implementation would go here
    console.log('Updating chart with data:', data);
}

// Helper functions
function getPaymentMethodClass(method) {
    const classes = {
        'cash': 'success',
        'card': 'primary',
        'online': 'info',
        'other': 'secondary'
    };
    return classes[method] || 'secondary';
}

function getPaymentStatusClass(status) {
    const classes = {
        'paid': 'success',
        'pending': 'warning',
        'cancelled': 'danger',
        'refunded': 'info'
    };
    return classes[status] || 'secondary';
}