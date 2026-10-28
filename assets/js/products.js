// assets/js/products.js - Product management

// Product state
let products = [];
let currentProduct = null;
let filters = {
    category: '',
    status: 'active',
    search: ''
};

// Initialize product page
document.addEventListener('DOMContentLoaded', function() {
    console.log('Products page loaded');
    if (document.getElementById('products-page')) {
        console.log('Products page detected');
        loadProducts();
        setupProductEventListeners();
    } else {
        console.log('Products page not detected');
    }
});

// Load products with filters
async function loadProducts(page = 1) {
    console.log('Loading products...');
    try {
        let url = `${API_BASE_URL}/products/list.php?page=${page}`;
        console.log('Fetching from:', url);
        
        const response = await fetch(url);
        const result = await response.json();
        console.log('API Response:', result);
        
        products = result.data || [];
        displayProducts(products);
        if (result.pagination) {
            updatePagination(result.pagination);
        }
    } catch (error) {
        console.error('Failed to load products:', error);
        document.getElementById('products-table-body').innerHTML = 
            '<tr><td colspan="7" class="text-center error">Error loading products. Check console.</td></tr>';
    }
}

// Display products in table
function displayProducts(products) {
    const tableBody = document.getElementById('products-table-body');
    if (!tableBody) {
        console.error('Table body not found');
        return;
    }
    
    if (products.length === 0) {
        tableBody.innerHTML = '<tr><td colspan="7" class="text-center">No products found</td></tr>';
        return;
    }
    
    let html = '';
    products.forEach(product => {
        html += `
        <tr>
            <td>${product.product_code || 'N/A'}</td>
            <td>${product.name || 'N/A'}</td>
            <td>${product.category || 'Uncategorized'}</td>
            <td>${formatCurrency(product.price || 0)}</td>
            <td>
                <span class="badge ${product.quantity > 10 ? 'badge-success' : product.quantity > 0 ? 'badge-warning' : 'badge-danger'}">
                    ${product.quantity || 0}
                </span>
            </td>
            <td>
                <span class="badge badge-${product.status === 'active' ? 'success' : 'secondary'}">
                    ${product.status || 'active'}
                </span>
            </td>
            <td>
                <button class="btn btn-sm btn-primary" onclick="editProduct(${product.id})">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn btn-sm btn-info" onclick="viewProduct(${product.id})">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn btn-sm btn-danger" onclick="deleteProduct(${product.id})">
                    <i class="fas fa-trash"></i>
                </button>
                <button class="btn btn-sm btn-success" onclick="showQRCode(${product.id})">
                    <i class="fas fa-qrcode"></i>
                </button>
            </td>
        </tr>
        `;
    });
    
    tableBody.innerHTML = html;
    console.log('Products displayed:', products.length);
}

// Format currency helper
function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}

// Setup product page event listeners
function setupProductEventListeners() {
    // Search input
    const searchInput = document.getElementById('search-products');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function(e) {
            filters.search = e.target.value;
            loadProducts();
        }, 300));
    }
    
    // Category filter
    const categoryFilter = document.getElementById('filter-category');
    if (categoryFilter) {
        categoryFilter.addEventListener('change', function(e) {
            filters.category = e.target.value;
            loadProducts();
        });
    }
    
    // Status filter
    const statusFilter = document.getElementById('filter-status');
    if (statusFilter) {
        statusFilter.addEventListener('change', function(e) {
            filters.status = e.target.value;
            loadProducts();
        });
    }
}

// Debounce helper
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function updatePagination(pagination) {
    console.log('Pagination:', pagination);
}