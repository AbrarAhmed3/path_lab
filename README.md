# Diagnoxis LIMS (Path Lab Management System)

**A comprehensive Laboratory Information Management System (LIMS) tailored for diagnostic centers**

---

## 📖 Overview

Diagnoxis is a full-featured, PHP/MySQL-based LIMS designed to streamline laboratory workflows, from patient registration and test assignment through billing, doctor commissions, report generation, and inventory management. It’s built for small-to-medium pathology labs seeking professional, efficient, and secure operations.

---

## 🚀 Key Features

* **User Roles & Authentication**: Admin, lab technician, and doctor access controls.
* **Patient Management**: Register, view, edit, and search patient records.
* **Test Assignment**: Support for individual tests and category/profile bundles. Bundled logic applies when ≥ 80% of category tests are selected.
* **Billing Module**: Draft/open/finalized statuses, discounts, balance calculations, visit notes, and visit-based referred doctor logic.
* **Doctor Commissions**: Automatic commission calculations stored in `doctor_commissions`; cashbook view to track paid/unpaid outflows and income charts.
* **Report Generation**:

  * PDF-ready reports via mPDF.
  * Dynamic reference ranges (gender, age, gestation, labels, components).
  * Department-wise machine info.
  * Lab and treating doctor selection with signature integration.
  * QR code, barcode, and watermark branding.
* **Saved Reports**: Finalized reports persist metadata (`report_metadata`) and machine details (`report_machine_info`); retrieve anytime.
* **Inventory Management**: Track reagents and consumables with expiry dates.
* **Activity Logs**: Record user actions for auditing.
* **Settings & Configuration**: Manage lab details, test definitions, reference ranges, and categories.

---

## 🖥️ Technology Stack & Libraries

* **Backend**: PHP ≥ 8.0, MySQL/MariaDB
* **Frontend**: HTML5, CSS3 (Bootstrap), JavaScript (jQuery, SweetAlert, DataTables, Chart.js)
* **PDF Generation**: mPDF (via Composer)
* **Barcode/QR**: `picqer/php-barcode-generator` or equivalent
* **Server**: Apache/Nginx

---

## ⚙️ Prerequisites & Installation

1. **Clone the Repository**

   ```bash
   git clone https://github.com/your-org/diagnoxis-lims.git
   cd diagnoxis-lims
   ```

2. **Environment Setup**

   * PHP extensions: `mysqli`, `mbstring`, `gd`, `curl`, `intl`
   * Composer: install dependencies
   * MySQL/MariaDB database

3. **Configuration**

   * Copy `config.sample.php` → `config.php` and update credentials:

     ```php
     define('DB_HOST', 'localhost');
     define('DB_USER', 'root');
     define('DB_PASS', 'password');
     define('DB_NAME', 'lab_db');
     define('BASE_URL', 'http://localhost/diagnoxis-lims');
     ```
   * Import database schema and seed data:

     ```bash
     mysql -u root -p lab_db < lab_db.sql
     ```

4. **Dependencies**

   ```bash
   composer install
   ```

5. **File Permissions**

   ```bash
   chmod -R 755 uploads/ vendor/
   ```

6. **Uploads Directory**

   * Place lab logo in `uploads/`
   * Upload doctor signature PNGs for lab doctors

---

## 📂 Directory Structure

```
/            Root
│
├── assets/          # CSS, JS, images
│   ├── css/report.css
│   └── js/
│
├── uploads/         # Signatures, logos, barcodes
│
├── vendor/          # Composer dependencies
│
├── db.php           # Database connection
├── config.php       # App configuration
├── add_patient.php  # Patient registration
├── assign_test.php  # Test assignment interface
├── billing.php      # Billing workflow
├── cashbook.php     # Cashbook view & charts
├── select_report_details.php # Pre-report doctor/machine selection
├── generate_report.php        # PDF report generation
├── saved_report.php  # Retrieve finalized reports
├── inventory.php     # Inventory management
└── index.php         # Dashboard
```

---

## ⚡ Usage Guide

1. **Login**: Visit `/admin_login.php` and authenticate.
2. **Register Patient**: `/add_patient.php`. Fill in demographics, pregnancy details (if any).
3. **Assign Tests**: `/assign_test.php`. Select category/profile or individual tests.
4. **Process Billing**: `/billing.php`. Apply discounts, select referred doctor per visit, finalize payment.
5. **Finalize Report**: `/select_report_details.php` → choose doctors & machines → `/generate_report.php`.
6. **Access Reports**: `/saved_report.php` for past generated reports.
7. **Manage Inventory**: `/inventory.php`. Add items, monitor expiry.
8. **View Cashbook**: `/cashbook.php`. Track billing income and commission payouts.
9. **Settings**: Use admin interface to update lab details, test ranges, categories.

---

## 🛠️ Customization & Development

* **CSS**: `report.css` controls PDF layout, page breaks, watermark, and responsive print styles.
* **JavaScript**: `assets/js` contains DataTables init, Chart.js config, and SweetAlert prompts.
* **PDF Templates**: Modify header/footer and table styles within `generate_report.php`.

---

## 🤝 Contributing

1. Fork the repo.
2. Create a feature branch (`git checkout -b feature/xyz`).
3. Commit changes (`git commit -m "Add XYZ feature"`).
4. Push (`git push origin feature/xyz`).
5. Open a Pull Request.

Please adhere to PSR-12 coding standards and include relevant tests and documentation updates.

---

## 📄 License

Distributed under the **MIT License**. See [LICENSE](LICENSE) for more information.

---

## 📬 Contact

**Abranex Software Solutions**
Email: [freelancerabrar@gmail.com](mailto:freelancerabrar@gmail.com)

Visit our website for more information and support.
