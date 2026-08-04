import re

with open(r'c:\xampp\htdocs\gracehubtels\msj-admin\css\admin.css', 'r', encoding='utf-8') as f:
    css = f.read()

# Replace :root
root_new = """:root{
  --primary: #FF5A1F;
  --primary-hover: #E04815;
  --primary-light: rgba(255, 90, 31, 0.15);
  --sidebar-dark: #051A52;
  --sidebar-light: #0A2A7A;
  --navy: #1F2937;
  --navy-dark: #F9FAFB;
  --navy-light: #374151;
  --sidebar-w: 260px;
  --sidebar-collapsed-w: 80px;
  --topbar-h: 70px;
  --bg: #0B0F19;
  --surface: #111827;
  --surface-hover: #1F293B;
  --text: #F3F4F6;
  --text-muted: #9CA3AF;
  --border: rgba(255, 255, 255, 0.08);
  --border-light: rgba(255, 255, 255, 0.03);
  --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.5);
  --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.4);
  --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.5);
  --shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.6);
  --radius-sm: 8px;
  --radius: 16px;
  --radius-lg: 24px;
  --success: #10B981;
  --danger: #EF4444;
  --warning: #F59E0B;
  --info: #3B82F6;
}"""

css = re.sub(r':root\s*\{.*?\n\}', root_new, css, flags=re.DOTALL)

# Fix sidebar background
css = css.replace('var(--navy-dark) 0%, var(--navy) 100%', 'var(--sidebar-dark) 0%, var(--sidebar-light) 100%')

# Replace hardcoded #fff and white backgrounds
css = css.replace('background: #fff;', 'background: var(--surface);')
css = css.replace('background:#fff;', 'background: var(--surface);')
css = css.replace('background-color: white;', 'background-color: var(--text);')
css = css.replace('color: #fff;', 'color: var(--text);')

# Re-fix buttons and icons that need #fff
css = css.replace('color: var(--text);\n  display: flex;\n  align-items: center;\n  justify-content: center;\n  font-size: 16px;\n  font-weight: 800;\n  box-shadow:', 'color: #fff;\n  display: flex;\n  align-items: center;\n  justify-content: center;\n  font-size: 16px;\n  font-weight: 800;\n  box-shadow:')
css = css.replace('color: var(--text);\n  padding: 10px 20px;\n  border-radius: 10px;', 'color: #fff;\n  padding: 10px 20px;\n  border-radius: 10px;')

# Fix Topbar Glass
css = css.replace('background: rgba(255, 255, 255, 0.85);', 'background: rgba(17, 24, 39, 0.7);')
css = css.replace('background: rgba(248, 250, 252, 0.5);', 'background: rgba(31, 41, 59, 0.5);')

# Fix Table search
css = css.replace('background: var(--surface); font-size: 13px;', 'background: var(--bg); font-size: 13px; color: var(--text);')

with open(r'c:\xampp\htdocs\gracehubtels\msj-admin\css\admin.css', 'w', encoding='utf-8') as f:
    f.write(css)
