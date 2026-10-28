// assets/js/pos.js - Point of Sale interface

// POS state
let cart = [];
let products = [];
let productById = new Map();
let activeSearchTerm = '';
let activeCategory = '';
let currentTransaction = {
    items: [],
    customer: null,
    payment_method: 'cash',
    total: 0
};

// Initialize POS
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('pos-page')) {
        initializePOS();
    }
});

async function initializePOS() {
    await loadProducts();
    setupPOSEventListeners();
    updateCartDisplay();
}

// Load products for POS
async function loadProducts() {
    try {
        const fields = encodeURIComponent('id,product_code,name,price,quantity,category,status');
        const response = await apiCall(`${API_BASE_URL}/products/list.php?limit=0&status=active&fields=${fields}`);
        products = (response.data || []).map((p) => ({
            ...p,
            _searchKey: `${String(p.name || '').toLowerCase()} ${String(p.product_code || '').toLowerCase()}`
        }));
        productById = new Map(products.map((p) => [String(p.id), p]));
        displayProductsGrid(products);
    } catch (error) {
        console.error('Failed to load products:', error);
    }
}

// Display products in grid
function displayProductsGrid(products) {
    const grid = document.getElementById('products-grid');
    if (!grid) return;
    
    grid.innerHTML = products.map(product => `
        <div class="product-card ${product.quantity === 0 ? 'out-of-stock' : ''}" 
             onclick="addToCart(${product.id})"
             data-id="${product.id}">
            <div class="product-name">${product.name}</div>
            <div class="product-price">${formatCurrency(product.price)}</div>
            <div class="product-stock">Stock: ${product.quantity}</div>
        </div>
    `).join('');
}

// Setup POS event listeners
function setupPOSEventListeners() {
    // Search products
    const searchInput = document.getElementById('pos-search');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function(e) {
            activeSearchTerm = String(e.target.value || '');
            applyFilters();
        }, 300));
    }
    
    // Category filter
    const categorySelect = document.getElementById('pos-category');
    if (categorySelect) {
        categorySelect.addEventListener('change', function(e) {
            activeCategory = String(e.target.value || '');
            applyFilters();
        });
    }
    
    // Barcode/QR scanner input
    const scanInput = document.getElementById('scan-input');
    if (scanInput) {
        scanInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                handleScan(e.target.value);
                e.target.value = '';
            }
        });
    }
    
    // Payment method
    const paymentMethod = document.getElementById('payment-method');
    if (paymentMethod) {
        paymentMethod.addEventListener('change', function(e) {
            currentTransaction.payment_method = e.target.value;
        });
    }
    
    // Process sale button
    const processBtn = document.getElementById('process-sale');
    if (processBtn) {
        processBtn.addEventListener('click', processSale);
    }
    
    // Clear cart button
    const clearBtn = document.getElementById('clear-cart');
    if (clearBtn) {
        clearBtn.addEventListener('click', clearCart);
    }
}

// Add product to cart
function addToCart(productId) {
    const product = productById.get(String(productId));
    
    if (!product) return;
    
    if (product.quantity <= 0) {
        showAlert('Product out of stock', 'warning');
        return;
    }
    
    const existingItem = cart.find(item => item.product_id == productId);
    
    if (existingItem) {
        if (existingItem.quantity >= product.quantity) {
            showAlert('Not enough stock', 'warning');
            return;
        }
        existingItem.quantity++;
        existingItem.subtotal = existingItem.quantity * existingItem.price;
    } else {
        cart.push({
            product_id: product.id,
            product_name: product.name,
            price: product.price,
            quantity: 1,
            subtotal: product.price
        });
    }
    
    updateCartDisplay();
    highlightProduct(productId);
}

