<?php
$page_title = $page_title ?? 'Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - <?= htmlspecialchars(setting('site_name','Gracehubtels')) ?></title>
    <link rel="icon" type="image/png" href="/assets/images/favicon.png">
    
    <!-- SweetAlert2 — used by Swal.fire() across ~20 user-portal pages
         (betting, airtime, cabletv, electricity, recharge/data card, fund,
         transfers, etc.) for confirmation dialogs and post-purchase
         redirects, but was never actually included anywhere in this
         codebase. Every one of those Swal.fire() calls threw a silent
         ReferenceError, which — for pages that gate their receipt redirect
         inside .then() on the dialog closing — meant the money-moving
         request completed successfully server-side but the page just sat
         there with no visible feedback and no redirect. -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'inter': ['Inter', 'sans-serif'],
                        'outfit': ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        brand: {
                            navy: '#002060',
                            navyLight: '#0d2e6c',
                            navyDark: '#001238',
                            orange: '#ff5500',
                            orangeHover: '#e04b00',
                            orangeLight: '#fff0eb'
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- Google Fonts & Font Awesome -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; transition: background-color 0.3s, color 0.3s; }
        h1, h2, h3, h4, h5, h6 { font-family: 'Outfit', sans-serif; }
        
        /* Dark Mode Overrides */
        html.dark-mode .bg-white { background-color: #1e293b !important; border-color: #334155 !important; }
        html.dark-mode .bg-slate-50 { background-color: #0f172a !important; border-color: #334155 !important; }
        html.dark-mode body { background-color: #0f172a !important; color: #f1f5f9 !important; }
        html.dark-mode .text-slate-800, html.dark-mode .text-gray-900, html.dark-mode .text-slate-700 { color: #f8fafc !important; }
        html.dark-mode .text-slate-600, html.dark-mode .text-slate-500, html.dark-mode .text-gray-600 { color: #cbd5e1 !important; }
        html.dark-mode .border-slate-200, html.dark-mode .border-slate-100 { border-color: #334155 !important; }
        html.dark-mode .card-shadow { box-shadow: 0 4px 6px -1px rgba(0,0,0, 0.4) !important; }
        html.dark-mode aside.bg-white { background-color: #1e293b !important; }
        html.dark-mode header.bg-white { background-color: #1e293b !important; }
        
        .card-shadow { box-shadow: 0 4px 6px -1px rgba(0, 32, 96, 0.1), 0 2px 4px -1px rgba(0, 32, 96, 0.06); }
        .sidebar-active { background-color: rgba(255, 85, 0, 0.1); color: #ff5500; border-right: 3px solid #ff5500; }
        
        #mobile-menu { transition: transform 0.3s ease-in-out; }
        .menu-open { transform: translateX(0%); }
        .menu-closed { transform: translateX(-100%); }
    </style>
    <script>
        // Apply theme immediately to prevent FOUC
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark-mode');
        }
        
        function toggleTheme() {
            const html = document.documentElement;
            const icon = document.getElementById('themeIcon');
            
            if (html.classList.contains('dark-mode')) {
                html.classList.remove('dark-mode');
                localStorage.setItem('theme', 'light');
                if(icon) { icon.classList.remove('fa-sun'); icon.classList.add('fa-moon'); }
            } else {
                html.classList.add('dark-mode');
                localStorage.setItem('theme', 'dark');
                if(icon) { icon.classList.remove('fa-moon'); icon.classList.add('fa-sun'); }
            }
        }
        
        // Sync icon on load
        window.addEventListener('DOMContentLoaded', () => {
            const icon = document.getElementById('themeIcon');
            if(icon && document.documentElement.classList.contains('dark-mode')) {
                icon.classList.remove('fa-moon');
                icon.classList.add('fa-sun');
            }
        });
    </script>
</head>
<body class="bg-slate-50 text-slate-800">
    <div class="flex h-screen overflow-hidden">
