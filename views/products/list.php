<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>StickerFlow | Bulk Barcode Labels - Shopping Cart Style</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: #eef2f7;
            font-family: 'Segoe UI', 'Roboto', system-ui, sans-serif;
            padding: 20px;
        }

        .barcode-app {
            max-width: 1400px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
            margin-bottom: 28px;
            overflow: hidden;
        }

        .card-header {
            padding: 18px 24px;
            background: #ffffff;
            border-bottom: 2px solid #f0f2f5;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .card-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1a2c3e;
        }

        .card-header h2 i {
            color: #2c7da0;
            margin-right: 8px;
        }

        .btn-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 8px 18px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fc;
            color: #2c3e50;
            border: 1px solid #dee2e6;
            transition: all 0.2s;
        }

        .btn-primary { background: #2c7da0; color: white; border: none; }
        .btn-primary:hover { background: #1f5e7a; }
        .btn-success { background: #2a9d8f; color: white; border: none; }
        .btn-success:hover { background: #21867a; }
        .btn-warning { background: #e9c46a; color: #2d3e50; border: none; }
        .btn-warning:hover { background: #e0b84d; }
        .btn-outline { background: transparent; border: 1px solid #cbd5e1; }
        .btn-danger { background: #dc3545; color: white; border: none; }
        .btn-danger:hover { background: #c82333; }

        .filters {
            padding: 16px 24px;
            background: #fefefe;
            border-bottom: 1px solid #edf2f7;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            align-items: flex-end;
        }

        .filter-item {
            flex: 1;
            min-width: 170px;
        }

        .filter-item label {
            font-size: 0.7rem;
            text-transform: uppercase;
            font-weight: 700;
            color: #5b6e8c;
            display: block;
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cfdfed;
            border-radius: 14px;
            font-size: 0.9rem;
            background: white;
        }

        .table-wrapper {
            overflow-x: auto;
            padding: 0 20px 20px 20px;
        }

        .product-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .product-table th, .product-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ecf3f9;
        }

        .product-table th {
            background: #f8fafd;
            font-weight: 700;
            color: #1e2f3f;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fed7aa; color: #92400e; }

        .barcode-preview {
            width: 140px;
            height: 55px;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ccc;
            padding: 4px;
        }
        
        .barcode-preview canvas {
            max-width: 100%;
            height: auto;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal.show { display: flex; }
        .modal-dialog {
            background: white;
            border-radius: 28px;
            width: 95%;
            max-width: 1300px;
            max-height: 90vh;
            overflow: auto;
        }
        .modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid #eef2f8;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-close {
            font-size: 28px;
            background: none;
            border: none;
            cursor: pointer;
            color: #94a3b8;
        }
        .modal-body { padding: 20px 24px; }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #eef2f8;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Shopping Cart Layout - Two Columns */
        .cart-layout {
            display: flex;
            gap: 20px;
            min-height: 500px;
        }
        
        /* Left Panel - Product Search & List */
        .products-panel {
            flex: 2;
            border-right: 1px solid #e2edf7;
            padding-right: 20px;
        }
        
        /* Right Panel - Shopping Cart */
        .cart-panel {
            flex: 1.2;
            background: #f8fafc;
            border-radius: 20px;
            padding: 16px;
            position: sticky;
            top: 20px;
            height: fit-content;
            max-height: 600px;
            overflow-y: auto;
        }
        
        .cart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid #2c7da0;
        }
        
        .cart-header h4 {
            color: #1a2c3e;
            font-size: 1.1rem;
        }
        
        .cart-header h4 i {
            color: #2c7da0;
            margin-right: 8px;
        }
        
        .cart-items {
            max-height: 350px;
            overflow-y: auto;
        }
        
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            margin: 8px 0;
            background: white;
            border-radius: 12px;
            border: 1px solid #e2edf7;
            transition: all 0.2s;
        }
        
        .cart-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .cart-item-info {
            flex: 1;
        }
        
        .cart-item-name {
            font-weight: 600;
            font-size: 0.85rem;
            color: #1a2c3e;
        }
        
        .cart-item-code {
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        .cart-item-price {
            font-weight: 600;
            color: #2c7da0;
            font-size: 0.85rem;
        }
        
        .cart-item-remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1.2rem;
            padding: 4px 8px;
            border-radius: 20px;
            transition: all 0.2s;
        }
        
        .cart-item-remove:hover {
            background: #dc3545;
            color: white;
        }
        
        .cart-footer {
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid #dee2e6;
        }
        
        .cart-total {
            display: flex;
            justify-content: space-between;
            font-weight: 700;
            margin-bottom: 16px;
            font-size: 1rem;
        }
        
        .empty-cart {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-cart i {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        
        .bulk-search-bar {
            margin-bottom: 16px;
        }
        
        .bulk-search-bar input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #cfdfed;
            border-radius: 30px;
            font-size: 0.95rem;
            background: white;
            padding-left: 42px;
            background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="%23666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="10" cy="10" r="7"></circle><line x1="21" y1="21" x2="15" y2="15"></line></svg>');
            background-repeat: no-repeat;
            background-position: 14px center;
        }
        
        .bulk-search-bar input:focus {
            outline: none;
            border-color: #2c7da0;
            box-shadow: 0 0 0 3px rgba(44,125,160,0.1);
        }
        
        .product-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e2edf7;
            border-radius: 20px;
            padding: 8px;
            background: white;
        }
        
        .product-item {
            padding: 12px;
            border-bottom: 1px solid #edf2f7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }
        
        .product-item:hover {
            background: #f8fafc;
        }
        
        .product-item:last-child {
            border-bottom: none;
        }
        
        .product-item-info {
            flex: 1;
        }
        
        .product-item-name {
            font-weight: 600;
            color: #1a2c3e;
            font-size: 0.9rem;
        }
        
        .product-item-code {
            font-size: 0.75rem;
            color: #6c757d;
        }
        
        .product-item-price {
            font-weight: 600;
            color: #2c7da0;
            font-size: 0.9rem;
        }
        
        .add-to-cart-btn {
            background: #2a9d8f;
            border: none;
            color: white;
            padding: 6px 16px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.2s;
            margin-left: 12px;
        }
        
        .add-to-cart-btn:hover {
            background: #21867a;
            transform: scale(1.02);
        }
        
        .already-in-cart {
            background: #e9ecef;
            color: #6c757d;
            padding: 6px 12px;
            border-radius: 25px;
            font-size: 0.7rem;
            margin-left: 12px;
            white-space: nowrap;
        }
        
        .search-stats {
            font-size: 0.75rem;
            color: #6c757d;
            margin: 10px 0;
            padding: 6px 12px;
            background: #f8f9fa;
            border-radius: 20px;
            display: inline-block;
        }
        
        .print-iframe {
            position: absolute;
            width: 0;
            height: 0;
            border: 0;
            visibility: hidden;
        }
        
        .label-card {
            width: 50mm;
            height: 30mm;
            border: 0.5px solid black;
            padding: 1.5mm 2mm;
            background: white;
            page-break-after: always;
            break-after: page;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-sizing: border-box;
        }
        
        .product-name-print {
            font-weight: 800;
            font-size: 2.4mm;
            text-transform: uppercase;
            line-height: 1.2;
            margin-bottom: 1mm;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        
        .info-row-print {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1mm;
        }
        
        .sku-code-print {
            font-weight: 800;
            font-size: 2.5mm;
            font-family: monospace;
        }
        
        .retail-price-print {
            font-weight: 800;
            font-size: 2.8mm;
        }
        
        .price-codes-print {
            display: flex;
            justify-content: center;
            gap: 3mm;
            padding: 1mm 0;
            border-top: 0.3px solid #aaa;
            border-bottom: 0.3px solid #aaa;
        }
        
        .barcode-container-print {
            display: flex;
            justify-content: center;
            margin-top: 1mm;
        }
        
        .barcode-container-print canvas {
            width: 44mm !important;
            height: 9mm !important;
        }
        
        .loading-indicator {
            text-align: center;
            padding: 40px;
            color: #2c7da0;
        }
        
        .cart-badge {
            background: #2c7da0;
            color: white;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 0.7rem;
            margin-left: 8px;
        }
        
        .product-loading {
            text-align: center;
            padding: 40px;
            color: #6c757d;
        }
        
        .product-loading i {
            font-size: 32px;
            margin-bottom: 12px;
        }
        
        /* Action buttons row */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin: 16px 0;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .action-buttons .btn-sm {
            padding: 6px 16px;
        }
        
        .selection-info {
            font-size: 0.8rem;
            color: #2c7da0;
            font-weight: 600;
            margin-left: auto;
        }
    </style>
</head>
<body>
<div class="barcode-app">
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-tag"></i> StickerFlow Barcode Labels</h2>
            <div class="btn-group">
                <a href="/pos-system/views/dashboard/index.php" class="btn btn-outline"><i class="fas fa-home"></i> Back to Dashboard</a>
                <button class="btn btn-success" id="bulkStickerBtn"><i class="fas fa-layer-group"></i> Bulk 50x30mm (Cart Style)</button>
                <button class="btn btn-warning" id="bulkSticker30x20Btn"><i class="fas fa-print"></i> Bulk 30x20mm</button>
                <button class="btn btn-primary" id="refreshTableBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
            </div>
        </div>
        <div class="filters">
            <div class="filter-item">
                <label>🔍 Search (name/code)</label>
                <input type="text" id="searchInput" class="form-control" placeholder="Filter by product name or code...">
            </div>
            <div class="filter-item">
                <label>📁 Category</label>
                <select id="categoryFilter" class="form-control"><option value="">All</option></select>
            </div>
        </div>
        <div class="table-wrapper">
            <table class="product-table">
                <thead> Bon<th>Code</th><th>Product Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Preview</th><th>Actions</th> </thead>
                <tbody id="productTableBody"><tr><td colspan="7" class="loading-indicator"><i class="fas fa-spinner fa-spin"></i><br>Loading products...</td></tr></tbody>
              </table>
        </div>
    </div>
</div>

<iframe id="printFrame" class="print-iframe"></iframe>

<!-- BULK MODAL 50x30 with SHOPPING CART STYLE -->
<div class="modal" id="bulkStickerModal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-shopping-cart"></i> Add Products to Cart - 50x30mm Labels</h3>
            <button class="modal-close" onclick="closeModal('bulkStickerModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="cart-layout">
                <!-- LEFT PANEL: Products to browse and add -->
                <div class="products-panel">
                    <div class="bulk-search-bar">
                        <input type="text" id="bulkSearchInput" placeholder="🔍 Search by product name or code...">
                    </div>
                    <div id="bulkSearchStats" class="search-stats"></div>
                    
                    <!-- Action Buttons for Select All / Deselect All shown products -->
                    <div class="action-buttons">
                        <button class="btn btn-primary btn-sm" id="selectAllShownBtn"><i class="fas fa-check-double"></i> Select All Shown</button>
                        <button class="btn btn-outline btn-sm" id="deselectAllShownBtn"><i class="fas fa-times"></i> Deselect All Shown</button>
                        <span class="selection-info" id="selectionInfo">0 shown, 0 in cart</span>
                    </div>
                    
                    <div class="product-list" id="productList">
                        <div class="product-loading"><i class="fas fa-spinner fa-spin"></i><br>Loading products...</div>
                    </div>
                </div>
                
                <!-- RIGHT PANEL: Shopping Cart -->
                <div class="cart-panel">
                    <div class="cart-header">
                        <h4><i class="fas fa-shopping-cart"></i> Your Cart</h4>
                        <button class="btn-danger btn-sm" id="clearCartBtn" style="padding: 4px 12px; font-size: 0.7rem;">Clear All</button>
                    </div>
                    <div class="cart-items" id="cartItems">
                        <div class="empty-cart">
                            <i class="fas fa-cart-plus"></i>
                            <p>Your cart is empty</p>
                            <small>Search and click "Add to Cart" to add items</small>
                        </div>
                    </div>
                    <div class="cart-footer">
                        <div class="cart-total">
                            <span>Total Items:</span>
                            <span id="cartTotalCount">0</span>
                        </div>
                        <button class="btn btn-success" id="checkoutBtn" style="width: 100%;">
                            <i class="fas fa-print"></i> Print All
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('bulkStickerModal')">Close</button>
        </div>
    </div>
</div>

<!-- BULK MODAL 30x20 -->
<div class="modal" id="bulkSticker30x20Modal">
    <div class="modal-dialog">
        <div class="modal-header">
            <h3><i class="fas fa-print"></i> Select Products - 30x20mm Labels</h3>
            <button class="modal-close" onclick="closeModal('bulkSticker30x20Modal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="cart-layout">
                <div class="products-panel">
                    <div class="bulk-search-bar">
                        <input type="text" id="bulkSearchInput30x20" placeholder="🔍 Search by product name or code...">
                    </div>
                    <div id="bulkSearchStats30x20" class="search-stats"></div>
                    
                    <!-- Action Buttons for Select All / Deselect All shown products -->
                    <div class="action-buttons">
                        <button class="btn btn-primary btn-sm" id="selectAllShownBtn30x20"><i class="fas fa-check-double"></i> Select All Shown</button>
                        <button class="btn btn-outline btn-sm" id="deselectAllShownBtn30x20"><i class="fas fa-times"></i> Deselect All Shown</button>
                        <span class="selection-info" id="selectionInfo30x20">0 shown, 0 in cart</span>
                    </div>
                    
                    <div class="product-list" id="productList30x20">
                        <div class="product-loading"><i class="fas fa-spinner fa-spin"></i><br>Loading products...</div>
                    </div>
                </div>
                <div class="cart-panel">
                    <div class="cart-header">
                        <h4><i class="fas fa-shopping-cart"></i> Your Cart (30x20mm)</h4>
                        <button class="btn-danger btn-sm" id="clearCartBtn30x20" style="padding: 4px 12px;">Clear All</button>
                    </div>
                    <div class="cart-items" id="cartItems30x20">
                        <div class="empty-cart"><i class="fas fa-cart-plus"></i><p>Your cart is empty</p></div>
                    </div>
                    <div class="cart-footer">
                        <div class="cart-total"><span>Total Items:</span><span id="cartTotalCount30x20">0</span></div>
                        <button class="btn btn-warning" id="checkoutBtn30x20" style="width: 100%;"><i class="fas fa-print"></i> Print All</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('bulkSticker30x20Modal')">Close</button>
        </div>
    </div>
</div>

<script>
    const priceMap = {0:'N',1:'L',2:'R',3:'M',4:'A',5:'Z',6:'G',7:'F',8:'B',9:'P'};
    
    function encodePriceValue(price) {
        let intPrice = Math.round(parseFloat(price || 0));
        if(isNaN(intPrice)) intPrice = 0;
        let encoded = '';
        const str = intPrice.toString();
        for(let ch of str) encoded += priceMap[parseInt(ch)];
        return encoded;
    }

    let allProducts = [];
    // Shopping cart - stores products
    let cart50 = [];
    let cart30 = [];
    let currentSearchTerm = '';
    let currentSearchTerm30 = '';
    let currentFilteredProducts50 = [];
    let currentFilteredProducts30 = [];

    function escapeHtml(str) { 
        if(!str) return ''; 
        return str.replace(/[&<>]/g, function(m){ 
            if(m==='&') return '&amp;'; 
            if(m==='<') return '&lt;'; 
            if(m==='>') return '&gt;'; 
            return m;
        }); 
    }
    
    // Search function - searches BOTH name AND code from all products
    function filterProductsBySearch(products, searchTerm) {
        if (!searchTerm || searchTerm.trim() === '') {
            return products;
        }
        const term = searchTerm.toLowerCase().trim();
        return products.filter(p => {
            const nameMatch = p.name ? p.name.toLowerCase().includes(term) : false;
            const codeMatch = (p.product_code && p.product_code.toLowerCase().includes(term)) || false;
            return nameMatch || codeMatch;
        });
    }
    
    function renderPreviewBarcode(container, codeValue) {
        if(!container) return;
        container.innerHTML = '';
        try {
            let canvas = document.createElement('canvas');
            container.appendChild(canvas);
            JsBarcode(canvas, codeValue, {
                format: "CODE128",
                width: 1.8,
                height: 45,
                displayValue: true,
                fontSize: 9,
                font: "monospace",
                margin: 4
            });
        } catch(e) { 
            container.innerHTML = '<span>Error</span>'; 
        }
    }
    
    function printToIframe(htmlContent) {
        const iframe = document.getElementById('printFrame');
        iframe.srcdoc = htmlContent;
        iframe.onload = function() {
            setTimeout(function() {
                try {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                } catch(e) {
                    alert('Unable to print. Please try again.');
                }
            }, 800);
        };
    }
    
    function printLabels50x30(products) {
        if (!products || products.length === 0) return;
        let labelsHtml = '';
        products.forEach(prod => {
            const name = escapeHtml(prod.name);
            const sku = prod.product_code || `ID${prod.id}`;
            const wholesale = prod.wholesale_price !== undefined ? prod.wholesale_price : (prod.price * 0.75);
            const cost = prod.cost_price !== undefined ? prod.cost_price : (prod.price * 0.6);
            const retail = prod.retail_price !== undefined ? prod.retail_price : prod.price;
            const retailPrice = `₱${parseFloat(retail).toFixed(2)}`;
            const encodedWhole = encodePriceValue(wholesale);
            const encodedCost = encodePriceValue(cost);
            const encodedRetail = encodePriceValue(retail);
            const uniqueId = `barcode_${prod.id}_${Date.now()}_${Math.random()}`;
            labelsHtml += `<div class="label-card"><div class="product-name-print">${name}</div><div class="info-row-print"><span class="sku-code-print">${sku}</span><span class="retail-price-print">${retailPrice}</span></div><div class="price-codes-print"><span><strong>C:</strong> ${encodedCost}</span><span><strong>W:</strong> ${encodedWhole}</span><span><strong>R:</strong> ${encodedRetail}</span></div><div class="barcode-container-print" id="${uniqueId}"></div></div>`;
        });
        const htmlContent = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>50x30mm Labels</title><script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script><style>*{margin:0;padding:0;box-sizing:border-box;}body{background:white;margin:0;padding:2mm;}.label-card{width:50mm;height:30mm;border:0.5px solid black;padding:1.5mm 2mm;background:white;page-break-after:always;break-after:page;display:flex;flex-direction:column;justify-content:space-between;}.product-name-print{font-weight:800;font-size:2.4mm;text-transform:uppercase;line-height:1.2;margin-bottom:1mm;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;}.info-row-print{display:flex;justify-content:space-between;margin-bottom:1mm;}.sku-code-print{font-weight:800;font-size:2.5mm;font-family:monospace;}.retail-price-print{font-weight:800;font-size:2.8mm;}.price-codes-print{display:flex;justify-content:center;gap:3mm;padding:1mm 0;border-top:0.3px solid #aaa;border-bottom:0.3px solid #aaa;font-size:2.3mm;}.barcode-container-print{display:flex;justify-content:center;margin-top:1mm;}.barcode-container-print canvas{width:44mm !important;height:9mm !important;}@page{size:50mm 30mm;margin:0;}@media print{body{margin:0;padding:0;}.label-card{border:0.5px solid black;page-break-after:always;break-after:page;}}</style></head><body>${labelsHtml}<script>window.onload=function(){document.querySelectorAll('.barcode-container-print').forEach(container=>{const card=container.closest('.label-card');const skuElement=card.querySelector('.sku-code-print');if(skuElement){const skuValue=skuElement.innerText;const canvas=document.createElement('canvas');container.appendChild(canvas);JsBarcode(canvas,skuValue,{format:"CODE128",width:2.4,height:55,displayValue:true,fontSize:10,font:"monospace",margin:4,textMargin:2});}});setTimeout(()=>window.print(),500);};<\/script></body></html>`;
        printToIframe(htmlContent);
    }
    
    function printLabels30x20(products) {
        if (!products || products.length === 0) return;
        let labelsHtml = '';
        products.forEach(prod => {
            const name = escapeHtml(prod.name);
            const sku = prod.product_code || `ID${prod.id}`;
            const wholesale = prod.wholesale_price !== undefined ? prod.wholesale_price : (prod.price * 0.75);
            const cost = prod.cost_price !== undefined ? prod.cost_price : (prod.price * 0.6);
            const retail = prod.retail_price !== undefined ? prod.retail_price : prod.price;
            const retailPrice = `₱${parseFloat(retail).toFixed(2)}`;
            const encodedWhole = encodePriceValue(wholesale);
            const encodedCost = encodePriceValue(cost);
            const encodedRetail = encodePriceValue(retail);
            const uniqueId = `barcode_${prod.id}_${Date.now()}_${Math.random()}`;
            labelsHtml += `<div class="label-card" style="width:30mm;height:20mm;padding:1mm;"><div style="font-weight:800;font-size:1.9mm;text-transform:uppercase;margin-bottom:0.5mm;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">${name}</div><div style="display:flex;justify-content:space-between;margin-bottom:0.5mm;"><span style="font-weight:800;font-size:1.9mm;">${sku}</span><span style="font-weight:800;font-size:2.1mm;">${retailPrice}</span></div><div style="display:flex;justify-content:center;gap:1.8mm;padding:0.3mm 0;border-top:0.3px solid #aaa;border-bottom:0.3px solid #aaa;font-size:1.7mm;"><span><strong>C:</strong> ${encodedCost}</span><span><strong>W:</strong> ${encodedWhole}</span><span><strong>R:</strong> ${encodedRetail}</span></div><div class="barcode-container-print" id="${uniqueId}" style="display:flex;justify-content:center;"></div></div>`;
        });
        const htmlContent = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>30x20mm Labels</title><script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script><style>*{margin:0;padding:0;box-sizing:border-box;}body{background:white;margin:0;padding:1mm;}.label-card{width:30mm;height:20mm;border:0.5px solid black;background:white;page-break-after:always;break-after:page;display:flex;flex-direction:column;justify-content:space-between;}.barcode-container-print canvas{width:26mm !important;height:6.5mm !important;}@page{size:30mm 20mm;margin:0;}@media print{body{margin:0;padding:0;}.label-card{border:0.5px solid black;page-break-after:always;break-after:page;}}</style></head><body>${labelsHtml}<script>window.onload=function(){document.querySelectorAll('.barcode-container-print').forEach(container=>{const card=container.closest('.label-card');const skuElement=card.querySelector('span:first-child');if(skuElement){const skuValue=skuElement.innerText;const canvas=document.createElement('canvas');container.appendChild(canvas);JsBarcode(canvas,skuValue,{format:"CODE128",width:1.6,height:36,displayValue:true,fontSize:7,font:"monospace",margin:2,textMargin:1});}});setTimeout(()=>window.print(),500);};<\/script></body></html>`;
        printToIframe(htmlContent);
    }
    
    // Select all shown products and add them to cart
    function selectAllShown(size) {
        const productsToAdd = size === '50' ? currentFilteredProducts50 : currentFilteredProducts30;
        const cart = size === '50' ? cart50 : cart30;
        
        productsToAdd.forEach(product => {
            if (!cart.some(item => item.id === product.id)) {
                cart.push(product);
            }
        });
        
        if (size === '50') {
            renderCart('cartItems', 'cartTotalCount', cart50, '50');
            renderProductList('productList', currentSearchTerm, 'bulkSearchStats', cart50, '50');
            updateCheckoutButton();
            updateSelectionInfo('selectionInfo', currentFilteredProducts50.length, cart50.length);
        } else {
            renderCart('cartItems30x20', 'cartTotalCount30x20', cart30, '30');
            renderProductList('productList30x20', currentSearchTerm30, 'bulkSearchStats30x20', cart30, '30');
            updateSelectionInfo('selectionInfo30x20', currentFilteredProducts30.length, cart30.length);
        }
    }
    
    // Deselect all shown products (remove from cart)
    function deselectAllShown(size) {
        const productsToRemove = size === '50' ? currentFilteredProducts50 : currentFilteredProducts30;
        const cart = size === '50' ? cart50 : cart30;
        
        productsToRemove.forEach(product => {
            const index = cart.findIndex(item => item.id === product.id);
            if (index !== -1) {
                cart.splice(index, 1);
            }
        });
        
        if (size === '50') {
            renderCart('cartItems', 'cartTotalCount', cart50, '50');
            renderProductList('productList', currentSearchTerm, 'bulkSearchStats', cart50, '50');
            updateCheckoutButton();
            updateSelectionInfo('selectionInfo', currentFilteredProducts50.length, cart50.length);
        } else {
            renderCart('cartItems30x20', 'cartTotalCount30x20', cart30, '30');
            renderProductList('productList30x20', currentSearchTerm30, 'bulkSearchStats30x20', cart30, '30');
            updateSelectionInfo('selectionInfo30x20', currentFilteredProducts30.length, cart30.length);
        }
    }
    
    function updateSelectionInfo(infoSpanId, shownCount, cartCount) {
        const span = document.getElementById(infoSpanId);
        if (span) {
            span.innerHTML = `${shownCount} shown, ${cartCount} in cart`;
        }
    }
    
    // Render product list (left panel) - uses ALL products from database
    function renderProductList(containerId, searchTerm, statsSpanId, cartArray, size) {
        const container = document.getElementById(containerId);
        if (!container) return;
        
        if (!allProducts.length) { 
            container.innerHTML = '<div class="product-loading"><i class="fas fa-spinner fa-spin"></i><br>Loading products...</div>';
            return; 
        }
        
        // Filter ALL products by search term (searches both name and code)
        let filtered = filterProductsBySearch(allProducts, searchTerm);
        
        // Store filtered products for select/deselect all functionality
        if (size === '50') {
            currentFilteredProducts50 = filtered;
        } else {
            currentFilteredProducts30 = filtered;
        }
        
        const statsSpan = document.getElementById(statsSpanId);
        if(statsSpan) {
            const totalVisible = filtered.length;
            const totalAll = allProducts.length;
            if(searchTerm && searchTerm.trim() !== '') {
                statsSpan.innerHTML = `🔍 Found <strong>${totalVisible}</strong> product${totalVisible !== 1 ? 's' : ''} matching "<strong>${escapeHtml(searchTerm)}</strong>" (out of ${totalAll} total)`;
            } else {
                statsSpan.innerHTML = `📦 Showing all <strong>${totalAll}</strong> products`;
            }
        }
        
        // Update selection info
        updateSelectionInfo(size === '50' ? 'selectionInfo' : 'selectionInfo30x20', filtered.length, cartArray.length);
        
        if(filtered.length === 0) {
            container.innerHTML = '<div class="no-results-message"><i class="fas fa-search"></i><p>No products match your search.<br>Try a different keyword.</p></div>';
            return;
        }
        
        let html = '';
        filtered.forEach(p => {
            const isInCart = cartArray.some(item => item.id === p.id);
            html += `
                <div class="product-item" data-product-id="${p.id}">
                    <div class="product-item-info">
                        <div class="product-item-name">${escapeHtml(p.name)}</div>
                        <div class="product-item-code">${escapeHtml(p.product_code || 'N/A')}</div>
                        <div class="product-item-price">₱${parseFloat(p.price).toFixed(2)}</div>
                    </div>
                    ${isInCart ? 
                        '<span class="already-in-cart"><i class="fas fa-check"></i> In Cart</span>' : 
                        `<button class="add-to-cart-btn" onclick="addToCart(${p.id}, '${size}')"><i class="fas fa-cart-plus"></i> Add to Cart</button>`
                    }
                </div>
            `;
        });
        container.innerHTML = html;
    }
    
    // Add to cart function
    window.addToCart = function(productId, size) {
        const product = allProducts.find(p => p.id === productId);
        if (!product) return;
        
        if (size === '50') {
            if (!cart50.some(item => item.id === productId)) {
                cart50.push(product);
                renderCart('cartItems', 'cartTotalCount', cart50, '50');
                renderProductList('productList', currentSearchTerm, 'bulkSearchStats', cart50, '50');
                updateCheckoutButton();
            }
        } else {
            if (!cart30.some(item => item.id === productId)) {
                cart30.push(product);
                renderCart('cartItems30x20', 'cartTotalCount30x20', cart30, '30');
                renderProductList('productList30x20', currentSearchTerm30, 'bulkSearchStats30x20', cart30, '30');
            }
        }
    };
    
    // Remove from cart
    window.removeFromCart = function(productId, size) {
        if (size === '50') {
            cart50 = cart50.filter(item => item.id !== productId);
            renderCart('cartItems', 'cartTotalCount', cart50, '50');
            renderProductList('productList', currentSearchTerm, 'bulkSearchStats', cart50, '50');
            updateCheckoutButton();
        } else {
            cart30 = cart30.filter(item => item.id !== productId);
            renderCart('cartItems30x20', 'cartTotalCount30x20', cart30, '30');
            renderProductList('productList30x20', currentSearchTerm30, 'bulkSearchStats30x20', cart30, '30');
        }
    };
    
    // Render cart items
    function renderCart(containerId, totalCountId, cartArray, size) {
        const container = document.getElementById(containerId);
        const totalCountSpan = document.getElementById(totalCountId);
        
        totalCountSpan.textContent = cartArray.length;
        
        if (cartArray.length === 0) {
            container.innerHTML = '<div class="empty-cart"><i class="fas fa-cart-plus"></i><p>Your cart is empty</p><small>Search and click "Add to Cart" to add items</small></div>';
            return;
        }
        
        let html = '';
        cartArray.forEach(item => {
            html += `
                <div class="cart-item">
                    <div class="cart-item-info">
                        <div class="cart-item-name">${escapeHtml(item.name)}</div>
                        <div class="cart-item-code">${escapeHtml(item.product_code || 'N/A')}</div>
                        <div class="cart-item-price">₱${parseFloat(item.price).toFixed(2)}</div>
                    </div>
                    <button class="cart-item-remove" onclick="removeFromCart(${item.id}, '${size}')" title="Remove">&times;</button>
                </div>
            `;
        });
        container.innerHTML = html;
    }
    
    function updateCheckoutButton() {
        const btn = document.getElementById('checkoutBtn');
        if (btn) {
            btn.innerHTML = `<i class="fas fa-print"></i> Print ${cart50.length} Label${cart50.length !== 1 ? 's' : ''}`;
        }
    }
    
    function clearCart(size) {
        if (size === '50') {
            cart50 = [];
            renderCart('cartItems', 'cartTotalCount', cart50, '50');
            renderProductList('productList', currentSearchTerm, 'bulkSearchStats', cart50, '50');
            updateCheckoutButton();
            updateSelectionInfo('selectionInfo', currentFilteredProducts50.length, 0);
        } else {
            cart30 = [];
            renderCart('cartItems30x20', 'cartTotalCount30x20', cart30, '30');
            renderProductList('productList30x20', currentSearchTerm30, 'bulkSearchStats30x20', cart30, '30');
            updateSelectionInfo('selectionInfo30x20', currentFilteredProducts30.length, 0);
        }
    }
    
    function checkout(size) {
        const cart = size === '50' ? cart50 : cart30;
        if (cart.length === 0) {
            alert('Your cart is empty. Please add some products first.');
            return;
        }
        
        if (size === '50') {
            printLabels50x30(cart);
        } else {
            printLabels30x20(cart);
        }
    }
    
    function openBulkStickerModal() {
        const searchInput = document.getElementById('bulkSearchInput');
        searchInput.value = '';
        currentSearchTerm = '';
        renderProductList('productList', '', 'bulkSearchStats', cart50, '50');
        renderCart('cartItems', 'cartTotalCount', cart50, '50');
        updateCheckoutButton();
        openModal('bulkStickerModal');
        
        let searchTimeout;
        const handleSearch = () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentSearchTerm = searchInput.value;
                renderProductList('productList', currentSearchTerm, 'bulkSearchStats', cart50, '50');
            }, 300);
        };
        searchInput.oninput = handleSearch;
    }
    
    function openBulkSticker30x20Modal() {
        const searchInput = document.getElementById('bulkSearchInput30x20');
        searchInput.value = '';
        currentSearchTerm30 = '';
        renderProductList('productList30x20', '', 'bulkSearchStats30x20', cart30, '30');
        renderCart('cartItems30x20', 'cartTotalCount30x20', cart30, '30');
        openModal('bulkSticker30x20Modal');
        
        let searchTimeout;
        const handleSearch = () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                currentSearchTerm30 = searchInput.value;
                renderProductList('productList30x20', currentSearchTerm30, 'bulkSearchStats30x20', cart30, '30');
            }, 300);
        };
        searchInput.oninput = handleSearch;
    }
    
    // Load ALL products from the database
    async function loadAllProductsFromDB() {
        try {
            const response = await fetch('/pos-system/api/products/list.php?status=active');
            const data = await response.json();
            
            if (data.success && Array.isArray(data.data)) {
                allProducts = data.data;
                console.log(`Loaded ${allProducts.length} products from API`);
                return true;
            } else if (Array.isArray(data)) {
                allProducts = data;
                console.log(`Loaded ${allProducts.length} products from API`);
                return true;
            } else {
                console.log('API returned unexpected format, using fallback data');
                return false;
            }
        } catch (error) {
            console.error('Error loading products from API:', error);
            return false;
        }
    }
    
    async function loadProducts() {
        const productTableBody = document.getElementById('productTableBody');
        productTableBody.innerHTML = '<tr><td colspan="7" class="loading-indicator"><i class="fas fa-spinner fa-spin"></i><br>Loading products from database...</td></tr>';
        
        const success = await loadAllProductsFromDB();
        if (!success) {
            allProducts = [];
            productTableBody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#b91c1c">Unable to load products. Check the server connection and refresh this page.</td></tr>';
            return;
        }
        
        populateFilters();
        renderProductTable();
    }
    
    function populateFilters() {
        let cats = [...new Set(allProducts.map(p=>p.category).filter(c=>c))];
        const catSel = document.getElementById('categoryFilter');
        catSel.innerHTML = '<option value="">All categories</option>' + cats.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
    }
    
    function renderProductTable() {
        const searchTerm = document.getElementById('searchInput').value;
        const category = document.getElementById('categoryFilter').value;
        let filtered = filterProductsBySearch(allProducts, searchTerm);
        if(category) filtered = filtered.filter(p => p.category === category);
        const tbody = document.getElementById('productTableBody');
        if(!filtered.length) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center">No products found</td></tr>'; return; }
        let html = '';
        filtered.forEach(p => {
            let stockClass = (p.quantity > 10) ? 'badge-success' : 'badge-warning';
            let previewId = `preview_${p.id}`;
            html += `<tr>
                        <td><small>${escapeHtml(p.product_code || 'N/A')}</small></td>
                        <td><strong>${escapeHtml(p.name)}</strong></td>
                        <td>${escapeHtml(p.category || '-')}</td>
                        <td>₱${parseFloat(p.price).toFixed(2)}</td>
                        <td><span class="badge ${stockClass}">${p.quantity || 0}</span></td>
                        <td><div class="barcode-preview" id="${previewId}"></div></td>
                        <td>
                            <button class="btn btn-primary btn-sm" onclick="selectAndPrint50x30(${p.id})"><i class="fas fa-print"></i> 50x30</button>
                            <button class="btn btn-warning btn-sm" onclick="selectAndPrint30x20(${p.id})" style="margin-left:5px;"><i class="fas fa-print"></i> 30x20</button>
                         </td>
                      </tr>`;
        });
        tbody.innerHTML = html;
        filtered.forEach(p => {
            const previewDiv = document.getElementById(`preview_${p.id}`);
            if(previewDiv) renderPreviewBarcode(previewDiv, p.product_code || `P${p.id}`);
        });
    }
    
    function selectAndPrint50x30(id) {
        const product = allProducts.find(p => p.id === id);
        if(product) printLabels50x30([product]);
    }
    
    function selectAndPrint30x20(id) {
        const product = allProducts.find(p => p.id === id);
        if(product) printLabels30x20([product]);
    }
    
    function openModal(id) { document.getElementById(id).classList.add('show'); }
    function closeModal(id) { document.getElementById(id).classList.remove('show'); }
    
    window.selectAndPrint50x30 = selectAndPrint50x30;
    window.selectAndPrint30x20 = selectAndPrint30x20;
    window.closeModal = closeModal;
    window.addToCart = addToCart;
    window.removeFromCart = removeFromCart;
    
    document.getElementById('searchInput').addEventListener('input', renderProductTable);
    document.getElementById('categoryFilter').addEventListener('change', renderProductTable);
    document.getElementById('refreshTableBtn').addEventListener('click', () => loadProducts());
    document.getElementById('bulkStickerBtn').addEventListener('click', openBulkStickerModal);
    document.getElementById('bulkSticker30x20Btn').addEventListener('click', openBulkSticker30x20Modal);
    document.getElementById('clearCartBtn').addEventListener('click', () => clearCart('50'));
    document.getElementById('clearCartBtn30x20').addEventListener('click', () => clearCart('30'));
    document.getElementById('checkoutBtn').addEventListener('click', () => checkout('50'));
    document.getElementById('checkoutBtn30x20').addEventListener('click', () => checkout('30'));
    
    // Select All / Deselect All buttons
    document.getElementById('selectAllShownBtn').addEventListener('click', () => selectAllShown('50'));
    document.getElementById('deselectAllShownBtn').addEventListener('click', () => deselectAllShown('50'));
    document.getElementById('selectAllShownBtn30x20').addEventListener('click', () => selectAllShown('30'));
    document.getElementById('deselectAllShownBtn30x20').addEventListener('click', () => deselectAllShown('30'));
    
    loadProducts();
</script>
</body>
</html>
