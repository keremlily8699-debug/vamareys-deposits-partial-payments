# Vamareys - Deposits & Partial Payments for WooCommerce

Customized edition of the **Deposits & Partial Payments for WooCommerce** WordPress plugin for Vamareys.

## 📌 Project Overview
- **Base Plugin:** Deposits & Partial Payments for WooCommerce
- **Original Author:** Acowebs
- **Base Version:** 1.2.13
- **Entry File:** `start.php`
- **Text Domain:** `deposits-partial-payments-for-woocommerce`

---

## 🏗️ Codebase Structure

```text
├── assets/                  # Frontend & Backend CSS, JS, Images, and SASS sources
│   ├── css/
│   ├── js/
│   └── images/
├── includes/                # Core PHP classes and logic
│   ├── blocks/              # WooCommerce Blocks integration (Gutenberg block support)
│   ├── compatibility/       # Third-party plugin & payment gateway compatibility layers
│   ├── emails/              # Custom WooCommerce email classes (e.g. deposit paid)
│   ├── class-awcdp-api.php  # API handling
│   ├── class-awcdp-front-end.php
│   ├── class-awcdp-backend.php
│   ├── class-awcdp-order.php
│   └── helpers.php          # Global helper functions
├── languages/               # Translation and POT/PO/MO files
├── templates/               # Overridable front-end, email, and admin templates
│   ├── admin/
│   ├── emails/
│   └── order/
├── readme.txt               # WordPress.org standard plugin readme
└── start.php                # Main plugin bootstrap and activation hooks
```

---

## 🛠️ Development & Customization Guidelines

1. **Clean Baseline Tracking:**
   - The `main` branch maintains clean baseline imports from official releases.
   - All customizations and feature branches should branch off `main` (e.g. `feature/custom-deposits`, `feature/iran-gateways`).
2. **WordPress Standards:**
   - Follow standard WordPress security guidelines: nonces for CSRF protection, capability checks (`manage_woocommerce`, `edit_shop_orders`), input sanitization (`sanitize_text_field`, `wp_unslash`), and output escaping (`esc_html`, `esc_attr`).
3. **Template Overrides:**
   - Keep template modifications modular in `templates/` to allow seamless WooCommerce integration.
