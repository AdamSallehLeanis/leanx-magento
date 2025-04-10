# 💳 LeanX Payments for Magento 2

A custom Magento 2 payment gateway integration for LeanX.

---

## Installation Instructions

Follow these steps to install the **LeanX Payments Gateway** for Magento 2 via Composer.

---

### ⚙️ Requirements

- Magento 2.4.7 or later  
- PHP 8.3+  
- PHP extensions:
  - `bcmath`
  - `soap`
- Composer 2+

---

### 1. Add the GitHub Repository

In the root directory of your Magento 2 project, add this repository as a VCS source:
```bash
composer config repositories.leanx-magento vcs https://github.com/AdamSallehLeanis/leanx-magento
```
---

### 2. Install the Package

Install the module using Composer:
```bash
composer require leanx/payments:@dev
```
> 💡 Once stable releases are tagged, you can replace `@dev` with a specific version like `^1.0`.

---

### 3. Enable and Compile the Module

Run the following Magento CLI commands:
```bash
php bin/magento module:enable LeanX_Payments
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

---

### 4. Verify Installation (Optional)

To confirm the module is active:
```bash
php bin/magento module:status | grep LeanX
```

Expected output:
```bash
LeanX_Payments: enabled
```

---

### ⚙️ 5. Configure the Gateway in Magento Admin

After installing, follow these steps to enable the module and configure it:

1. Log in to your **Magento Admin Panel**.
2. Go to **`Stores` > `Configuration` > `Sales` > `Payment Methods`**.
3. Scroll down to the **LeanX Payment Gateway** section.
4. Make sure the gateway is **Enabled** from the dropdown menu.
5. Enter the required credentials:
   - **Auth Token**
   - **Hash Key**
   - **Collection UUID**
   - **Bill Invoice ID Prefix**
6. Click **Save Config** in the top-right corner.

---

## 🙋 Support

If you encounter issues or have questions, feel free to [open an issue](https://github.com/AdamSallehLeanis/leanx-magento/issues) on GitHub.
