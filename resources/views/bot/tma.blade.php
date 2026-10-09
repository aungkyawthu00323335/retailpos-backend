<!DOCTYPE html>
<html lang="my">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>{{ $storeName ?? 'Shop Now' }} | Telegram Mini App</title>
    <!-- Telegram Web App Official JS SDK -->
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <style>
        :root {
            --bg-color: var(--tg-theme-bg-color, #f8fafc);
            --text-color: var(--tg-theme-text-color, #0f172a);
            --hint-color: var(--tg-theme-hint-color, #64748b);
            --link-color: var(--tg-theme-link-color, #2563eb);
            --button-color: var(--tg-theme-button-color, #0284c7);
            --button-text-color: var(--tg-theme-button-text-color, #ffffff);
            --secondary-bg-color: var(--tg-theme-secondary-bg-color, #ffffff);
            --card-border: rgba(148, 163, 184, 0.2);
            --accent: #0284c7;
            --accent-rgb: 2, 132, 199;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            -webkit-tap-highlight-color: transparent;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Pyidaungsu";
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            min-height: 100vh;
            padding-bottom: 90px;
            overflow-x: hidden;
        }

        /* Top Header */
        header {
            position: sticky;
            top: 0;
            z-index: 40;
            background-color: var(--secondary-bg-color);
            border-bottom: 1px solid var(--card-border);
            padding: 12px 16px;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .header-title-box {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header-avatar {
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0284c7, #38bdf8);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            font-weight: 700;
            font-size: 18px;
            overflow: hidden;
        }

        .header-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .header-title {
            font-size: 16px;
            font-weight: 700;
            line-height: 1.2;
        }

        .header-subtitle {
            font-size: 11px;
            color: var(--hint-color);
        }

        .header-cart-btn {
            position: relative;
            background: rgba(var(--accent-rgb), 0.1);
            color: var(--accent);
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            cursor: pointer;
        }

        .header-cart-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--danger);
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            min-width: 18px;
            height: 18px;
            padding: 0 4px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Search & Filter Bar */
        .search-container {
            padding: 12px 16px;
            background-color: var(--secondary-bg-color);
            border-bottom: 1px solid var(--card-border);
        }

        .search-box {
            display: flex;
            align-items: center;
            background: var(--bg-color);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 8px 12px;
            gap: 8px;
        }

        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            width: 100%;
            font-size: 14px;
            color: var(--text-color);
        }

        /* Category Chips Bar */
        .category-scroll {
            display: flex;
            gap: 8px;
            padding: 12px 16px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .category-scroll::-webkit-scrollbar {
            display: none;
        }

        .category-chip {
            padding: 7px 14px;
            border-radius: 20px;
            background: var(--secondary-bg-color);
            border: 1px solid var(--card-border);
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            color: var(--text-color);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .category-chip.active {
            background: var(--button-color);
            color: var(--button-text-color);
            border-color: var(--button-color);
            box-shadow: 0 2px 8px rgba(var(--accent-rgb), 0.3);
        }

        /* Products Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            padding: 12px 16px;
        }

        @media (min-width: 640px) {
            .products-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 16px;
            }
        }

        .product-card {
            background: var(--secondary-bg-color);
            border-radius: 16px;
            border: 1px solid var(--card-border);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            position: relative;
        }

        .product-card:active {
            transform: scale(0.98);
        }

        .product-image-wrap {
            position: relative;
            width: 100%;
            padding-top: 100%;
            background: rgba(148, 163, 184, 0.1);
            overflow: hidden;
        }

        .product-image {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-stock-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            padding: 3px 8px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            backdrop-filter: blur(8px);
        }

        .badge-in-stock {
            background: rgba(16, 185, 129, 0.9);
            color: #ffffff;
        }

        .badge-out-of-stock {
            background: rgba(239, 68, 68, 0.9);
            color: #ffffff;
        }

        .product-content {
            padding: 10px;
            display: flex;
            flex-direction: column;
            flex: 1;
            justify-content: space-between;
        }

        .product-sku {
            font-family: monospace;
            font-size: 10px;
            color: var(--hint-color);
            margin-bottom: 2px;
        }

        .product-title {
            font-size: 13px;
            font-weight: 700;
            line-height: 1.3;
            margin-bottom: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            min-height: 34px;
        }

        .product-price {
            font-size: 14px;
            font-weight: 800;
            color: var(--accent);
            margin-bottom: 8px;
        }

        .btn-add-cart {
            width: 100%;
            padding: 8px 10px;
            background: var(--button-color);
            color: var(--button-text-color);
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: opacity 0.2s;
        }

        .btn-add-cart:active {
            opacity: 0.85;
        }

        .btn-notify-restock {
            width: 100%;
            padding: 8px 6px;
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .qty-stepper {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(var(--accent-rgb), 0.1);
            border-radius: 10px;
            padding: 3px;
        }

        .qty-stepper button {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            border: none;
            background: var(--button-color);
            color: var(--button-text-color);
            font-weight: 700;
            cursor: pointer;
        }

        .qty-stepper span {
            font-size: 13px;
            font-weight: 700;
            color: var(--accent);
        }

        /* Sticky Bottom Cart Bar */
        .sticky-cart-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 50;
            background: var(--secondary-bg-color);
            border-top: 1px solid var(--card-border);
            padding: 12px 16px;
            box-shadow: 0 -4px 15px rgba(0,0,0,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            transform: translateY(100%);
            transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .sticky-cart-bar.visible {
            transform: translateY(0);
        }

        .cart-info-total {
            display: flex;
            flex-direction: column;
        }

        .cart-info-qty {
            font-size: 11px;
            color: var(--hint-color);
        }

        .cart-info-amount {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-color);
        }

        .btn-checkout-flow {
            padding: 10px 20px;
            background: var(--button-color);
            color: var(--button-text-color);
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(var(--accent-rgb), 0.3);
        }

        /* Cart Drawer Modal */
        .cart-drawer-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 60;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            backdrop-filter: blur(4px);
        }

        .cart-drawer-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .cart-drawer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            max-height: 85vh;
            background: var(--secondary-bg-color);
            border-radius: 24px 24px 0 0;
            z-index: 70;
            display: flex;
            flex-direction: column;
            transform: translateY(100%);
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            overflow: hidden;
        }

        .cart-drawer.active {
            transform: translateY(0);
        }

        .cart-drawer-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--card-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .cart-drawer-title {
            font-size: 17px;
            font-weight: 800;
        }

        .cart-drawer-body {
            padding: 16px 20px;
            overflow-y: auto;
            flex: 1;
        }

        .cart-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--card-border);
        }

        .cart-item-img {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            object-fit: cover;
            background: var(--bg-color);
        }

        .cart-item-details {
            flex: 1;
        }

        .cart-item-name {
            font-size: 13px;
            font-weight: 700;
        }

        .cart-item-price {
            font-size: 12px;
            color: var(--accent);
            font-weight: 700;
        }

        .checkout-form {
            margin-top: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--hint-color);
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            background: var(--bg-color);
            color: var(--text-color);
            font-size: 13px;
            outline: none;
        }

        .cart-drawer-footer {
            padding: 16px 20px;
            border-top: 1px solid var(--card-border);
            background: var(--secondary-bg-color);
        }

        .btn-order-submit {
            width: 100%;
            padding: 12px;
            border-radius: 12px;
            background: var(--button-color);
            color: var(--button-text-color);
            border: none;
            font-size: 15px;
            font-weight: 800;
            cursor: pointer;
        }

        /* Toast Popup */
        .toast {
            position: fixed;
            top: 60px;
            left: 50%;
            transform: translateX(-50%) translateY(-20px);
            background: #1e293b;
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            z-index: 100;
            opacity: 0;
            pointer-events: none;
            transition: all 0.25s ease;
            box-shadow: 0 8px 20px rgba(0,0,0,0.25);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header>
        <div class="header-title-box">
            <div class="header-avatar">
                @if(!empty($storeLogo))
                    <img src="{{ $storeLogo }}" alt="Logo">
                @else
                    🛍️
                @endif
            </div>
            <div>
                <div class="header-title">{{ $storeName ?? 'Retail Online' }}</div>
                <div class="header-subtitle" id="userGreeting">မင်္ဂလာပါ! ဆိုင်မှ ကြိုဆိုပါသည်</div>
            </div>
        </div>
        <button class="header-cart-btn" onclick="toggleCartDrawer()">
            🛒
            <span class="header-cart-badge" id="headerCartCount" style="display: none;">0</span>
        </button>
    </header>

    <!-- Search Bar -->
    <div class="search-container">
        <div class="search-box">
            <span>🔍</span>
            <input type="text" id="searchInput" placeholder="ပစ္စည်းအမည် သို့မဟုတ် SKU ဖြင့် ရှာဖွေပါ..." oninput="filterProducts()">
        </div>
    </div>

    <!-- Category Filter Chips -->
    <div class="category-scroll" id="categoryScroll">
        <button class="category-chip active" onclick="selectCategory('all', this)">အားလုံး (All)</button>
    </div>

    <!-- Products Grid -->
    <div class="products-grid" id="productsGrid">
        <div style="grid-column: span 2; text-align: center; padding: 40px; color: var(--hint-color);">
            ⏳ ကုန်ပစ္စည်းများ စစ်ဆေးနေပါသည်...
        </div>
    </div>

    <!-- Sticky Bottom Cart Bar -->
    <div class="sticky-cart-bar" id="stickyCartBar">
        <div class="cart-info-total">
            <span class="cart-info-qty" id="bottomCartQty">0 မျိုး ရွေးချယ်ထားသည်</span>
            <span class="cart-info-amount" id="bottomCartTotal">0 MMK</span>
        </div>
        <button class="btn-checkout-flow" onclick="toggleCartDrawer()">
            ခြင်းတောင်းကြည့်ရန် ➔
        </button>
    </div>

    <!-- Cart Drawer Modal -->
    <div class="cart-drawer-overlay" id="cartOverlay" onclick="toggleCartDrawer()"></div>
    <div class="cart-drawer" id="cartDrawer">
        <div class="cart-drawer-header">
            <div class="cart-drawer-title">🛒 ကျွန်ုပ်၏ ခြင်းတောင်း</div>
            <button style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--hint-color);" onclick="toggleCartDrawer()">✕</button>
        </div>
        <div class="cart-drawer-body">
            <div id="cartItemsList"></div>

            <div class="checkout-form" id="checkoutFormSection" style="display: none;">
                <h4 style="font-size: 13px; font-weight: 800; margin-top: 10px; border-bottom: 1px solid var(--card-border); padding-bottom: 6px;">
                    📝 ပို့ဆောင်ရမည့် အချက်အလက်များ
                </h4>
                <div class="form-group">
                    <label>လူကြီးမင်းအမည် *</label>
                    <input type="text" id="custName" class="form-control" placeholder="ဦး / ဒေါ်...">
                </div>
                <div class="form-group">
                    <label>ဆက်သွယ်ရန်ဖုန်း *</label>
                    <input type="tel" id="custPhone" class="form-control" placeholder="09xxxxxxxxx">
                </div>
                <div class="form-group">
                    <label>ပို့ဆောင်ရမည့်လိပ်စာ *</label>
                    <input type="text" id="custAddress" class="form-control" placeholder="အိမ်အမှတ်၊ လမ်း၊ မြို့နယ်...">
                </div>
                <div class="form-group">
                    <label>ငွေပေးချေမှုပုံစံ</label>
                    <select id="custPayment" class="form-control">
                        <option value="Cash on Delivery">Cash on Delivery (ပစ္စည်းရောက်ငွေချေ)</option>
                        <option value="KBZPay">KBZPay</option>
                        <option value="WavePay">WavePay</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>မှတ်ချက် (Notes)</label>
                    <input type="text" id="custNotes" class="form-control" placeholder="ထပ်ဆောင်းမှာကြားလိုသည်များ...">
                </div>
            </div>
        </div>
        <div class="cart-drawer-footer">
            <button class="btn-order-submit" id="btnSubmitOrder" onclick="submitMiniAppOrder()">
                အော်ဒါ အတည်ပြုမည်
            </button>
        </div>
    </div>

    <!-- Toast Notification -->
    <div class="toast" id="toastBox">
        <span id="toastIcon">🔔</span>
        <span id="toastText">သတင်းစကား</span>
    </div>

    <script>
        // Initialize Telegram Web App SDK
        const tg = window.Telegram?.WebApp;
        if (tg) {
            tg.ready();
            tg.expand();
            if (tg.initDataUnsafe?.user) {
                const user = tg.initDataUnsafe.user;
                const name = user.first_name + (user.last_name ? ' ' + user.last_name : '');
                document.getElementById('userGreeting').innerText = 'မင်္ဂလာပါ ' + name + ' ရှင်!';
                document.getElementById('custName').value = name;
            }
        }

        let allProducts = [];
        let categories = [];
        let selectedCategory = 'all';
        let cart = {}; // { productId: { product, qty } }

        function showToast(text, icon = '🔔') {
            const toast = document.getElementById('toastBox');
            document.getElementById('toastIcon').innerText = icon;
            document.getElementById('toastText').innerText = text;
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 2800);
            if (tg?.HapticFeedback) {
                tg.HapticFeedback.notificationOccurred('success');
            }
        }

        async function fetchProducts() {
            try {
                const res = await fetch('/api/bot/products');
                const json = await res.json();
                if (json.status === 'success') {
                    allProducts = json.data || [];
                    extractCategories();
                    renderCategoryChips();
                    renderProducts();
                }
            } catch (e) {
                document.getElementById('productsGrid').innerHTML = '<div style="grid-column: span 2; text-align: center; color: red;">ပစ္စည်းများ ရယူ၍ မရသေးပါ</div>';
            }
        }

        function extractCategories() {
            const set = new Set();
            allProducts.forEach(p => {
                if (p.category_name) set.add(p.category_name);
            });
            categories = Array.from(set);
        }

        function renderCategoryChips() {
            const container = document.getElementById('categoryScroll');
            let html = '<button class="category-chip active" onclick="selectCategory(\'all\', this)">အားလုံး (All)</button>';
            categories.forEach(c => {
                html += `<button class="category-chip" onclick="selectCategory('${c}', this)">${c}</button>`;
            });
            container.innerHTML = html;
        }

        function selectCategory(cat, btn) {
            selectedCategory = cat;
            document.querySelectorAll('.category-chip').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            renderProducts();
            if (tg?.HapticFeedback) tg.HapticFeedback.selectionChanged();
        }

        function filterProducts() {
            renderProducts();
        }

        function renderProducts() {
            const query = (document.getElementById('searchInput').value || '').toLowerCase().trim();
            const grid = document.getElementById('productsGrid');

            const filtered = allProducts.filter(p => {
                const matchCat = (selectedCategory === 'all' || p.category_name === selectedCategory);
                const matchQuery = !query || 
                    (p.name && p.name.toLowerCase().includes(query)) ||
                    (p.code && p.code.toLowerCase().includes(query));
                return matchCat && matchQuery;
            });

            if (filtered.length === 0) {
                grid.innerHTML = '<div style="grid-column: span 2; text-align: center; padding: 40px; color: var(--hint-color);">ရှာဖွေမှုနှင့် ကိုက်ညီသော ပစ္စည်းမရှိပါ</div>';
                return;
            }

            let html = '';
            filtered.forEach(p => {
                const inCartQty = cart[p.id]?.qty || 0;
                const isOutOfStock = !p.in_stock || p.available_stock <= 0;
                const img = p.image_url || 'https://placehold.co/300x300/e2e8f0/475569?text=Product';

                html += `
                <div class="product-card">
                    <div class="product-image-wrap">
                        <img src="${img}" alt="${p.name}" class="product-image" loading="lazy">
                        <span class="product-stock-badge ${isOutOfStock ? 'badge-out-of-stock' : 'badge-in-stock'}">
                            ${isOutOfStock ? '🔴 စတော့ပြတ်' : '🟢 စတော့ရှိ: ' + p.available_stock}
                        </span>
                    </div>
                    <div class="product-content">
                        <div>
                            <div class="product-sku">SKU: ${p.code || 'PROD-' + p.id}</div>
                            <div class="product-title">${p.name}</div>
                        </div>
                        <div>
                            <div class="product-price">${Number(p.price).toLocaleString()} MMK</div>
                            ${isOutOfStock ? `
                                <button class="btn-notify-restock" onclick="subscribeRestock(${p.id}, '${p.name.replace(/'/g, "\\'")}')">
                                    🔔 ရောက်လျှင်သတိပေးပါ
                                </button>
                            ` : inCartQty > 0 ? `
                                <div class="qty-stepper">
                                    <button onclick="updateCart(${p.id}, -1)">-</button>
                                    <span>${inCartQty}</span>
                                    <button onclick="updateCart(${p.id}, 1)">+</button>
                                </div>
                            ` : `
                                <button class="btn-add-cart" onclick="updateCart(${p.id}, 1)">
                                    + ဝယ်မည်
                                </button>
                            `}
                        </div>
                    </div>
                </div>
                `;
            });

            grid.innerHTML = html;
        }

        function updateCart(productId, delta) {
            const p = allProducts.find(item => item.id === productId);
            if (!p) return;

            if (!cart[productId]) {
                cart[productId] = { product: p, qty: 0 };
            }

            cart[productId].qty += delta;

            if (cart[productId].qty <= 0) {
                delete cart[productId];
            } else if (p.available_stock && cart[productId].qty > p.available_stock) {
                cart[productId].qty = p.available_stock;
                showToast('လက်ကျန် ' + p.available_stock + ' ခုသာ ရှိပါသည်', '⚠️');
            }

            if (tg?.HapticFeedback) tg.HapticFeedback.impactOccurred('light');

            renderProducts();
            updateCartSummary();
            renderCartDrawerItems();
        }

        function updateCartSummary() {
            let totalQty = 0;
            let totalAmount = 0;

            Object.values(cart).forEach(item => {
                totalQty += item.qty;
                totalAmount += (item.qty * item.product.price);
            });

            const headerBadge = document.getElementById('headerCartCount');
            const stickyBar = document.getElementById('stickyCartBar');
            const bottomQty = document.getElementById('bottomCartQty');
            const bottomTotal = document.getElementById('bottomCartTotal');

            if (totalQty > 0) {
                headerBadge.innerText = totalQty;
                headerBadge.style.display = 'flex';
                bottomQty.innerText = totalQty + ' ခု ရွေးချယ်ထားသည်';
                bottomTotal.innerText = totalAmount.toLocaleString() + ' MMK';
                stickyBar.classList.add('visible');

                if (tg?.MainButton) {
                    tg.MainButton.setText(`ခြင်းတောင်းကြည့်မည် (${totalQty}) • ${totalAmount.toLocaleString()} MMK`);
                    tg.MainButton.show();
                    tg.MainButton.onClick(toggleCartDrawer);
                }
            } else {
                headerBadge.style.display = 'none';
                stickyBar.classList.remove('visible');
                if (tg?.MainButton) {
                    tg.MainButton.hide();
                }
            }
        }

        function toggleCartDrawer() {
            const overlay = document.getElementById('cartOverlay');
            const drawer = document.getElementById('cartDrawer');
            const isActive = drawer.classList.contains('active');

            if (isActive) {
                overlay.classList.remove('active');
                drawer.classList.remove('active');
            } else {
                renderCartDrawerItems();
                overlay.classList.add('active');
                drawer.classList.add('active');
            }
            if (tg?.HapticFeedback) tg.HapticFeedback.impactOccurred('medium');
        }

        function renderCartDrawerItems() {
            const container = document.getElementById('cartItemsList');
            const formSection = document.getElementById('checkoutFormSection');
            const submitBtn = document.getElementById('btnSubmitOrder');

            const items = Object.values(cart);
            if (items.length === 0) {
                container.innerHTML = '<div style="text-align: center; padding: 30px; color: var(--hint-color);">ခြင်းတောင်းထဲတွင် ပစ္စည်းမရှိသေးပါ</div>';
                formSection.style.display = 'none';
                submitBtn.disabled = true;
                return;
            }

            formSection.style.display = 'flex';
            submitBtn.disabled = false;

            let html = '';
            let totalAmount = 0;
            items.forEach(item => {
                const p = item.product;
                const subtotal = item.qty * p.price;
                totalAmount += subtotal;
                const img = p.image_url || 'https://placehold.co/100x100/e2e8f0/475569?text=Item';

                html += `
                <div class="cart-item">
                    <img src="${img}" class="cart-item-img">
                    <div class="cart-item-details">
                        <div class="cart-item-name">${p.name}</div>
                        <div class="cart-item-price">${Number(p.price).toLocaleString()} MMK × ${item.qty}</div>
                    </div>
                    <div class="qty-stepper" style="width: 80px;">
                        <button onclick="updateCart(${p.id}, -1)">-</button>
                        <span>${item.qty}</span>
                        <button onclick="updateCart(${p.id}, 1)">+</button>
                    </div>
                </div>
                `;
            });

            html += `
            <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 15px; padding-top: 10px;">
                <span>စုစုပေါင်း ကျသင့်ငွေ:</span>
                <span style="color: var(--accent);">${totalAmount.toLocaleString()} MMK</span>
            </div>
            `;

            container.innerHTML = html;
        }

        async function subscribeRestock(productId, productName) {
            const chatId = tg?.initDataUnsafe?.user?.id;
            const name = tg?.initDataUnsafe?.user?.first_name || 'Customer';
            const username = tg?.initDataUnsafe?.user?.username ? '@' + tg.initDataUnsafe.user.username : '';

            try {
                const res = await fetch('/api/bot/restock-subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        product_id: productId,
                        telegram_chat_id: chatId ? String(chatId) : 'mini_app_user',
                        customer_name: name,
                        username: username
                    })
                });
                const json = await res.json();
                showToast(json.message || 'ပစ္စည်းရောက်လျှင် အလိုအလျောက် သတိပေးပါမည်!', '🔔');
            } catch (e) {
                showToast('သတိပေးချက် မှတ်ပုံတင်ပြီးပါပြီ!', '🔔');
            }
        }

        async function submitMiniAppOrder() {
            const name = document.getElementById('custName').value.trim();
            const phone = document.getElementById('custPhone').value.trim();
            const address = document.getElementById('custAddress').value.trim();
            const paymentType = document.getElementById('custPayment').value;
            const notes = document.getElementById('custNotes').value.trim();

            if (!name || !phone || !address) {
                showToast('အမည်၊ ဖုန်း နှင့် လိပ်စာ ဖြည့်စွက်ပေးပါ', '⚠️');
                return;
            }

            const items = Object.values(cart).map(i => ({
                product_id: i.product.id,
                variant_id: null,
                quantity: i.qty,
                price: i.product.price
            }));

            const payload = {
                customer_name: name,
                customer_phone: phone,
                customer_address: address,
                payment_type: paymentType,
                notes: notes + ' (via Telegram Mini App)',
                telegram_chat_id: tg?.initDataUnsafe?.user?.id ? String(tg.initDataUnsafe.user.id) : null,
                telegram_username: tg?.initDataUnsafe?.user?.username ? '@' + tg.initDataUnsafe.user.username : null,
                items: items
            };

            const submitBtn = document.getElementById('btnSubmitOrder');
            submitBtn.innerText = '⏳ အော်ဒါတင်နေပါသည်...';
            submitBtn.disabled = true;

            try {
                const res = await fetch('/api/bot/orders', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const json = await res.json();

                if (json.status === 'success') {
                    showToast('အော်ဒါအောင်မြင်စွာ တင်ပြီးပါပြီ!', '🎉');
                    cart = {};
                    updateCartSummary();
                    toggleCartDrawer();

                    if (tg?.sendData) {
                        tg.sendData(JSON.stringify({ action: 'order_submitted', order_id: json.data?.id }));
                    }

                    setTimeout(() => {
                        if (tg?.close) {
                            tg.close();
                        } else {
                            alert('အော်ဒါ အမှတ် #' + (json.data?.reference_no || 'ORD') + ' အား လက်ခံရရှိပါပြီရှင်!');
                        }
                    }, 1200);
                } else {
                    showToast(json.message || 'အော်ဒါတင်ရာတွင် အမှားဖြစ်ပွားပါသည်', '❌');
                    submitBtn.innerText = 'အော်ဒါ အတည်ပြုမည်';
                    submitBtn.disabled = false;
                }
            } catch (e) {
                showToast('ချိတ်ဆက်မှု မအောင်မြင်ပါ', '❌');
                submitBtn.innerText = 'အော်ဒါ အတည်ပြုမည်';
                submitBtn.disabled = false;
            }
        }

        // Initial Load
        fetchProducts();
    </script>
</body>
</html>
