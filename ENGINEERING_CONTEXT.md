# Engineering Context: Gestor de Facturas hpos

## ℹ️ Project Overview
Plugin para WordPress que permite subir y enviar facturas PDF desde el área de administración de pedidos de WooCommerce, compatible con HPOS (High-Performance Order Storage).

## 🛠 Tech Stack
- **Languages:** PHP 7.4+, JavaScript (Vanilla/jQuery for AJAX)
- **Dependencies:** WordPress 5.6+, WooCommerce 7.0+
- **Infrastructure:** Docker (for local development, currently inactive/idle)

## 🏗 Architecture (v2.6.0)
El proyecto ha sido reestructurado para separar responsabilidades en las siguientes clases:
- `GFWC_Admin_UI`: Maneja la interfaz de administración, metaboxes y scripts.
- `GFWC_Ajax_Handler`: Gestiona las peticiones AJAX (Subida y Notificaciones).
- `GFWC_Mailer`: Encargado de la lógica de envío de correos (Facturas y Shipping).
- `gestor-facturas-hpos.php`: Punto de entrada principal y carga de clases.

## 📋 Pending Tasks / Backlog
- [x] **Validation:** Verificar la integración de las 3 nuevas clases tras la refactorización. (Corregido bug de HPOS Scripts).
- [x] **i18n:** Internacionalizar todas las cadenas de texto. (Completado).
- [x] **Shipping Notifications:** Sistema de envío de detalles de transporte con plantillas dinámicas. (Completado).
- [ ] **Tests:** Implementar pruebas unitarias o de integración para `GFWC_Mailer`.
- [x] **Optimization:** Revisar `gfwc-script.js` para asegurar compatibilidad con estándares modernos. (Completado).
- [ ] **CI/CD:** Configurar GitHub Actions para linting de PHP (PHPCS).

## 🐛 Known Issues / Technical Debt
- No hay suite de pruebas automatizadas configurada actualmente.