// Update cart display
function updateCartDisplay() {
    const cartItems = document.getElementById('cart-items');
    const cartTotal = document.getElementById('cart-total');
    const cartCount = document.getElementById('cart-count');
    
    if (cartItems) {
        if (cart.length === 0) {
            cartItems.innerHTML = '<div class="text-center p-3">Cart is empty</div>';
        } else {
            cartItems.innerHTML = cart.map((item, index) => `
                <div class="cart-item">
                    <div class="cart-item-info">
                        <div class="cart-item-name">${item.product_name}</div>
                        <div class="cart-item-price">${formatCurrency(item.price)} x ${item.quantity}</div>
                    </div>
                    <div class="cart-item-actions">
                        <button class="btn btn-sm btn-outline-primary" onclick="updateCartItem(${index}, 'decrease')">-</button>
                        <span>${item.quantity}</span>
                        <button class="btn btn-sm btn-outline-primary" onclick="updateCartItem(${index}, 'increase')">+</button>
                        <button class="btn btn-sm btn-danger" onclick="removeFromCart(${index})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        }
    }
    
    const total = cart.reduce((sum, item) => sum + item.subtotal, 0);
    currentTransaction.total = total;
    
    if (cartTotal) {
        cartTotal.textContent = formatCurrency(total);
    }
    
    if (cartCount) {
        cartCount.textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
    }
}

// Update cart item quantity
function updateCartItem(index, action) {
    const item = cart[index];
    const product = productById.get(String(item.product_id));
    if (!product) return;
    
    if (action === 'increase') {
        if (item.quantity >= product.quantity) {
            showAlert('Not enough stock', 'warning');
            return;
        }
        item.quantity++;
    } else if (action === 'decrease') {
        if (item.quantity > 1) {
            item.quantity--;
        } else {
            removeFromCart(index);
            return;
        }
    }
    
    item.subtotal = item.quantity * item.price;
    updateCartDisplay();
}

// Remove item from cart
function removeFromCart(index) {
    cart.splice(index, 1);
    updateCartDisplay();
}

// Clear entire cart
function clearCart() {
    if (cart.length > 0 && confirm('Clear all items from cart?')) {
        cart = [];
        updateCartDisplay();
    }
}

// Process sale
async function processSale() {
    if (cart.length === 0) {
        showAlert('Cart is empty', 'warning');
        return;
    }
    
    const customerName = document.getElementById('customer-name')?.value;
    const customerEmail = document.getElementById('customer-email')?.value;
    const customerPhone = document.getElementById('customer-phone')?.value;
    const notes = document.getElementById('sale-notes')?.value;
    
    const saleData = {
        items: cart.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
            price: item.price
        })),
        payment_method: currentTransaction.payment_method,
        customer_name: customerName,
        customer_email: customerEmail,
        customer_phone: customerPhone,
        notes: notes
    };
    
    try {
        const result = await apiCall(`${API_BASE_URL}/sales/process.php`, 'POST', saleData);
        
        if (result.success) {
            showAlert('Sale processed successfully', 'success');
            
            // Show receipt
            showReceipt(result.data);
            
            // Clear cart and reload products
            cart = [];
            updateCartDisplay();
            await loadProducts();
            
            // Clear customer info
            document.getElementById('customer-name').value = '';
            document.getElementById('customer-email').value = '';
            document.getElementById('customer-phone').value = '';
            document.getElementById('sale-notes').value = '';
        }
    } catch (error) {
        console.error('Failed to process sale:', error);
    }
}

// Show receipt
function showReceipt(saleData) {
    const receiptContent = document.getElementById('receipt-content');
    if (!receiptContent) return;
    
    const items = saleData.items || [];
    const sale = saleData.sale || {};
    
    receiptContent.innerHTML = `
        <div class="receipt">
            <h3 class="text-center">RECEIPT</h3>
            <p class="text-center">${sale.invoice_number}</p>
            <p class="text-center">${formatDate(sale.created_at)}</p>
            <hr>
            ${items.map(item => `
                <div class="d-flex justify-between">
                    <span>${item.product_name} x${item.quantity}</span>
                    <span>${formatCurrency(item.subtotal)}</span>
                </div>
            `).join('')}
            <hr>
            <div class="d-flex justify-between">
                <strong>Total</strong>
                <strong>${formatCurrency(sale.total_amount)}</strong>
            </div>
            <p class="text-center mt-3">Thank you for your purchase!</p>
        </div>
    `;
    
    openModal('receiptModal');
}

// Filter products
function filterProducts(searchTerm) {
    activeSearchTerm = String(searchTerm || '');
    applyFilters();
}

function filterByCategory(category) {
    activeCategory = String(category || '');
    applyFilters();
}

function applyFilters() {
    const q = activeSearchTerm.trim().toLowerCase();
    const cat = activeCategory;
    const filtered = products.filter((p) => {
        const matchesSearch = !q || p._searchKey.includes(q);
        const matchesCategory = !cat || p.category === cat;
        return matchesSearch && matchesCategory;
    });
    displayProductsGrid(filtered);
}

// Handle barcode/QR scan
async function handleScan(scannedData) {
    try {
        // Try to find product by code
        const response = await apiCall(`${API_BASE_URL}/products/get.php?code=${scannedData}`);
        if (response.success && response.product) {
            addToCart(response.product.id);
            showAlert(`Product added: ${response.product.name}`, 'success');
        } else {
            showAlert('Product not found', 'warning');
        }
    } catch (error) {
        console.error('Scan failed:', error);
        showAlert('Invalid product code', 'danger');
    }
}

// Highlight product
function highlightProduct(productId) {
    const productCard = document.querySelector(`.product-card[data-id="${productId}"]`);
    if (productCard) {
        productCard.classList.add('selected');
        setTimeout(() => {
            productCard.classList.remove('selected');
        }, 500);
    }
}
