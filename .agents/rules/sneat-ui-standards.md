# Sneat UI & Web Player V2 Design Standards

This rule governs all front-end and view development for the admin panel and **Web Player V2** in XC_VM.

---

## 🎨 Core Architectural Directives

1. **Exclusive Reference to Sneat Full-Version**:
   - The authoritative reference for all UI components, layouts, cards, navigation, forms, and tables is `sneat-bootstrap-html-admin-template-v3.0.0/full-version/`.
   - Never introduce ad-hoc CSS frameworks, custom non-Sneat stylesheets, or inline `<style>` blocks inside `.php` view files.

2. **Strict Prohibition on Inline Styles & Custom Page CSS**:
   - **NEVER** write inline `style="..."` attributes on elements (except for dynamic image URLs, e.g. `background-image: url(...)`).
   - **NEVER** write `<style> ... </style>` tags inside view templates (`login.php`, `index.php`, `live.php`, etc.).
   - All styling must be achieved using Sneat's compiled CSS classes from `assets/vendor/css/core.css` and `assets/css/demo.css`.

3. **Shell & Layout Architecture**:
   - All authenticated pages must utilize the standard Sneat vertical menu layout:
     ```html
     <div class="layout-wrapper layout-content-navbar">
       <div class="layout-container">
         <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">...</aside>
         <div class="layout-page">
           <nav class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme" id="layout-navbar">...</nav>
           <div class="content-wrapper">
             <div class="container-xxl flex-grow-1 container-p-y">
               <!-- Page Content -->
             </div>
             <footer class="content-footer footer bg-footer-theme">...</footer>
             <div class="content-backdrop fade"></div>
           </div>
         </div>
       </div>
       <div class="layout-overlay layout-menu-toggle"></div>
       <div class="drag-target"></div>
     </div>
     ```

4. **Component Patterns**:
   - **Cards**: Use `<div class="card">` with `<div class="card-header">`, `<h5 class="card-title">`, and `<div class="card-body">`.
   - **Badges**: Use Sneat label badges (`bg-label-primary`, `bg-label-success`, `bg-label-warning`, `bg-label-danger`, `bg-label-info`).
   - **Icons**: Use Boxicons via `icon-base bx bx-*` (e.g. `bx bx-home-smile`, `bx bx-tv`, `bx bx-film`, `bx bx-movie-play`).
   - **Nav Pills / Tabs**: Use `<ul class="nav nav-pills" role="tablist">` with `<button class="nav-link">`.
   - **Authentication**: Use the Sneat standard `authentication-wrapper authentication-basic container-p-y` with `card px-sm-6 px-0`.
