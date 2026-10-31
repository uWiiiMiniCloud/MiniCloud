<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireLogin();

$pdo         = getDB();
$appSettings = getAppSettings($pdo);
$appName     = $appSettings['app_title'] ?: APP_NAME;
$isAdminUser = isAdmin();
$lang        = getCurrentLang();
$dateStr     = date('d.m.Y');

$pageTitle = 'Hilfe & Dokumentation';
require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">
<?php if ($lang === 'en'): ?>
    <!-- Header (EN) -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Help &amp; User Guide – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Overview of all features, keyboard shortcuts, security information, and the data dictionary.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Back to my files
        </a>
    </div>

    <!-- Quick Navigation (EN) -->
    <nav aria-label="Table of Contents" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Jump to:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Upload &amp; Folders</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Preview &amp; Sorting</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Move &amp; Copy</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Share &amp; Permissions</a>
            <a href="#kalender-chat" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📅 Calendar &amp; 💬 Chat</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Vault Mode</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Trash &amp; Settings</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Administration</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ IT Security Concept</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Data Dictionary</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Upload Files &amp; Folders</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direct Folder Drag &amp; Drop Zones:</strong> Every folder row in the list view and every folder card in the grid view is a direct drop target. Drag files or folders from your computer directly onto a folder to upload them straight into that subfolder without opening it first—or drop them anywhere else in the file list to upload into the currently open directory.</li>
                <li><strong>Compact Header Buttons &amp; Office Light:</strong> Right in the header of the file list (saving vertical screen space especially on smartphones), use <em>“+ Files”</em> to upload files, <em>“📁+ Folder”</em> to upload an entire folder hierarchy, or <strong>“📄+ Office”</strong> to create a new Word document (<code>.docx</code>), Excel spreadsheet (<code>.xlsx</code>), CSV table (<code>.csv</code>), or note (<code>.txt</code> / <code>.md</code>) directly in the cloud.</li>
                <li><strong>Move Cloud Files by Drag &amp; Drop:</strong> Drag any file (or multiple checked files) directly onto a subfolder row/tile or onto the <code>&crarr; ..</code> parent folder row to move them there immediately.</li>
                <li><strong>Create Subfolders:</strong> Use the folder bar to create new folders or subfolders inside the currently active directory at any time.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">📄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Preview, Office Light (Word, Excel, PowerPoint, Visio, Mind Maps &amp; Photo Studio) &amp; Mobile Menu</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📄 MiniCloud Office Light (6-in-1 Suite: Word, Excel, PowerPoint, Visio, Mind Maps &amp; 🎨 Photo Studio):</strong> Create or edit <strong>Word documents (<code>.docx</code>)</strong>, <strong>Excel spreadsheets (<code>.xlsx</code> / <code>.csv</code>)</strong> with live formulas (<code>=SUM(A1:A10)</code>, <code>=AVERAGE(...)</code>, <code>=MIN(...)</code>, <code>=MAX(...)</code>, <code>=ROUND(...)</code>, <code>=IF(...)</code>), <strong>PowerPoint presentations (<code>.pptx</code>)</strong>, <strong>Visio diagrams (<code>.drawio</code>)</strong>, and edit any photo in the integrated <strong>🎨 Photo &amp; Image Studio</strong> (crop, rotate, resize/compress to JPEG/WebP/PNG with live size preview, color filters, watermark &amp; GDPR pixelation brush) directly in your browser and save via <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Ctrl+S</kbd>.</li>
                <li><strong>Instant Preview &amp; Browsing:</strong> Click the eye icon (<span class="text-violet-600 dark:text-violet-400 font-semibold">Preview</span>) or the file name to preview images, PDFs, videos, audio, and code/office files; use <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> and <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> to browse (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Esc</kbd> closes).</li>
                <li><strong>Photo Gallery / Grid View, ⭐ Favorites &amp; Global Spotlight Search (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Ctrl+K</kbd>):</strong> Switch anytime between the classic table (<strong>📋 List</strong>) and the visual thumbnail gallery (<strong>🖼️ Grid</strong>), pin important files to the top with a star (<strong>⭐ Favorites</strong>), or press <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Ctrl+K</kbd> (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">⌘K</kbd>) from anywhere in the cloud to search folders, files, whiteboards, notes, tasks, appointments, contacts, and modules in real time.</li>
                <li><strong>Multi-Select Batch Actions (Files &amp; Folders):</strong> Select multiple folders and/or files via checkboxes to move or copy entire folder trees and files in one step, move them to the Trash, or download selected files as a ZIP archive via the floating action bar.</li>
                <li><strong>Customizable Favorites in Header &amp; File Actions (⭐ / ☆):</strong> Both in the top <strong>⊞ Modules</strong> launcher and in the <strong>⋯ file action menu</strong> (also accessible via <strong>right-click</strong> on any file or folder row), click the <strong>⭐ / ☆ star</strong> next to any module or file action to pin it directly to the outside bar or unpin it back into the menu.</li>
                <li><strong>Table Sorting:</strong> Click the column headers <em>Name</em>, <em>Folder</em>, <em>Size</em>, or <em>Type / Date</em> to sort ascending or descending (<code>↑</code> / <code>↓</code>).</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Move or Copy Files &amp; Folders</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Compact ⇄ Icon in Actions Column:</strong> Click the <strong>⇄</strong> icon button in the <em>Actions</em> column of any file or subfolder row to open the move/copy dialog and choose the desired target folder under <strong>⇄ Move</strong> or <strong>📋 Copy</strong>.</li>
                <li><strong>Entire Folders Including Contents:</strong> Both via the <strong>⇄</strong> icon in the top folder bar and in every subfolder row, complete folders including all subfolders and files can be moved or copied.</li>
                <li><strong>Shared Folders:</strong> Move or copy files and folders between your personal folders and shared folders where you have write permissions.</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Sharing &amp; 3-Level Permissions</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Public Share Links &amp; Digital Drop Box (File-Drop):</strong> Click the share icon to generate a cryptographically secure link for files or folders – optionally protected by a <strong>password</strong> and <strong>expiration date</strong>. For folder links, choose between <strong>👁️ Download Only</strong>, <strong>📤 Digital Drop Box / Upload Only</strong> (guests can upload files without seeing other files), or <strong>🔄 Download &amp; Upload</strong>.</li>
                <li><strong>Direct Sharing with Cloud Users (3 Levels):</strong> Share files or folders directly with other users who have enabled their privacy visibility in Settings. For shared folders, choose between <strong>👁️ Read Only</strong>, <strong>✏️ Read &amp; Write</strong> (upload &amp; create subfolders), or <strong>🗑️ Read, Write &amp; Delete</strong>.</li>
                <li><strong>Privacy Protection by Default:</strong> New accounts are hidden from the sharing/chat user list by default and can opt in anytime in Settings.</li>
            </ul>
        </section>

        <section id="kalender-chat" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3 md:col-span-2">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 font-bold">📅</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Calendar &amp; Integrated Messenger (Chat)</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Calendar &amp; Appointments (<a href="calendar.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Calendar</a>):</strong> Manage personal, group, and shared events in month or list view with recurrence rules (daily, weekly, monthly, yearly), colors, file attachments, <code>.ics</code> import/export, and a personal <code>.ics</code> subscription link for smartphones, Thunderbird, or Outlook. Share your calendar with users or groups using 3 permission levels (Read, Edit, Edit &amp; Delete).</li>
                <li><strong>Messenger, File Attachments &amp; Announcements (<a href="messages.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Chat</a>):</strong> Exchange real-time 1:1 messages with visible cloud users, attach files or photos directly via the <strong>📎</strong> button (with inline image preview), or receive official admin broadcasts. Includes one-click chat history clearing, automatic 1-column mobile view, and configurable desktop/mobile system notifications.</li>
            </ul>
        </section>
    </div>

    <!-- Vault Mode (EN) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Vault Mode – Single-File Encryption (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Protect sensitive documents with a password known only to you (Zero-Knowledge principle)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Encrypt via Lock Icon:</strong> After uploading a file, click the <strong>🔒 lock icon</strong> in the <em>Actions</em> column of the file list to encrypt it using <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong> with your personal Vault password.</li>
            <li><strong>Decrypt &amp; Share:</strong> Vault-protected files carry the <strong>🔒 Vault</strong> badge. When downloading (including via public share links or direct shares), the Vault password is requested. You can also permanently remove Vault protection at any time via the lock icon.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Important Notice Regarding Your Vault Password (Zero-Knowledge):</strong><br>
            Your chosen Vault password is <strong>never stored anywhere on the server</strong> – neither in the database nor in any configuration file. Immediately after encryption, the key is wiped from memory. <strong>If you forget the Vault password for a file, nobody – not even the administrator – can recover the file!</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Trash, Themes, Notifications &amp; 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30-Day Trash Retention:</strong> Deleted files go to the <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Trash</a> first, where they can be restored with one click for 30 days or permanently deleted.</li>
                <li><strong>Settings (⚙️ Gear Icon):</strong> In <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Settings</a>, choose between 5 languages, 13 eye-friendly color themes (5 light, 8 dark incl. <em>🌌 Midnight</em>), configure status message display duration (1–300s or manual), session timeout, chat system notifications, privacy visibility, and password.</li>
                <li><strong>2FA via Email or Google Authenticator (TOTP):</strong> Under <em>Security &amp; 2FA</em> in <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Settings</a>, you can choose between <strong>✉️ Email code</strong> (6-digit code via email) or <strong>📱 Authenticator App (Google Authenticator, Microsoft Authenticator, Authy, Bitwarden, Apple Passwords)</strong>. Simply scan the 100% locally generated QR code (or enter the Base32 key) and confirm with the 6-digit app code. If your phone is unavailable at login, you can optionally request an emergency code via email.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Administrator Notes</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 Thematic Admin Tabs:</strong> <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administration</a> is organized into 5 clear tabs: <em>👥 Users &amp; Registration</em>, <em>🧩 Modules &amp; Features</em>, <em>🎨 Branding &amp; Legal Notice</em>, <em>✉️ Email &amp; SMTP</em>, and <em>🖥️ System, Update &amp; Status</em>.</li>
                <li><strong>1-Click GitHub System Updater, Rollback &amp; Server Monitor:</strong> Check for new commits on GitHub and update all application files with one click while preserving <code>uploads/</code> and database credentials. Before every update, a local restore point (snapshot in <code>uploads/.system_backups/</code>) is created automatically so you can undo any update with one click or install any previous version from the GitHub commit history.</li>
                <li><strong>Users, Groups, Quotas &amp; 2FA Reset:</strong> Create accounts, assign individual storage quotas (in MB), manage user groups, reset passwords, or switch/disable a user’s 2FA method (Email / TOTP).</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <!-- IT Security Concept (EN) -->
    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">IT Security Concept</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Technical and organizational security measures (TOM) and audit results</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Status: <?= e($dateStr) ?> &bull; Audited &amp; Hardened
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authentication, 2FA (Email &amp; TOTP) &amp; Brute-Force Protection</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Closed user system without public self-registration. Passwords are hashed exclusively with <strong>BCRYPT (cost factor 12)</strong>. Users can optionally enable Two-Factor Authentication via <strong>Email code</strong> or <strong>Authenticator App (Google Authenticator / RFC 6238 TOTP with local offline QR code generation)</strong>. After <strong>5 failed attempts within 5 minutes</strong>, an automatic temporary lockout is enforced.
                </p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Session &amp; Access Control (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Sessions enforce <code>session.use_strict_mode</code>, <code>HttpOnly</code>, <code>SameSite=Lax</code>, and <code>Secure</code> over HTTPS. Upon login, <code>session_regenerate_id(true)</code> prevents session fixation. Every request verifies user existence and admin status in real time against the database.
                </p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. File Storage Isolation &amp; Vault Encryption</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Uploaded files are stored with cryptographically random filenames and a non-executable <code>.bin</code> extension (permissions <code>0640</code>). The <code>uploads/</code> directory is blocked from direct web access via <code>.htaccess</code>. Sensitive files can additionally be encrypted with <strong>AES-256-GCM (Zero-Knowledge)</strong>.
                </p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Protection Against SQLi, XSS, CSRF &amp; Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    All database queries use native <strong>PDO Prepared Statements</strong>. All outputs are escaped via <code>htmlspecialchars</code> or <code>JSON_HEX_TAG</code>. Every state-changing request requires a <code>POST</code> request with a 256-bit CSRF token (<code>hash_equals</code>). Paths are sanitized against <code>..</code> and SQL wildcards.
                </p>
            </div>
        </div>
    </section>

    <!-- Data Dictionary (EN) -->
    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Data Dictionary &amp; Storage Inventory</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Overview of all processed data entities, database tables, storage locations, and retention periods</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Table / Storage Location</th>
                        <th class="py-3 px-4">Key Fields / Contents</th>
                        <th class="py-3 px-4">Purpose &amp; Personal Data Reference</th>
                        <th class="py-3 px-4">Retention / Deletion</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, session_timeout, flash_duration, chat_notify_mode, allow_user_sharing, calendar_token, storage_quota</code></td>
                        <td class="py-3 px-4">User authentication, optional 2FA (Email or Google Authenticator / TOTP), password reset, role control, quota, UI preferences &amp; privacy settings</td>
                        <td class="py-3 px-4">Until user account deletion by administrator (cascade delete)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>id, user_id, filename, original_name, folder_path, file_size, mime_type, is_encrypted, upload_date, deleted_at</code></td>
                        <td class="py-3 px-4">Metadata of uploaded files &amp; directory hierarchy (incl. empty folders)</td>
                        <td class="py-3 px-4">Active until deleted; automatic permanent deletion after 30 days in Trash (<code>deleted_at</code>)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">shares &amp; user_shares</td>
                        <td class="py-3 px-4"><code>share_token, password_hash, expires_at</code> / <code>owner_id, shared_with_user_id, can_write, can_delete</code></td>
                        <td class="py-3 px-4">Public share links and internal 3-level direct shares between cloud users</td>
                        <td class="py-3 px-4">Until manual revocation, expiration (<code>expires_at</code>), or deletion of file/folder</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">calendar_events &amp; calendar_shares</td>
                        <td class="py-3 px-4"><code>title, start_at, end_at, recurrence, group_id, shared_user_id, attached_file_id</code> / <code>owner_user_id, target_user_id, target_group_id, can_edit, can_delete</code></td>
                        <td class="py-3 px-4">Appointments, recurring events, and 3-level calendar sharing permissions</td>
                        <td class="py-3 px-4">Until event/share deletion or user account deletion</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">messages &amp; message_broadcast_reads</td>
                        <td class="py-3 px-4"><code>sender_id, recipient_id, is_broadcast, message_text, is_read, created_at</code></td>
                        <td class="py-3 px-4">Direct 1:1 chat messages and administrator announcements to all users</td>
                        <td class="py-3 px-4">Until individual deletion, chat history clearing, or user account deletion</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">gallery_albums, gallery_album_items, forms &amp; form_submissions</td>
                        <td class="py-3 px-4"><code>title, event_date, share_token, allow_guest_upload</code> / <code>fields_json, max_submissions, closes_at, answers_json, imported_to_club</code></td>
                        <td class="py-3 px-4">Virtual photo albums &amp; media gallery (<code>gallery.php</code>) as well as self-hosted online forms, event registrations, and club membership applications (<code>forms.php</code>)</td>
                        <td class="py-3 px-4">Until album/form deletion by owner or account deletion</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">Filesystem: uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Physical binary data of uploaded files (pseudonymized filename, optionally AES-256 encrypted)</td>
                        <td class="py-3 px-4">Physical deletion (<code>unlink</code>) when emptying Trash, after 30 days in Trash, or upon account deletion</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($lang === 'fr'): ?>
    <!-- Header (FR) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Aide &amp; Manuel d’utilisation – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Toutes les fonctionnalités, raccourcis clavier, informations de sécurité et dictionnaire des données.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Retour à mes fichiers
        </a>
    </div>

    <nav aria-label="Sommaire" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Accès rapide :</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Envoi &amp; Dossiers</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Aperçu &amp; Tri</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Déplacer &amp; Copier</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Partage &amp; Groupes</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Mode Coffre</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Corbeille &amp; Paramètres</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Administration</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ Concept de sécurité</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Dictionnaire des données</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Téléverser des fichiers &amp; dossiers</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Glisser-déposer instantané :</strong> Faites glisser des fichiers ou des dossiers entiers directement sur la zone en pointillés. L’envoi démarre automatiquement.</li>
                <li><strong>Boutons de sélection &amp; Office Light :</strong> Utilisez <em>« + Fichiers »</em> pour plusieurs fichiers, <em>« Dossier »</em> pour toute une arborescence, ou <strong>« 📄+ / + Office Light »</strong> pour créer directement un document Word (<code>.docx</code>), un tableur Excel (<code>.xlsx</code>), un fichier CSV (<code>.csv</code>) ou une note (<code>.txt</code> / <code>.md</code>).</li>
                <li><strong>Dossier cible :</strong> Le champ <em>« Dossier cible (optionnel) »</em> correspond par défaut au dossier actif, mais vous pouvez saisir un nouveau chemin (ex. <code>Projets/2026</code>).</li>
                <li><strong>Sous-dossiers :</strong> Créez de nouveaux sous-dossiers à tout moment via la barre de dossier active.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">📄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Aperçu, Office Light (Word &amp; Excel) &amp; Menu mobile</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📄 MiniCloud Office Light (Word, Excel &amp; Notes en ligne) :</strong> Créez et modifiez des documents <strong>Word (<code>.docx</code>)</strong> avec barre de mise en forme ou des feuilles de calcul <strong>Excel (<code>.xlsx</code> / <code>.csv</code>)</strong> multi-onglets avec formules (<code>=SUMME(A1:A10)</code>, <code>=MITTELWERT(...)</code>, <code>=MIN(...)</code>, <code>=MAX(...)</code>, <code>=A1*B1</code>) directement dans le navigateur (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Ctrl+S</kbd>).</li>
                <li><strong>Aperçu direct &amp; Navigation :</strong> Cliquez sur l’icône d’œil (<span class="text-violet-600 dark:text-violet-400 font-semibold">Aperçu</span>) ou le nom du fichier pour prévisualiser images, PDF, vidéos, audio et textes ; parcourez avec <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> et <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd>.</li>
                <li><strong>Icônes Bureau &amp; Menu d’actions mobile ⋯ :</strong> Sur ordinateur, toutes les icônes d’action sont affichées côte à côte. Sur smartphone, un bouton compact <strong>⋯</strong> regroupe toutes les actions dans un menu tactile clair.</li>
                <li><strong>Tri du tableau :</strong> Cliquez sur les en-têtes <em>Nom</em>, <em>Dossier</em>, <em>Taille</em> ou <em>Type / Date</em> pour trier par ordre croissant ou décroissant.</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Déplacer ou copier des fichiers &amp; dossiers</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Icône ⇄ dans la colonne Actions :</strong> Cliquez sur le bouton <strong>⇄</strong> dans la colonne <em>Actions</em> d’un fichier ou sous-dossier pour ouvrir la boîte de dialogue et choisir le dossier cible sous <strong>⇄ Déplacer</strong> ou <strong>📋 Copier</strong>.</li>
                <li><strong>Dossiers complets :</strong> Déplacez ou copiez des dossiers entiers avec tout leur contenu via l’icône <strong>⇄</strong> de la barre de dossier active ou de la ligne du sous-dossier.</li>
                <li><strong>Entre cloud personnel &amp; dossiers de groupe :</strong> Transférez librement vos fichiers et dossiers entre votre espace personnel et les dossiers de groupe (avec droit d’écriture).</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Partages &amp; Dossiers de groupe</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Liens publics :</strong> Créez un lien sécurisé pour un fichier ou un dossier – avec <strong>mot de passe</strong> et <strong>date d’expiration</strong> optionnels.</li>
                <li><strong>Partage direct entre utilisateurs :</strong> Partagez des fichiers ou dossiers avec d’autres membres enregistrés (visibles sous <em>« Partagés avec moi »</em>).</li>
                <li><strong>Dossiers de groupe :</strong> Les dossiers d’équipe offrent un accès en lecture seule (👁️ Lecture seule) ou complet (✏️ Lecture &amp; Écriture).</li>
            </ul>
        </section>
    </div>

    <!-- Mode Coffre (FR) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Mode Coffre – Chiffrement individuel (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Protégez vos documents sensibles avec un mot de passe connu de vous seul (principe Zero-Knowledge)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Chiffrer via l’icône cadenas :</strong> Une fois votre fichier téléversé, cliquez sur l’<strong>icône cadenas 🔒</strong> dans la colonne <em>Actions</em> pour le chiffrer avec <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong>.</li>
            <li><strong>Déchiffrer &amp; Partager :</strong> Les fichiers protégés portent le badge <strong>🔒 Coffre</strong>. Le mot de passe du coffre est demandé au téléchargement. Vous pouvez aussi supprimer définitivement la protection à tout moment via l’icône cadenas.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Avertissement important concernant le mot de passe du coffre (Zero-Knowledge) :</strong><br>
            Votre mot de passe Coffre n’est <strong>jamais enregistré sur le serveur</strong> – ni dans la base de données, ni dans un fichier de configuration. <strong>Si vous oubliez le mot de passe d’un fichier, personne – pas même l’administrateur – ne pourra restaurer le fichier !</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Corbeille, Thèmes, Langue &amp; 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Conservation 30 jours dans la corbeille :</strong> Les fichiers supprimés sont conservés 30 jours dans la <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Corbeille</a> avant suppression définitive.</li>
                <li><strong>Paramètres &amp; 2FA (E-mail ou Google Authenticator) :</strong> Cliquez sur l’engrenage en haut à droite (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Paramètres</a>) pour choisir la langue, le thème visuel, modifier votre mot de passe ou activer l’<strong>authentification à deux facteurs (2FA par code e-mail ou par application Google Authenticator / TOTP avec QR code généré localement)</strong>.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Notes pour les administrateurs</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 onglets thématiques, Mises à jour &amp; Rollback :</strong> Dans <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administration</a>, gérez les utilisateurs, les modules, l’apparence/mentions légales, le serveur SMTP et les mises à jour GitHub en 1 clic (avec sauvegarde locale automatique avant chaque mise à jour, restauration en 1 clic et choix libre d’une version antérieure dans l’historique GitHub).</li>
                <li><strong>Utilisateurs, Quotas &amp; 2FA :</strong> Créez des comptes, attribuez des quotas en Mo, gérez les groupes et modifiez ou désactivez la méthode 2FA des utilisateurs.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Concept de sécurité informatique</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Mesures techniques et organisationnelles (TOM) et résultats de l’audit</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                État : <?= e($dateStr) ?> &bull; Vérifié &amp; Durci
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authentification, 2FA (E-mail &amp; TOTP) &amp; Anti-Brute-Force</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Système fermé sans inscription publique. Mots de passe hachés avec <strong>BCRYPT (coût 12)</strong>, 2FA optionnelle par e-mail ou application d’authentification (Google Authenticator / RFC 6238 TOTP avec QR code local) et blocage temporaire après 5 échecs en 5 minutes.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Protection des sessions &amp; RBAC</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sessions protégées par <code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> et régénération d’ID après connexion. Vérification en temps réel des droits en base de données.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Isolation des fichiers &amp; Chiffrement Coffre</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Stockage pseudonymisé en <code>.bin</code> (droits <code>0640</code>), répertoire <code>uploads/</code> protégé par <code>.htaccess</code> et chiffrement individuel <strong>AES-256-GCM Zero-Knowledge</strong>.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Protection SQLi, XSS, CSRF &amp; Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Requêtes préparées PDO natives, échappement HTML systématique et jetons CSRF 256 bits sur toutes les actions POST.</p>
            </div>
        </div>
    </section>

    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Dictionnaire des données</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Aperçu des tables de base de données, emplacements de stockage et délais de suppression</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Table / Stockage</th>
                        <th class="py-3 px-4">Champs principaux</th>
                        <th class="py-3 px-4">Finalité</th>
                        <th class="py-3 px-4">Conservation / Suppression</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, storage_quota</code></td>
                        <td class="py-3 px-4">Authentification, 2FA optionnelle (e-mail ou Google Authenticator / TOTP), réinitialisation du mot de passe, rôle, quota &amp; thème</td>
                        <td class="py-3 px-4">Jusqu’à la suppression du compte par l’administrateur</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>filename, original_name, folder_path, file_size, is_encrypted, deleted_at</code></td>
                        <td class="py-3 px-4">Métadonnées des fichiers et arborescence des dossiers</td>
                        <td class="py-3 px-4">Suppression automatique après 30 jours dans la corbeille</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Données binaires pseudonymisées (optionnellement chiffrées AES-256)</td>
                        <td class="py-3 px-4">Suppression physique (<code>unlink</code>) lors du vidage de la corbeille ou après 30 jours</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($lang === 'es'): ?>
    <!-- Header (ES) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Ayuda y Manual de Usuario – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Resumen de todas las funciones, atajos de teclado, información de seguridad y diccionario de datos.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Volver a mis archivos
        </a>
    </div>

    <nav aria-label="Índice" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Ir a:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Subida y Carpetas</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Vista previa y Orden</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Mover y Copiar</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Compartir y Grupos</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Modo Bóveda</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Papelera y Ajustes</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Administración</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ Seguridad TI</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Diccionario de datos</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Subir archivos y carpetas</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Subida instantánea con arrastrar y soltar:</strong> Arrastre archivos o carpetas completas directamente al área punteada. La subida comienza automáticamente.</li>
                <li><strong>Botones de selección y Office Light:</strong> Use <em>«+ Archivos»</em> para varios archivos, <em>«Carpeta»</em> para subir toda una estructura de directorios o <strong>«📄+ / + Office Light»</strong> para crear directamente documentos Word (<code>.docx</code>), hojas de cálculo Excel (<code>.xlsx</code>), listas CSV (<code>.csv</code>) o notas (<code>.txt</code> / <code>.md</code>).</li>
                <li><strong>Carpeta de destino:</strong> El campo <em>«Carpeta de destino (opcional)»</em> usa por defecto la carpeta actual, o puede escribir una ruta nueva (p. ej. <code>Proyectos/2026</code>).</li>
                <li><strong>Subcarpetas:</strong> Cree nuevas carpetas o subcarpetas en cualquier momento desde la barra de carpeta activa.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">📄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Vista previa, Office Light (Word y Excel) y Menú móvil</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📄 MiniCloud Office Light (Word, Excel y Notas en línea):</strong> Cree y edite documentos <strong>Word (<code>.docx</code>)</strong> con formato enriquecido o tablas <strong>Excel (<code>.xlsx</code> / <code>.csv</code>)</strong> con múltiples hojas y fórmulas en vivo (<code>=SUMME(A1:A10)</code>, <code>=MITTELWERT(...)</code>, <code>=MIN(...)</code>, <code>=MAX(...)</code>, <code>=A1*B1</code>) directamente en el navegador (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Ctrl+S</kbd>).</li>
                <li><strong>Vista previa directa y Navegación:</strong> Haga clic en el icono del ojo (<span class="text-violet-600 dark:text-violet-400 font-semibold">Vista previa</span>) o el nombre para abrir imágenes, PDF, vídeos, audio y texto; navegue con <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> y <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd>.</li>
                <li><strong>Iconos en escritorio y menú móvil ⋯:</strong> En escritorio todos los iconos de acción se muestran uno al lado del otro; en móviles se agrupan en un botón compacto <strong>⋯</strong> que abre un menú táctil claro.</li>
                <li><strong>Ordenar tabla:</strong> Haga clic en los encabezados <em>Nombre</em>, <em>Carpeta</em>, <em>Tamaño</em> o <em>Tipo / Fecha</em> para ordenar de forma ascendente o descendente.</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Mover o copiar archivos y carpetas</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Icono compacto ⇄ en la columna Acciones:</strong> Haga clic en el botón <strong>⇄</strong> en la columna <em>Acciones</em> de cualquier archivo o subcarpeta para abrir el diálogo y elegir la carpeta de destino bajo <strong>⇄ Mover</strong> o <strong>📋 Copiar</strong>.</li>
                <li><strong>Carpetas completas:</strong> Tanto desde el icono <strong>⇄</strong> de la barra superior como en cada fila de subcarpeta, puede mover o copiar carpetas completas con todo su contenido.</li>
                <li><strong>Entre nube personal y carpetas de grupo:</strong> Mueva o copie archivos y carpetas en ambas direcciones entre sus carpetas personales y las carpetas de grupo con permiso de escritura.</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Compartir y Carpetas de grupo</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Enlaces públicos:</strong> Cree un enlace seguro para archivos o carpetas, opcionalmente con <strong>contraseña</strong> y <strong>fecha de caducidad</strong>.</li>
                <li><strong>Compartir con usuarios:</strong> Comparta archivos o carpetas directamente con otros usuarios registrados (aparecen en <em>«Compartido conmigo»</em>).</li>
                <li><strong>Carpetas de grupo:</strong> Las carpetas de equipo permiten acceso de solo lectura (👁️ Solo lectura) o completo (✏️ Lectura y Escritura).</li>
            </ul>
        </section>
    </div>

    <!-- Modo Bóveda (ES) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Modo Bóveda – Cifrado individual de archivos (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Proteja documentos sensibles con una contraseña conocida solo por usted (principio Zero-Knowledge)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Cifrar mediante el icono de candado:</strong> Tras subir un archivo, haga clic en el <strong>icono de candado 🔒</strong> en la columna <em>Acciones</em> para cifrarlo con <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong>.</li>
            <li><strong>Descifrar y Compartir:</strong> Los archivos protegidos llevan la insignia <strong>🔒 Bóveda</strong>. Al descargarlos se solicita la contraseña de bóveda. También puede eliminar la protección permanentemente en cualquier momento desde el icono de candado.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Aviso importante sobre la contraseña de Bóveda (Zero-Knowledge):</strong><br>
            La contraseña de Bóveda elegida <strong>no se guarda en ningún lugar del servidor</strong>, ni en la base de datos ni en archivos de configuración. <strong>Si olvida la contraseña de Bóveda de un archivo, nadie —ni siquiera el administrador— podrá recuperarlo.</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Papelera, Temas, Idioma y 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30 días en la papelera:</strong> Los archivos eliminados pasan primero a la <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Papelera</a> durante 30 días antes de su borrado definitivo.</li>
                <li><strong>Ajustes y 2FA (Correo o Google Authenticator):</strong> Haga clic en el engranaje superior (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Ajustes</a>) para cambiar el idioma, el tema de color, su contraseña o activar la <strong>autenticación de dos factores (2FA por correo o mediante Google Authenticator / app TOTP con código QR generado localmente)</strong>.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Notas para administradores</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 pestañas temáticas, Actualizaciones y Rollback:</strong> En <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administración</a> puede gestionar usuarios, módulos, diseño/aviso legal, servidor SMTP y actualizaciones desde GitHub en 1 clic (incluyendo puntos de restauración locales automáticos antes de cada actualización y selección libre de versiones anteriores).</li>
                <li><strong>Usuarios, Grupos, Cuotas y 2FA:</strong> Cree cuentas, asigne cuotas en MB, gestione grupos y cambie o desactive el método 2FA de los usuarios.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Concepto de Seguridad TI</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Medidas técnicas y organizativas (TOM) y resultados de auditoría</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Estado: <?= e($dateStr) ?> &bull; Verificado y Endurecido
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Autenticación, 2FA (Correo y TOTP) y Protección contra fuerza bruta</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sistema cerrado sin autorregistro público. Contraseñas cifradas con <strong>BCRYPT (coste 12)</strong>, 2FA opcional por correo o app autenticadora (Google Authenticator / RFC 6238 TOTP con QR local) y bloqueo tras 5 intentos fallidos en 5 minutos.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Sesiones y Control de acceso (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sesiones con <code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> y verificación de permisos en tiempo real en cada petición.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Aislamiento de archivos y Modo Bóveda</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Almacenamiento con nombres aleatorios <code>.bin</code> (permisos <code>0640</code>), directorio <code>uploads/</code> bloqueado mediante <code>.htaccess</code> y cifrado <strong>AES-256-GCM Zero-Knowledge</strong>.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Protección contra SQLi, XSS, CSRF y Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Consultas preparadas PDO nativas, escape HTML completo y tokens CSRF de 256 bits para todas las acciones POST.</p>
            </div>
        </div>
    </section>

    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Diccionario de datos</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Resumen de tablas de base de datos, ubicaciones de almacenamiento y plazos de eliminación</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabla / Ubicación</th>
                        <th class="py-3 px-4">Campos principales</th>
                        <th class="py-3 px-4">Propósito</th>
                        <th class="py-3 px-4">Conservación / Eliminación</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, storage_quota</code></td>
                        <td class="py-3 px-4">Autenticación, 2FA opcional (correo o Google Authenticator / TOTP), restablecimiento de contraseña, rol, cuota y tema visual</td>
                        <td class="py-3 px-4">Hasta la eliminación de la cuenta por el administrador</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>filename, original_name, folder_path, file_size, is_encrypted, deleted_at</code></td>
                        <td class="py-3 px-4">Metadatos de archivos y estructura de carpetas</td>
                        <td class="py-3 px-4">Eliminación automática tras 30 días en la papelera</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Datos binarios seudonimizados (opcionalmente cifrados con AES-256)</td>
                        <td class="py-3 px-4">Borrado físico (<code>unlink</code>) al vaciar la papelera o tras 30 días</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($lang === 'nl'): ?>
    <!-- Header (NL) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Hulp &amp; Gebruikershandleiding – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Alle functies, sneltoetsen, beveiligingsinformatie en het gegevensoverzicht in één oogopslag.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Terug naar mijn bestanden
        </a>
    </div>

    <nav aria-label="Inhoudsopgave" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Direct naar:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Upload &amp; Mappen</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Voorbeeld &amp; Sorteren</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Verplaatsen &amp; Kopiëren</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Delen &amp; Groepsmappen</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Kluismodus</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Prullenbak &amp; Instellingen</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Beheer</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ IT-beveiligingsconcept</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Gegevensoverzicht</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Bestanden &amp; mappen uploaden</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direct uploaden via Drag &amp; Drop:</strong> Sleep losse bestanden of complete mappen rechtstreeks naar het gestippelde uploadvlak. De upload start automatisch.</li>
                <li><strong>Keuzeknoppen &amp; Office Light:</strong> Gebruik <em>“+ Bestanden”</em> voor meerdere bestanden, <em>“Map”</em> om een volledige mappenstructuur te uploaden of <strong>“📄+ / + Office Light”</strong> om direct een nieuw Word-document (<code>.docx</code>), Excel-werkblad (<code>.xlsx</code>), CSV-bestand (<code>.csv</code>) of notitie (<code>.txt</code> / <code>.md</code>) aan te maken.</li>
                <li><strong>Doelmap instellen:</strong> In het veld <em>“Doelmap (optioneel)”</em> staat automatisch uw huidige map ingevuld, of typ direct een nieuw pad (bijv. <code>Projecten/2026</code>).</li>
                <li><strong>Submappen aanmaken:</strong> Maak via de mappenbalk op elk moment nieuwe mappen of submappen aan.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">📄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Voorbeeld, Office Light (Word &amp; Excel) &amp; Mobiel menu</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📄 MiniCloud Office Light (Word, Excel &amp; Notities online):</strong> Maak en bewerk <strong>Word-documenten (<code>.docx</code>)</strong> met opmaakbalk of <strong>Excel-spreadsheets (<code>.xlsx</code> / <code>.csv</code>)</strong> met meerdere werkbladen en formules (<code>=SUMME(A1:A10)</code>, <code>=MITTELWERT(...)</code>, <code>=MIN(...)</code>, <code>=MAX(...)</code>, <code>=A1*B1</code>) rechtstreeks in de browser (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Ctrl+S</kbd>).</li>
                <li><strong>Direct voorbeeld &amp; Bladeren:</strong> Klik op het oog-icoon (<span class="text-violet-600 dark:text-violet-400 font-semibold">Voorbeeld</span>) of de bestandsnaam om afbeeldingen, pdf’s, video’s, audio en tekstbestanden te bekijken; blader met <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> en <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd>.</li>
                <li><strong>Desktop-iconen &amp; Mobiel ⋯ actiemenu:</strong> Op desktop staan alle actie-iconen naast elkaar; op smartphones opent één compacte <strong>⋯ knop</strong> een overzichtelijk aanraakmenu met alle acties.</li>
                <li><strong>Tabel sorteren:</strong> Klik op de kolomkoppen <em>Naam</em>, <em>Map</em>, <em>Grootte</em> of <em>Type / Datum</em> om oplopend of aflopend te sorteren.</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Bestanden &amp; mappen verplaatsen of kopiëren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Compact ⇄ icoon in de kolom Acties:</strong> Klik op het <strong>⇄</strong> icoon in de kolom <em>Acties</em> van een bestand of submap om het dialoogvenster te openen en kies onder <strong>⇄ Verplaatsen</strong> of <strong>📋 Kopiëren</strong> de gewenste doelmap.</li>
                <li><strong>Volledige mappen inclusief inhoud:</strong> Zowel via het <strong>⇄</strong> icoon in de bovenste mappenbalk als in elke submap-rij kunt u complete mappen inclusief alle bestanden verplaatsen of kopiëren.</li>
                <li><strong>Tussen persoonlijke cloud &amp; groepsmappen:</strong> Verplaats of kopieer bestanden en mappen in beide richtingen tussen uw persoonlijke mappen en groepsmappen met schrijfrechten.</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Delen &amp; Groepsmappen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Openbare deellinks:</strong> Maak via het deel-icoon een beveiligde link voor bestanden of mappen – optioneel met <strong>wachtwoord</strong> en <strong>vervaldatum</strong>.</li>
                <li><strong>Direct delen met cloudgebruikers:</strong> Deel bestanden of mappen rechtstreeks met andere geregistreerde gebruikers (zichtbaar onder <em>“Gedeeld met mij”</em>).</li>
                <li><strong>Groepsmappen:</strong> Gedeelde teammappen vindt u onder <em>“Groepsmappen”</em> met alleen-lezen (👁️ Alleen lezen) of schrijfrechten (✏️ Lezen &amp; Schrijven).</li>
            </ul>
        </section>
    </div>

    <!-- Kluismodus (NL) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Kluismodus – Individuele bestandsversleuteling (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Bescherm gevoelige documenten met een wachtwoord dat alleen u kent (Zero-Knowledge-principe)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Versleutelen via het slot-icoon:</strong> Klik na het uploaden op het <strong>🔒 slot-icoon</strong> in de kolom <em>Acties</em> van de bestandslijst om een bestand met <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong> te versleutelen.</li>
            <li><strong>Ontsleutelen &amp; Delen:</strong> Kluisbestanden zijn gemarkeerd met de badge <strong>🔒 Kluis</strong>. Bij het downloaden wordt om het kluiswachtwoord gevraagd. U kunt de kluisbeveiliging ook op elk moment permanent opheffen via het slot-icoon.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Belangrijke waarschuwing over het kluiswachtwoord (Zero-Knowledge):</strong><br>
            Het door u gekozen kluiswachtwoord wordt <strong>nergens op de server opgeslagen</strong> – noch in de database, noch in een configuratiebestand. <strong>Als u het kluiswachtwoord voor een bestand vergeet, kan niemand – ook de beheerder niet – het bestand herstellen!</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Prullenbak, Thema’s, Taal &amp; 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>30 dagen bewaartijd in prullenbak:</strong> Verwijderde bestanden blijven 30 dagen herstelbaar in de <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Prullenbak</a>.</li>
                <li><strong>Instellingen &amp; 2FA (E-mail of Google Authenticator):</strong> Klik rechtsboven op het tandwiel (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Instellingen</a>) om uw taal, kleurthema of wachtwoord te wijzigen, of om optionele <strong>tweefactorauthenticatie (2FA per e-mail of via Google Authenticator / TOTP-app met lokaal gegenereerde QR-code)</strong> in te schakelen.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Tips voor beheerders</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 overzichtelijke tabbladen, Updates &amp; Rollback:</strong> Beheer onder <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Beheer</a> gebruikers, modules, weergave/colofon, SMTP en 1-klik GitHub-updates (inclusief automatische lokale herstelpunten vóór elke update en vrije versie-selectie uit de GitHub-geschiedenis).</li>
                <li><strong>Gebruikers, Groepen, Quota &amp; 2FA:</strong> Maak accounts aan, wijs opslagruimte (in MB) toe, beheer groepen en wijzig of reset de 2FA-methode van gebruikers.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">IT-beveiligingsconcept</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Technische en organisatorische maatregelen (TOM) en controleresultaten</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Status: <?= e($dateStr) ?> &bull; Gecontroleerd &amp; Gehard
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authenticatie, 2FA (E-mail &amp; TOTP) &amp; Brute-Force-bescherming</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Gesloten systeem zonder openbare zelfregistratie. Wachtwoorden gehasht met <strong>BCRYPT (cost 12)</strong>, optionele 2FA per e-mail of Authenticator-app (Google Authenticator / RFC 6238 TOTP met lokale QR-code) en tijdelijke blokkering na 5 foutieve pogingen binnen 5 minuten.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Sessie- &amp; Toegangsbeveiliging (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sessies met <code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> en realtime controle van gebruikersrechten bij elke pagina-aanroep.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Opslagisolatie &amp; Kluisversleuteling</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Bestanden worden opgeslagen met willekeurige <code>.bin</code>-namen (rechten <code>0640</code>), afgeschermd via <code>.htaccess</code> en optioneel versleuteld met <strong>AES-256-GCM Zero-Knowledge</strong>.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Bescherming tegen SQLi, XSS, CSRF &amp; Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Echte PDO Prepared Statements, consequente HTML-maskering en 256-bit CSRF-tokens voor alle POST-acties.</p>
            </div>
        </div>
    </section>

    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Gegevensoverzicht (Data Dictionary)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Overzicht van alle databasetabellen, opslaglocaties en bewaartermijnen</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabel / Locatie</th>
                        <th class="py-3 px-4">Belangrijkste velden</th>
                        <th class="py-3 px-4">Doel</th>
                        <th class="py-3 px-4">Bewaring / Verwijdering</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, storage_quota</code></td>
                        <td class="py-3 px-4">Authenticatie, optionele 2FA (e-mail of Google Authenticator / TOTP), wachtwoordherstel, rol, quota &amp; thema</td>
                        <td class="py-3 px-4">Tot verwijdering van het account door de beheerder</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>filename, original_name, folder_path, file_size, is_encrypted, deleted_at</code></td>
                        <td class="py-3 px-4">Bestandsmetadata en mappenstructuur</td>
                        <td class="py-3 px-4">Automatische definitieve verwijdering na 30 dagen in de prullenbak</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">uploads/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Fysieke binaire gegevens (gepseudonimiseerd, optioneel AES-256 versleuteld)</td>
                        <td class="py-3 px-4">Fysieke verwijdering (<code>unlink</code>) bij legen van prullenbak of na 30 dagen</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php elseif ($lang === 'it'): ?>
    <!-- Header (IT) -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Guida &amp; Manuale utente – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Panoramica di tutte le funzioni, scorciatoie da tastiera, informazioni sulla sicurezza e dizionario dei dati.
            </p>
        </div>
        <a href="index.php" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto">
            &larr; Torna ai miei file
        </a>
    </div>

    <nav aria-label="Indice" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Vai a:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">📤 Caricamento &amp; Cartelle</a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">👁️ Anteprima &amp; Office Light</a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">⇄ Sposta &amp; Copia</a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🔗 Condivisione &amp; Permessi</a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">🔒 Modalità Cassaforte</a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">🗑️ Cestino &amp; Impostazioni</a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">⚙️ Amministrazione</a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">🛡️ Concetto di sicurezza IT</a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">🗂️ Dizionario dei dati</a>
        </div>
    </nav>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Caricamento di file e cartelle</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Caricamento immediato tramite Drag &amp; Drop:</strong> Trascina singoli file o intere cartelle direttamente nell'area tratteggiata. Il caricamento si avvia automaticamente.</li>
                <li><strong>Pulsanti di selezione &amp; Office Light:</strong> Usa <em>“+ File”</em> per selezionare più file, <em>“Cartella”</em> per caricare un'intera struttura di cartelle oppure <strong>“📄+ / + Office Light”</strong> per creare direttamente un nuovo documento Word (<code>.docx</code>), foglio Excel (<code>.xlsx</code>), file CSV (<code>.csv</code>) o nota (<code>.txt</code> / <code>.md</code>).</li>
                <li><strong>Cartella di destinazione:</strong> Nel campo <em>“Cartella di destinazione (opzionale)”</em> è preimpostata la cartella corrente, oppure puoi digitare un nuovo percorso (es. <code>Progetti/2026</code>).</li>
                <li><strong>Creazione di sottocartelle:</strong> Crea nuove cartelle o sottocartelle in qualsiasi momento tramite la barra delle cartelle.</li>
            </ul>
        </section>

        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">📄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Anteprima, Office Light (Word &amp; Excel) &amp; Menu mobile</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📄 MiniCloud Office Light (Word, Excel &amp; Note online):</strong> Crea e modifica <strong>documenti Word (<code>.docx</code>)</strong> con barra di formattazione o <strong>fogli di calcolo Excel (<code>.xlsx</code> / <code>.csv</code>)</strong> con più fogli e formule (<code>=SUMME(A1:A10)</code>, <code>=MITTELWERT(...)</code>, <code>=MIN(...)</code>, <code>=MAX(...)</code>, <code>=A1*B1</code>) direttamente nel browser (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Ctrl+S</kbd>).</li>
                <li><strong>Anteprima diretta &amp; Navigazione:</strong> Clicca sull'icona dell'occhio (<span class="text-violet-600 dark:text-violet-400 font-semibold">Anteprima</span>) o sul nome del file per visualizzare immagini, PDF, video, audio e file di testo; scorri con <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> e <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd>.</li>
                <li><strong>Icone desktop &amp; Menu azioni mobile ⋯:</strong> Su desktop tutte le icone di azione sono affiancate; su smartphone un unico pulsante compatto <strong>⋯</strong> apre un chiaro menu touch con tutte le azioni.</li>
                <li><strong>Ordinamento tabella:</strong> Clicca sulle intestazioni <em>Nome</em>, <em>Cartella</em>, <em>Dimensione</em> o <em>Tipo / Data</em> per ordinare in modo crescente o decrescente.</li>
            </ul>
        </section>

        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Spostare o copiare file e cartelle</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Icona compatta ⇄ nella colonna Azioni:</strong> Clicca sull'icona <strong>⇄</strong> nella colonna <em>Azioni</em> di un file o di una sottocartella per aprire la finestra di dialogo e scegliere la cartella di destinazione sotto <strong>⇄ Sposta</strong> o <strong>📋 Copia</strong>.</li>
                <li><strong>Intere cartelle incluso il contenuto:</strong> Sia tramite l'icona <strong>⇄</strong> nella barra superiore delle cartelle sia in ogni riga di sottocartella puoi spostare o copiare intere cartelle inclusi tutti i file.</li>
                <li><strong>Verso cartelle condivise:</strong> Trasferisci o copia file e cartelle tra il tuo spazio personale e le cartelle condivise con permessi di scrittura.</li>
            </ul>
        </section>

        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Condivisione &amp; Permessi a 3 livelli</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Link di condivisione pubblici:</strong> Crea tramite l'icona di condivisione un link sicuro per file o cartelle – opzionalmente con <strong>password</strong> e <strong>data di scadenza</strong>.</li>
                <li><strong>Condivisione diretta con utenti cloud (3 livelli):</strong> Condividi file o cartelle direttamente con altri utenti registrati (visibili sotto <em>“Condivisi con me”</em>) con permessi di <strong>👁️ Sola lettura</strong>, <strong>✏️ Lettura e scrittura</strong> o <strong>🗑️ Lettura, scrittura ed eliminazione</strong>.</li>
                <li><strong>Calendario &amp; Chat integrati:</strong> Condividi il tuo calendario (con abbonamento <code>.ics</code>) e comunica in tempo reale tramite il messenger integrato (con vista mobile ottimizzata senza piè di pagina durante la digitazione).</li>
            </ul>
        </section>
    </div>

    <!-- Modalità Cassaforte (IT) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Modalità Cassaforte – Crittografia di singoli file (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Proteggi i documenti sensibili con una password nota solo a te (principio Zero-Knowledge)</p>
            </div>
        </div>
        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Crittografia tramite l'icona del lucchetto:</strong> Clicca sull'icona <strong>🔒 del lucchetto</strong> nella colonna <em>Azioni</em> dell'elenco dei file per crittografare qualsiasi file con <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong>.</li>
            <li><strong>Decrittografia &amp; Condivisione:</strong> I file nella cassaforte sono contrassegnati dal badge <strong>🔒 Cassaforte</strong>. Al momento del download viene richiesta la password della cassaforte. Puoi anche rimuovere definitivamente la protezione in qualsiasi momento tramite l'icona del lucchetto.</li>
        </ul>
        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Avviso importante sulla password della cassaforte (Zero-Knowledge):</strong><br>
            La password della cassaforte scelta <strong>non viene salvata da nessuna parte sul server</strong> – né nel database né in un file di configurazione. <strong>Se dimentichi la password della cassaforte di un file, nessuno – nemmeno l'amministratore – potrà recuperare il file!</strong>
        </div>
    </section>

    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Cestino, Temi, Lingua &amp; 2FA</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Conservazione di 30 giorni nel cestino:</strong> I file eliminati restano ripristinabili per 30 giorni nel <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Cestino</a>.</li>
                <li><strong>Impostazioni &amp; 2FA (E-mail o Google Authenticator):</strong> Clicca sull'icona dell'ingranaggio in alto a destra (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Impostazioni</a>) per cambiare lingua (6 lingue integrate), schema di colori (13 temi) o password, oppure per attivare l'<strong>autenticazione a due fattori opzionale (2FA via e-mail o tramite app Google Authenticator / TOTP con codice QR generato localmente)</strong>.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Note per gli amministratori</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>5 schede chiare, Aggiornamenti &amp; Rollback:</strong> Gestisci in <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Amministrazione</a> utenti, moduli, aspetto/note legali, SMTP e aggiornamenti GitHub con 1 clic (inclusi punti di ripristino locali automatici prima di ogni aggiornamento e libera selezione della versione dalla cronologia GitHub).</li>
                <li><strong>Utenti, Gruppi, Quote &amp; 2FA:</strong> Crea account, assegna quote di archiviazione (in MB), gestisci i gruppi e modifica o reimposta il metodo 2FA degli utenti.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Concetto di sicurezza IT</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Misure tecniche e organizzative (TOM) e risultati della verifica di sicurezza</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Stato: <?= e($dateStr) ?> &bull; Verificato &amp; Protetto
            </span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Autenticazione, 2FA (E-mail &amp; TOTP) &amp; Protezione Brute-Force</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sistema chiuso senza auto-registrazione pubblica predefinita. Password sottoposte a hashing con <strong>BCRYPT (cost 12)</strong>, 2FA opzionale via e-mail o app di autenticazione (Google Authenticator / RFC 6238 TOTP con codice QR locale) e blocco temporaneo dopo 5 tentativi falliti in 5 minuti.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Sicurezza di sessione e controllo accessi (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Sessioni protette con <code>HttpOnly</code>, <code>SameSite=Lax</code>, <code>Secure</code> e verifica in tempo reale dei permessi utente a ogni caricamento di pagina.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Isolamento dell'archiviazione &amp; Crittografia Cassaforte</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">I file sono salvati con nomi casuali <code>.bin</code> (permessi <code>0640</code>), protetti tramite <code>.htaccess</code> e opzionalmente crittografati con <strong>AES-256-GCM Zero-Knowledge</strong>.</p>
            </div>
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Protezione contro SQLi, XSS, CSRF &amp; Path Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">Prepared Statement PDO nativi, codifica HTML sistematica e token CSRF a 256 bit per tutte le azioni POST.</p>
            </div>
        </div>
    </section>

    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Dizionario dei dati (Data Dictionary)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Panoramica di tutte le tabelle del database, posizioni di archiviazione e periodi di conservazione</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabella / Posizione</th>
                        <th class="py-3 px-4">Campi principali</th>
                        <th class="py-3 px-4">Scopo</th>
                        <th class="py-3 px-4">Conservazione / Eliminazione</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, is_admin, theme, storage_quota</code></td>
                        <td class="py-3 px-4">Autenticazione, 2FA opzionale (e-mail o Google Authenticator / TOTP), recupero password, ruolo, quota &amp; tema</td>
                        <td class="py-3 px-4">Fino all'eliminazione dell'account da parte dell'amministratore</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files &amp; user_folders</td>
                        <td class="py-3 px-4"><code>filename, original_name, folder_path, file_size, is_encrypted, deleted_at</code></td>
                        <td class="py-3 px-4">Metadati dei file e struttura delle cartelle</td>
                        <td class="py-3 px-4">Eliminazione definitiva automatica dopo 30 giorni nel cestino</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">uploads/ &amp; .system_backups/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>snapshot_*.zip</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Dati binari fisici (pseudonimizzati, opzionalmente crittografati AES-256) e punti di ripristino locali per rollback aggiornamenti</td>
                        <td class="py-3 px-4">Eliminazione fisica (<code>unlink</code>) allo svuotamento del cestino / rotazione automatica dei 8 snapshot più recenti</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

<?php else: ?>
    <!-- Standard: Deutsch (DE) -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <svg class="w-6 h-6 text-brand-600 dark:text-brand-400 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>Hilfe &amp; Benutzerhandbuch – <?= e($appName) ?></span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5">
                Alle Funktionen, Tastenkürzel, Sicherheitsinformationen und das Datenverzeichnis im Überblick.
            </p>
        </div>

        <a
            href="index.php"
            class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300/80 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors self-start sm:self-auto"
        >
            &larr; Zurück zu meinen Dateien
        </a>
    </div>

    <!-- Schnellnavigation -->
    <nav aria-label="Inhaltsverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3.5 sm:p-4 shadow-xs mb-6">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm font-semibold">
            <span class="text-slate-400 dark:text-slate-500 mr-1">Direkt zu:</span>
            <a href="#upload-ordner" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                📤 Upload &amp; Ordner
            </a>
            <a href="#vorschau-sortierung" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                👁️ Vorschau &amp; Sortierung
            </a>
            <a href="#verschieben-kopieren" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                ⇄ Verschieben &amp; Kopieren
            </a>
            <a href="#teilen-gruppen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                🔗 Teilen &amp; Berechtigungen
            </a>
            <a href="#kalender-termine" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                📅 Kalender &amp; .ics-Abo
            </a>
            <a href="#chat-nachrichten" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                💬 Chat &amp; Meldungen
            </a>
            <a href="#aufgaben-notizen" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                📋 Aufgaben &amp; Notizen
            </a>
            <a href="#kontakte-webdav" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                📇 Kontakte, CardDAV &amp; WebDAV
            </a>
            <a href="#tresor-modus" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">
                🔒 Tresor-Modus
            </a>
            <a href="#papierkorb-themes" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-brand-50 dark:hover:bg-brand-950/50 text-slate-700 dark:text-slate-200 hover:text-brand-600 dark:hover:text-brand-400 transition-colors">
                🗑️ Papierkorb &amp; Einstellungen
            </a>
            <?php if ($isAdminUser): ?>
                <a href="#admin-hilfe" class="px-3 py-1.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200/70 dark:border-amber-800/60 transition-colors">
                    ⚙️ Administration &amp; Update
                </a>
            <?php endif; ?>
            <a href="#it-sicherheit" class="px-3 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 text-emerald-800 dark:text-emerald-300 border border-emerald-200/70 dark:border-emerald-800/60 transition-colors">
                🛡️ IT-Sicherheitskonzept
            </a>
            <a href="#datenverzeichnis" class="px-3 py-1.5 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-800 dark:text-indigo-300 border border-indigo-200/70 dark:border-indigo-800/60 transition-colors">
                🗂️ Datenverzeichnis
            </a>
        </div>
    </nav>

    <!-- 1. Dateien & Ordner hochladen und verwalten -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <section id="upload-ordner" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-brand-100 dark:bg-brand-950 text-brand-600 dark:text-brand-400 font-bold">📤</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Dateien &amp; Ordner hochladen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direkte Drag-&amp;-Drop-Zonen auf jedem Ordner:</strong> Jede Ordner-Zeile in der Listenansicht und jede Ordner-Kachel in der Galerieansicht (sowie die Zeile <code>&crarr; .. Übergeordneter Ordner</code>) ist eine direkte Drop-Zone. Ziehen Sie Dateien oder ganze Ordner von Ihrem Computer einfach direkt auf einen Ordner, um sie sofort in genau diesen Unterordner hochzuladen – oder legen Sie sie an einer freien Stelle der Dateiliste ab, um sie in das aktuell geöffnete Verzeichnis hochzuladen.</li>
                <li><strong>Kompakte Segment-Aktionsleiste &amp; Zwischenablage-Upload (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Strg+V</kbd>):</strong> Direkt unter <em>„Meine hochgeladenen Dateien“</em> sind alle Werkzeuge platzsparend in zwei logischen Funktionsgruppen gebündelt: die Erstellungs-Gruppe (<strong>„+ Dateien“</strong> sowie <strong>„📁↑ Ordner · 📁+ Neu · 📄+ Office · ☁️↓ Import“</strong>) und die Ansichts-/Filter-Gruppe (<strong>„☆ Favoriten · 📊 Große Dateien · 📋 Liste · 🖼️ Kacheln“</strong>). Zusätzlich können Sie Screenshots oder kopierte Bilder jederzeit direkt per <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Strg+V</kbd> in den aktuellen Ordner einfügen (automatisch benannt als <code>Screenshot_JJJJ-MM-TT_HH-MM-SS.png</code>).</li>
                <li><strong>Cloud-Dateien per Drag &amp; Drop in Ordner verschieben:</strong> Ziehen Sie eine Datei (oder mehrere per Checkbox markierte Dateien gleichzeitig) einfach mit der Maus auf einen Unterordner oder auf <code>&crarr; .. Übergeordneter Ordner</code>, um sie sofort dorthin zu verschieben.</li>
                <li><strong>📦 ZIP-Archive im Browser inspizieren &amp; entpacken:</strong> Klicken Sie bei einer <code>.zip</code>-Datei auf <strong>👁️ Vorschau</strong>, um alle enthaltenen Dateien samt Größe anzusehen und das Archiv mit einem Klick direkt auf dem Server zu entpacken (wahlweise in einen eigenen Unterordner oder direkt in das aktuelle Verzeichnis).</li>
                <li><strong>🔍 Automatische SHA-256 Inhalts-Duplikaterkennung:</strong> Beim Hochladen berechnet MiniCloud für unverschlüsselte Dateien automatisch den kryptografischen <strong>SHA-256-Inhalts-Hash (<code>file_hash</code>)</strong>. Existiert in Ihrer Cloud bereits eine exakt inhaltsgleiche Datei (selbst unter anderem Dateinamen oder in einem anderen Unterordner), erhalten Sie sofort nach dem Upload einen Hinweis und sehen in der Dateiliste das Abzeichen <strong><code>⚠️ Duplikat</code></strong>.</li>
                <li><strong>Unterordner anlegen:</strong> Über die Ordnerleiste erstellen Sie jederzeit neue Ordner oder Unterordner im aktuell geöffneten Verzeichnis (auch in für Sie mit Schreibrecht freigegebenen Ordnern).</li>
            </ul>
        </section>

        <!-- 2. Dateivorschau, Office Light & Sortierung -->
        <section id="vorschau-sortierung" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-300 font-bold">📄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Vorschau, Office Light (Word, Excel, PowerPoint, Visio, Mind-Maps &amp; Foto-Studio) &amp; Mobil-Menü</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📄 MiniCloud Office Light (6-in-1 Suite: Word, Excel, PowerPoint, Visio-Diagramme, Mind-Maps &amp; 🎨 Foto-Studio inkl. 👥 Live-Team-Bearbeitung):</strong>
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>Neu erstellen:</strong> Über den Button <strong>„📄+“ / „+ Office Light“</strong> in der Upload-Leiste (oder in der <a href="office.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Office-Zentrale</a>) legen Sie direkt neue <strong>Word-Dokumente (<code>.docx</code>)</strong>, <strong>Excel-Tabellen (<code>.xlsx</code>)</strong>, <strong>PowerPoint-Präsentationen (<code>.pptx</code>)</strong>, <strong>Visio-Flussdiagramme &amp; Organigramme (<code>.drawio</code>)</strong>, <strong>Mind-Maps</strong>, <strong>CSV-Listen (<code>.csv</code>)</strong> oder <strong>Text-/Markdown-Notizen (<code>.txt</code> / <code>.md</code>)</strong> an.</li>
                        <li><strong>🎨 Integriertes Foto- &amp; Bild-Studio (<code>.jpg</code>, <code>.png</code>, <code>.webp</code>, <code>.gif</code>, <code>.bmp</code>):</strong> Öffnen Sie jedes Bild direkt aus der <strong>Dateivorschau (Button „🎨 Bild bearbeiten“)</strong>, aus der <strong>🖼️ Foto-Galerie (Lightbox-Button „🎨 Bearbeiten“ oder 🎨-Icon auf der Fotokachel)</strong> oder über den Reiter <strong>🎨 Bilder</strong> in der Office-Zentrale:
                            <ul class="list-disc pl-5 mt-1 space-y-0.5">
                                <li><strong>✂️ Zuschneiden, Drehen &amp; Spiegeln:</strong> 90° links/rechts drehen, horizontal/vertikal spiegeln sowie frei oder im festen Seitenverhältnis (<code>1:1</code>, <code>16:9</code>, <code>4:3</code>, <code>3:2</code>, <code>DIN A4</code>) zuschneiden.</li>
                                <li><strong>📐 Größe ändern &amp; Komprimieren:</strong> Schnell-Buttons (<code>75 %</code>, <code>50 %</code>, <code>Full-HD 1920 px</code>, <code>Web 1280 px</code>, <code>Kompakt 800 px</code>), Formatwechsel (<code>JPEG</code>, <code>WebP</code>, <code>PNG</code>) und Qualitätsregler mit <strong>Live-Dateigrößen-Vorschau (KB/MB)</strong>.</li>
                                <li><strong>☀️ Belichtung, Farben &amp; 1-Klick-Foto-Filter:</strong> Helligkeit, Kontrast, Sättigung und Sepia stufenlos regeln oder 1-Klick-Filter (<em>✨ Auto-Brillanz, 🖤 Schwarz-Weiß, 📜 Vintage, 🌅 Warm, 📄 Dokumenten-Kontrast</em>) anwenden.</li>
                                <li><strong>✏️ Zeichnen, Text/Wasserzeichen &amp; 🔲 DSGVO-Verpixelung:</strong> Mit dem <strong>🔲 DSGVO-Verpixelungs-Pinsel</strong> (oder 💧 Unschärfe-Pinsel) machen Sie Gesichter, Kfz-Kennzeichen oder sensible Daten vor dem Teilen irreversibel unkenntlich; zudem stehen Hinweispfeile, Rahmen, Freihand-Stift, Text-Wasserzeichen, 15-stufiges <code>↩️ Rückgängig</code> sowie die Wahl zwischen <strong>„💾 Original aktualisieren (mit 🕓 Versionsschutz)“</strong> und <strong>„📄 Als neue Kopie speichern“</strong> bereit.</li>
                            </ul>
                        </li>
                        <li><strong>Online bearbeiten (Word &amp; Excel inkl. Formel-Übersicht):</strong> Klicken Sie am Desktop auf das <strong>✏️ Stift-Symbol</strong> (oder im Vorschau-Fenster bzw. mobilen <strong>⋯ Menü</strong> auf <strong>„✏️ In Office Light bearbeiten“</strong>), um Word-Dokumente mit Formatierungsleiste (Überschriften, Fett/Kursiv, Listen, Tabellen, Druck/PDF) oder Excel-Tabellen mit mehreren Tabellenblättern direkt im Browser zu bearbeiten und per <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Strg+S</kbd> (oder automatischem <strong>⚡ Auto-Save</strong>) zu speichern.
                            <div class="mt-2 p-3 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700/80 text-xs space-y-1.5">
                                <div class="font-bold text-slate-900 dark:text-white">📊 Verfügbare Rechenformeln in Excel Light (<code>.xlsx</code> &amp; <code>.csv</code> – Deutsch &amp; Englisch):</div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-[11px] sm:text-xs">
                                    <div>• <code>=SUMME(A1:A10)</code> / <code>=SUM(...)</code> – Addiert Zellbereich oder Einzelwerte</div>
                                    <div>• <code>=MITTELWERT(A1:A10)</code> / <code>=AVERAGE(...)</code> / <code>=AVG(...)</code> – Durchschnitt</div>
                                    <div>• <code>=MIN(A1:A10)</code> &amp; <code>=MAX(A1:A10)</code> – Kleinster bzw. größter Zahlenwert</div>
                                    <div>• <code>=ANZAHL(A1:A10)</code> / <code>=COUNT(...)</code> – Zählt Zellen mit Zahlenwerten</div>
                                    <div>• <code>=PRODUKT(A1:A5)</code> / <code>=PRODUCT(...)</code> – Multipliziert alle Werte im Bereich</div>
                                    <div>• <code>=RUNDEN(Wert; Stellen)</code> / <code>=ROUND(...)</code> – Rundet (z. B. <code>=RUNDEN(A1*1,19; 2)</code>)</div>
                                    <div>• <code>=WENN(Bedingung; Dann; Sonst)</code> / <code>=IF(...)</code> – Logikprüfung (z. B. <code>=WENN(A1&gt;=0; "Plus"; "Minus")</code>)</div>
                                    <div>• <code>=ABS(A1)</code>, <code>=WURZEL(A1)</code> / <code>=SQRT(A1)</code> &amp; <code>=HEUTE()</code> / <code>=TODAY()</code></div>
                                    <div class="sm:col-span-2">• <strong>Grundrechenarten &amp; Klammern:</strong> <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>, <code>( )</code> sowie deutsche Kommazahlen (z. B. <code>=(D2-E2)*1,19</code>) inkl. automatischem Zirkelbezug-Schutz (<code>#ZIRKEL!</code>). Über den Button <strong>„ƒx Alle Formeln“</strong> in der Tabellen-Werkzeugleiste lassen sich alle Formeln mit 1 Klick einfügen.</div>
                                </div>
                            </div>
                        </li>
                        <li><strong>📙 PowerPoint Light (<code>.pptx</code> Präsentationen &amp; Beamer-Modus):</strong> Erstellen und bearbeiten Sie 16:9-Breitbild-Präsentationen mit <strong>6 Folien-Layouts</strong> (<em>Titelfolie, Aufzählung, Zwei Spalten, Cloud-Bild + Text, Große Kennzahl/Zitat, Kapitel-Trennfolie</em>), <strong>6 Farbdesigns</strong>, <strong>🎙️ Sprechernotizen</strong>, <strong>🖨️ PDF-Handout-Druck</strong> sowie einem integrierten <strong>▶️ Vollbild-Beamer-Modus mit Laserpointer (Taste <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">L</kbd>)</strong>. Gespeicherte <code>.pptx</code>-Dateien sind echtes OpenXML und lassen sich nahtlos in Microsoft PowerPoint, LibreOffice Impress und Apple Keynote öffnen.</li>
                        <li><strong>📐 Visio Light (<code>.drawio</code> Flussdiagramme, Organigramme &amp; Netzwerkpläne):</strong> Zeichnen Sie auf der interaktiven Zeichenfläche professionelle Prozess-Flussdiagramme und Vereins-/Team-Organigramme mit <strong>6 Baustein-Formen</strong> (<em>Rechteck, Abgerundet, Ja/Nein-Raute, Start/Ende-Kapsel, Person/Rolle, Notiz</em>), <strong>intelligenten Verbindungspfeilen</strong> (<em>geschwungen, rechtwinklig oder gerade</em>, durchgezogen oder gestrichelt), <strong>🪄 1-Klick Auto-Layout</strong> und <strong>Vektor-SVG-Export</strong>.</li>
                        <li><strong>🧠 Integrierter Mind-Map-Editor:</strong> Direkt in der Office-Zentrale unter dem Reiter <strong>🧠 Mind-Maps</strong> verwalten und starten Sie visuelle Ideensammlungen (<a href="mindmap.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Mind-Map-Editor</a>) inklusive 1-Klick-Umwandlung von Knoten in Kanban-Aufgaben oder Haftnotizen.</li>
                        <li><strong>👥 Gemeinsam gleichzeitig arbeiten (Live-Team Stufe 1):</strong> Öffnen mehrere berechtigte Benutzer dieselbe freigegebene Datei in Office Light, zeigt die obere <strong>🟢 Live-Team-Leiste</strong> in Echtzeit alle anwesenden Personen samt ihrer aktuellen Position (z. B. <code>👤 Anna · Zelle B4</code>, <code>👤 Uwe · Absatz #2</code>, <code>👤 Lisa · Folie 3</code> oder <code>👤 Tom · Baustein #4</code>). Aktuell bearbeitete Excel-Zellen, Word-Absätze, PowerPoint-Folien und Visio-Bausteine werden live synchronisiert.</li>
                    </ul>
                </li>
                <li><strong>⭐ Flexibel anheftbare &amp; frei verschiebbare Favoriten-Icons in Kopfzeile &amp; Dateiliste:</strong>
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>Kopfzeile (<code>⊞ Module</code> &amp; <code>👤</code> Konto-Menü):</strong> Im <strong>⊞ Module</strong>-Menü oben rechts sind alle Cloud-Module gebündelt. Über den <strong>⭐ / ☆ Stern</strong> oben rechts auf jeder Modulkachel legen Sie Ihre meistgenutzten Module direkt nach außen in die Kopfzeile oder räumen sie wieder ins Menü auf. Zudem lassen sich sowohl die Kacheln im <strong>⊞ Module</strong>-Menü als auch die oben angehefteten Buttons in der Kopfzeile per <strong>Drag &amp; Drop (<code>⠿</code>)</strong> frei in Ihrer Wunsch-Reihenfolge anordnen! Speicheranzeige, Admin, Einstellungen, Hilfe und Abmelden befinden sich gebündelt im <strong>👤 Konto-Menü</strong>.</li>
                        <li><strong>Datei-Aktionen &amp; <code>⋯</code>-Menü (inkl. Rechtsklick):</strong> Neben jeder Datei und jedem Ordner zeigt die Aktionsspalte Ihre favorisierten Schnellzugriffs-Icons (Standard: 👁️ Vorschau, ⬇️ Download, 🔗 Teilen) sowie den <strong>⋯ Button</strong> (auch per <strong>Rechtsklick</strong> auf die Zeile erreichbar). Im <code>⋯</code>-Menü können Sie jede Aktion (z. B. ✏️ Office Light, 💬 Kommentare &amp; Versionen, 🔒 Tresor, ⇄ Verschieben, 🗑️ Löschen) per Klick auf <strong>⭐ / ☆</strong> flexibel nach außen legen oder wieder ausblenden.</li>
                    </ul>
                </li>
                <li><strong>🕓 Automatische Dateiversionierung („Zeitreise“) &amp; 💬 Datei-Kommentare:</strong> Beim Speichern in Office Light, beim erneuten Hochladen einer gleichnamigen Datei oder beim Überschreiben per WebDAV bewahrt uWiiiMiniCloud automatisch bis zu <strong>5 frühere Versionen</strong> auf. Über das <strong>💬 Symbol</strong> (oder die Badges <code>🕓</code> / <code>💬</code> hinter dem Dateinamen) öffnen Sie den Dialog <em>Kommentare &amp; Versionen</em>, um ältere Stände mit 1 Klick herunterzuladen, wiederherzustellen oder teaminterne Notizen direkt an der Datei zu hinterlassen.</li>
                <li><strong>🖼️ Foto-Galerie / Kachel-Ansicht mit serverseitigem WebP/JPEG-Thumbnail-Cache &amp; ⭐ Favoriten:</strong> Schalten Sie über der Dateiliste jederzeit zwischen der klassischen Tabellenansicht (<strong>📋 Liste</strong>) und der Kachel-Ansicht (<strong>🖼️ Kacheln</strong>) um. Vorschaubilder in der Kachelansicht, in öffentlichen Ordner-Freigaben und in der <a href="gallery.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Foto-Galerie</a> werden über <code>thumbnail.php</code> automatisch als komprimierte <strong>WebP-/JPEG-Thumbnails (360 px inkl. EXIF-Drehungskorrekturen &amp; 30-Tage-Browser-Cache)</strong> im geschützten Verzeichnis <code>uploads/.thumbs/</code> zwischengespeichert – das spart bis zu 95 % Datenvolumen gegenüber dem Laden großer Originalfotos. Wichtige Dateien heften Sie per Klick auf den Stern (<strong>☆ / ⭐</strong>) ganz oben an oder filtern sie über <strong>⭐ Favoriten</strong>.</li>
                <li><strong>☑️ Mehrfachauswahl für Ordner &amp; Dateien (Sammel-Aktionen) &amp; ⌨️ Globale Spotlight-Schnellsuche (<kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Strg+K</kbd>):</strong> Haken Sie in der Listenansicht beliebig viele <strong>Ordner und/oder Dateien</strong> gleichzeitig an (auch über die Kopfzeilen-Checkbox <em>„Alle Ordner &amp; Dateien auswählen“</em>), um komplette Ordner samt Inhalt und Dateien über die schwebende Aktionsleiste in einem Schritt zu <strong>verschieben/kopieren</strong>, in den <strong>Papierkorb zu löschen</strong> oder ausgewählte Dateien als <strong>ZIP herunterzuladen</strong>. Mit dem Tastenkürzel <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">Strg+K</kbd> (bzw. dem Button <code>🔎 Suchen Strg+K</code> in der oberen Kopfzeile) öffnen Sie von jeder Seite aus die globale Echtzeit-Schnellsuche über alle Ordner, Dateien, Whiteboards, Notizen, Aufgaben, Termine, Kontakte und Module.</li>
                <li><strong>Direktvorschau (Erweiterte Vollbild-Lightbox) &amp; Durchblättern:</strong> Ein Klick auf das Augen-Symbol (<span class="text-violet-600 dark:text-violet-400 font-semibold">Vorschau</span>) oder den Dateinamen öffnet Bilder, PDFs, Videos, Audio sowie Text-/Codedateien; mit <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&larr;</kbd> / <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">&rarr;</kbd> blättern Sie durch den Ordner. Direkt in der Kopfzeile des Vorschaufensters stehen kontextsensitive Schnell-Buttons bereit: <strong>🎨 Zuschneiden / Bild-Studio</strong> (bei Bildern), <strong>📝 In Office Light bearbeiten</strong>, <strong>🔎 OCR / PDF-Werkzeuge</strong> sowie <strong>🤖 KI-Zusammenfassung</strong> (öffnet die Datei direkt im Dokumenten-Chat des lokalen KI-Assistenten).</li>
                <li><strong>📊 Große Dateien (Top 100, <code>?large=1</code>) &amp; Seiten-Blätterfunktion (Pagination):</strong> Direkt neben dem Button <em>„⭐ Favoriten“</em> über der Dateiliste (oder per Klick auf Ihre Speicher-Anzeige links) aktivieren Sie die Ansicht <strong>„📊 Große Dateien“</strong>. Diese ermittelt blitzschnell Ihre <strong>Top 100 größten Dateien</strong> absteigend nach Speicherverbrauch – inklusive <strong>prozentualem Speicherbalken</strong>, <strong>Größen-Filter-Pills</strong> (<em>Alle, ≥ 5 MB, ≥ 25 MB, ≥ 100 MB</em>) und <strong>1-Klick-Sprung in den jeweiligen Herkunftsordner</strong>. Bei sehr großen Ordnern mit hunderten Dateien teilt die automatische <strong>Seiten-Blätterfunktion (je 200 Dateien pro Seite)</strong> die Ansicht übersichtlich und verzögerungsfrei auf.</li>
                <li><strong>Tabellensortierung:</strong> Klicken Sie auf <em>Name</em>, <em>Ordner</em>, <em>Größe</em> oder <em>Typ / Datum</em>, um auf- oder absteigend zu sortieren.</li>
            </ul>
        </section>

        <!-- 3. Verschieben & Kopieren -->
        <section id="verschieben-kopieren" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⇄</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Dateien &amp; Ordner verschieben oder kopieren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Kompaktes ⇄ Symbol in der Aktionsspalte:</strong> Klicken Sie in der Spalte <em>„Aktionen“</em> einer Datei oder eines Unterordners auf das <strong>⇄</strong>-Symbol, um im Dialogfenster unter <strong>⇄ Verschieben</strong> oder <strong>📋 Kopieren</strong> den gewünschten Zielordner auszuwählen.</li>
                <li><strong>Ganze Ordner samt Inhalt:</strong> Sowohl über das <strong>⇄</strong>-Symbol in der oberen Ordnerleiste als auch direkt in jeder Unterordner-Zeile der Tabelle können komplette Ordner inklusive aller Unterordner und Dateien verschoben oder kopiert werden.</li>
                <li><strong>In freigegebene Ordner übertragen:</strong> Haben andere Benutzer Ihnen für einen Ordner Schreibrechte (<strong>✏️ Lesen &amp; Schreiben</strong>) eingeräumt, können Sie Dateien direkt dorthin hochladen, kopieren oder verschieben.</li>
            </ul>
        </section>

        <!-- 4. Teilen & 3-stufiges Berechtigungssystem -->
        <section id="teilen-gruppen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold">🔗</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Freigaben, Digitaler Briefkasten &amp; 3-stufige Rechte</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Öffentliche Freigabelinks, 📱 QR-Code, 🔥 Selbstzerstörung &amp; 📥 Digitaler Briefkasten (File-Drop):</strong> Über das Teilen-Symbol erstellen Sie für Dateien oder ganze Ordner einen kryptografisch gesicherten Link – optional mit <strong>Passwortschutz</strong>, <strong>Ablaufdatum</strong>, <strong>🔥 Max. Downloads (Selbstzerstörung / „Burn after reading“ nach z. B. 1×, 3×, 5× oder 10× Abrufen)</strong> sowie einem <strong>📱 100 % lokal erzeugten QR-Code</strong> (inkl. <code>.svg</code>-Download). Für Ordner-Links wählen Sie zwischen:
                    <ul class="list-disc pl-4 mt-1 space-y-0.5">
                        <li><strong>👁️ Nur Download:</strong> Besucher können Dateien ansehen und herunterladen.</li>
                        <li><strong>📤 Digitaler Briefkasten (Nur Hochladen):</strong> Gäste können Ihnen Dateien/Fotos ohne eigenes Benutzerkonto zusenden, ohne bereits im Ordner liegende Dateien anderer Personen zu sehen.</li>
                        <li><strong>🔄 Download &amp; Upload:</strong> Gemeinsamer Austausch-Ordner für alle Link-Empfänger.</li>
                        <li><strong>🔔 Automatische Eigentümer-Benachrichtigung im Messenger:</strong> Sobald ein Gast Dateien in Ihren digitalen Briefkasten hochlädt oder eine geteilte Datei herunterlädt (beim ersten Abruf sowie beim Erreichen eines Download-Limits), erhalten Sie automatisch eine System-Benachrichtigung direkt in Ihrem <a href="messages.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Cloud-Messenger</a>.</li>
                    </ul>
                </li>
                <li><strong>Direktes Teilen mit Cloud-Benutzern (3 Stufen):</strong> Geben Sie Dateien oder Ordner gezielt für andere Benutzer frei (sichtbar unter <em>„Für mich freigegeben“</em>) – mit <strong>👁️ Nur Lesen</strong>, <strong>✏️ Lesen &amp; Schreiben</strong> oder <strong>🗑️ Lesen, Schreiben &amp; Löschen</strong>.</li>
                <li><strong>Datenschutz-Standard:</strong> Neue Benutzerkonten sind standardmäßig in der Teilen- und Chat-Auswahl anderer Nutzer verborgen und können ihre Sichtbarkeit jederzeit in den <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen</a> aktivieren.</li>
            </ul>
        </section>

        <!-- 4a. Kalender & Terminverwaltung -->
        <section id="kalender-termine" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 font-bold">📅</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Kalender, Freigaben &amp; .ics-Abo</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Monats- &amp; Listenansicht (<a href="calendar.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Kalender</a>):</strong> Erstellen Sie Einzel- oder Ganztagestermine mit Farbkodierung, Ort, Beschreibung, Wiederholungen (täglich, wöchentlich, monatlich, jährlich) und verknüpften Cloud-Dateien.</li>
                <li><strong>Kalender teilen (3 Berechtigungsstufen):</strong> Teilen Sie Ihren Kalender gezielt mit sichtbaren Cloud-Benutzern – wahlweise mit <strong>👁️ Nur Lesen</strong>, <strong>✏️ Lesen &amp; Bearbeiten</strong> oder <strong>🗑️ Lesen, Bearbeiten &amp; Löschen</strong>. Termine mit dem Häkchen <em>„🔒 Privat“</em> bleiben trotz Kalender-Freigabe nur für Sie selbst sichtbar.</li>
                <li><strong>.ics-Import, Export &amp; Smartphone-Abo:</strong> Importieren oder exportieren Sie <code>.ics</code>-Kalenderdateien oder aktivieren Sie Ihren persönlichen <strong>.ics-Abo-Link</strong> zur Live-Synchronisation mit iPhone/iPad, Android, Thunderbird oder Outlook.</li>
            </ul>
        </section>

        <!-- 4b. Integrierter Messenger / Chat & System-Benachrichtigungen -->
        <section id="chat-nachrichten" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400 font-bold">💬</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Chat, Dateianhänge &amp; Meldungen</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Direktnachrichten, 🎙️ Sprachnachrichten, 📋 <kbd class="px-1 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-[11px] font-mono">Strg+V</kbd>-Screenshots &amp; 📎 Dateianhänge (<a href="messages.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Chat</a>):</strong> Schreiben Sie 1:1-Nachrichten mit freigegebenen Cloud-Benutzern, nehmen Sie über den <strong>🎙️ Mikrofon-Button</strong> direkt Sprachnachrichten im Browser auf (mit integriertem Audio-Player in der Sprechblase), fügen Sie Screenshots direkt per <kbd class="px-1 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-[11px] font-mono">Strg+V</kbd> ein, hängen Sie über <strong>📎</strong> Dateien an oder lesen Sie offizielle Rundnachrichten im Kanal <em>„📢 Ankündigungen (Alle)“</em>.</li>
                <li><strong>Verlauf leeren &amp; Optimierte Mobil-Darstellung:</strong> Über den Button <em>„🗑️ Verlauf leeren“</em> können Sie einen Chatverlauf jederzeit vollständig bereinigen. Auf Smartphones schaltet der Messenger automatisch in eine übersichtliche 1-Spalten-Navigation (Kontaktliste &leftrightarrow; Vollbild-Chat), blendet den Seiten-Footer automatisch aus und passt die Höhe des Chatfensters dynamisch an die Bildschirmtastatur an.</li>
                <li><strong>System-Benachrichtigungen am PC &amp; Mobilgerät:</strong> Bei neuen Nachrichten blendet MiniCloud auf Wunsch native Betriebssystem-/Smartphone-Meldungen ein. In den <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen</a> legen Sie fest, ob Sie <em>Nur System-Benachrichtigungen</em> (Standard), zusätzlich ein <em>In-App-Banner</em> oder gar keine Meldungen wünschen.</li>
            </ul>
        </section>

        <!-- 4c. Aufgaben, Projektmanagement & Kanban-Board + Notizwand -->
        <section id="aufgaben-notizen" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">📋</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Projektmanagement, Kanban-Boards &amp; Notizwand</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>🚀 Projektmanagement &amp; Projekt-Zentrale (<a href="projects.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Projekte</a>):</strong> Planen und steuern Sie Projekte mit Projektkürzel, Zeitraum, Status-Ampel, <strong>Meilensteinen &amp; visuellem Gantt-Zeitstrahl</strong>, integrierter <strong>Live-Stoppuhr &amp; Zeiterfassung</strong>, Kosten-/Budgetkontrolle, chronologischem <strong>Projekt-Tagebuch</strong> sowie druckfertigem Projektstatus-Bericht. Jedes Projekt lässt sich direkt mit einem Kanban-Board verknüpfen sowie komplett mit Cloud-Benutzern oder per externem Kunden-Link teilen.</li>
                <li><strong>📋 Aufgaben &amp; Multi-Projekt-Kanban-Boards (<a href="tasks.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Aufgaben</a>):</strong> Legen Sie über <em>„🗂️ + Neues Projekt-Board“</em> beliebig viele eigene Boards für unterschiedliche Projekte (mit eigenem Symbol, Farbschema und Beschreibung) an und wechseln Sie oben per Reiter-Leiste zwischen <em>🌐 Alle Aufgaben</em>, dem <em>🏠 Standard-Board</em> und Ihren Projekt-Boards.</li>
                <li><strong>🤝 Ganzes Kanban-Board auf einmal teilen:</strong> Über den Button <em>„🤝 Ganzes Board teilen / Bearbeiten“</em> geben Sie ein komplettes Projekt-Board mit 1 Klick für einen Cloud-Benutzer oder über einen öffentlichen Teilen-Link (wahlweise <em>Nur Lesen</em> oder <em>Mitarbeit erlaubt</em>) frei. Alle enthaltenen und neu angelegten Karten im Projekt-Board sind sofort für alle Berechtigten sichtbar und per Drag &amp; Drop zwischen <em>📌 Offen</em>, <em>⏳ In Arbeit</em> und <em>✅ Erledigt</em> verschiebbar.</li>
                <li><strong>📌 Notizwand, 🎙️ Sprachnotizen &amp; 🗣️ Diktierfunktion (<a href="notes.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Notizen</a>):</strong> Erstellen Sie farbige digitale Haftnotizen (Gelb, Blau, Grün, Rosa, Violett, Neutral), nehmen Sie über <strong>„🎙️ Sprachnotiz“</strong> direkt eingesprochene Audio-Memos per Mikrofon auf (inkl. integriertem Audio-Player, Tempo-Wahl <code>1×–2×</code> und Ablage unter <code>Notizen/Sprachnotizen</code>), nutzen Sie <strong>„🗣️ Diktieren (Sprache &rarr; Text)“</strong>, pinnen Sie wichtige Zettel oben an, filtern Sie nach <code>#Schlagworten</code> oder <code>🎙️ Sprachnotizen</code> und exportieren Sie jede Notiz per Klick auf <strong>☁️ .md</strong> direkt als Markdown-Datei in Ihren Cloud-Ordner <code>Notizen</code>.</li>
            </ul>
        </section>

        <!-- 4d. Adressbuch / Kontakte & WebDAV/CalDAV-Netzlaufwerk -->
        <section id="kontakte-webdav" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-sky-100 dark:bg-sky-950 text-sky-700 dark:text-sky-300 font-bold">📇</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Adressbuch (CardDAV), Kalender (CalDAV), WebDAV &amp; App-Passwörter</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>📇 Adressbuch &amp; Kontakte (<a href="contacts.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Kontakte</a>):</strong> Pflegen Sie persönliche oder im Team geteilte Kontakte inkl. Favoriten-Stern, Firma, Telefon/Mobil, E-Mail, Adresse und Geburtstag. Importieren und exportieren Sie Kontakte jederzeit im <strong>vCard-Standardformat (<code>.vcf</code>)</strong>. Hinterlegte Geburtstage (<code>🎂</code>) erscheinen automatisch im <a href="calendar.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Kalender</a> und im <a href="dashboard.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Mein-Tag-Cockpit</a>!</li>
                <li><strong>📡 2-Wege-Synchronisation per CardDAV (<code>carddav.php</code>) &amp; CalDAV (<code>caldav.php</code>):</strong> Binden Sie Ihr Adressbuch und Ihren Kalender nativ in iPhone/iPad (iOS), macOS, Mozilla Thunderbird oder Android (<em>DAVx⁵</em>) ein – Änderungen werden in beide Richtungen synchronisiert.</li>
                <li><strong>📡 WebDAV-Netzlaufwerk (<a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen</a>):</strong> Über den integrierten Endpunkt <code>webdav.php</code> binden Sie Ihre Cloud ohne Zusatzsoftware als echtes Netzlaufwerk im Windows Explorer, macOS Finder, unter Linux oder auf dem Smartphone ein.</li>
                <li><strong>🔑 Separate App-Passwörter für Geräte (<a href="settings.php#app-passwords" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen &rarr; App-Passwörter</a>):</strong> Erzeugen Sie für jedes Endgerät (z. B. <em>„iPhone CalDAV“</em>, <em>„Linux Laptop WebDAV“</em>) ein eigenes App-Passwort (<code>mc-xxxx-xxxx-xxxx-xxxx</code>), sehen Sie den Zeitpunkt der letzten Nutzung und widerrufen Sie einzelne Geräte bei Verlust mit 1 Klick, ohne Ihr Hauptpasswort preiszugeben.</li>
            </ul>
        </section>

        <!-- 4e. Mein Tag (Dashboard), Terminfinder/Umfragen, Live-Mentimeter, PDF-Werkzeugkasten & Schlüsselbund -->
        <section id="cockpit-umfragen-passwoerter" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3 md:col-span-2">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-teal-100 dark:bg-teal-950 text-teal-700 dark:text-teal-300 font-bold">🏠</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">„Mein Tag“-Cockpit (⭐), 📊 Terminfinder, 🎤 Live-Mentimeter, 📄 PDF &amp; Scan sowie 🔐 Schlüsselbund</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>🏠 Persönliches Tages-Cockpit (<a href="dashboard.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Mein Tag</a>) mit ⭐ Sternchen-Auswahl:</strong> Zeigt auf einen Blick Ihre heutigen und kommenden Kalendertermine (14 Tage), anstehende Geburtstage aus dem Adressbuch (inkl. Altersberechnung), offene und überfällige Aufgaben (direkt per 1-Klick abhakbar), laufende Termin-Umfragen, Live-Mentimeter, angepinnte Haftnotizen und zuletzt bearbeitete Dateien. Über den Button <strong>„⭐ Inhalte anpassen“</strong> (oder das Sternchen direkt auf jeder Kachel) blenden Sie einzelne Cockpit-Bereiche jederzeit individuell ein oder aus!</li>
                <li><strong>📊 Terminfinder &amp; Abstimmungen (<a href="polls.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Umfragen</a>):</strong> Datenschutzfreundliche „Doodle“-Alternative für Termin-Umfragen (<em>Datum + Uhrzeit</em>) oder freie Auswahl-Abstimmungen mit 3 Stimmoptionen (<code>✅ Ja</code>, <code>🤔 Unter Vorbehalt</code>, <code>❌ Nein</code>). Teilen Sie Umfragen intern mit Cloud-Benutzern/Gruppen oder verschicken Sie den <strong>öffentlichen Gäste-Abstimmungslink</strong> (<code>polls.php?token=...</code>), über den auch externe Gäste ohne eigenes Konto abstimmen können. Die Option mit der höchsten Punktzahl (<code>🏆</code>) lässt sich per <strong>1-Klick direkt als Termin in den Kalender übernehmen</strong>!</li>
                <li><strong>🎤 Live-Mentimeter &amp; Publikums-Voting (<a href="menti.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Mentimeter</a>):</strong> Interaktives Echtzeit-Präsentationswerkzeug für Meetings, Workshops, Schulungen und Vereinsversammlungen. Teilnehmer scannen einfach den <strong>100 % lokal erzeugten QR-Code</strong> oder geben den <strong>6-stelligen PIN-Code</strong> ein (ohne Konto-Pflicht). Unterstützt <strong>☁️ Wortwolken (Word Cloud)</strong>, <strong>📊 Live-Balkendiagramme</strong>, <strong>🎚️ Stimmungs-Skalen (1–10)</strong>, <strong>💬 Offene Antworten</strong>, <strong>🙋 Q&amp;A Publikumsfragen mit 👍 Upvoting</strong> sowie <strong>🏆 Live-Quiz mit Geschwindigkeits-Punkten &amp; Bestenliste</strong>. Schaltet der Moderator am Beamer eine Folie weiter, wechselt die Ansicht auf allen Smartphones im Raum vollautomatisch mit!</li>
                <li><strong>📄 PDF-Werkzeugkasten &amp; Dokumenten-Scanner (<a href="pdf_tools.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">PDF &amp; Scan</a>):</strong>
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>📸 Dokumenten-Scanner (Kamera / Fotos &rarr; PDF):</strong> Fotografieren Sie Briefe, Verträge oder Kassenbons direkt mit der Smartphone-Kamera oder wählen Sie Bilder aus Ihrer Cloud. Der optionale <strong>S/W-Dokumentenfilter (Hoher Kontrast)</strong> entfernt Grauschleier für gestochen scharfe Scans und speichert alle Seiten als mehrseitige DIN-A4-PDF direkt in Ihrem Wunschordner.</li>
                        <li><strong>🔗 PDFs zusammenfügen (Merge), ✂️ Seiten extrahieren (Split) &amp; 🗜️ PDF komprimieren:</strong> Verbinden Sie mehrere PDFs in beliebiger Reihenfolge zu einer Gesamtdatei, lösen Sie gezielt einzelne Seiten (z. B. <code>1-3</code>) heraus oder verkleinern Sie große PDF-Scans per 1-Klick für den E-Mail-Versand.</li>
                        <li><strong>⬛ PDF unwiderruflich schwärzen (DSGVO-Sicher):</strong> Ziehen Sie mit der Maus oder dem Finger schwarze (oder weiße) Balken über sensible Textstellen wie IBANs, Gehälter, Adressen oder Namen. Beim Speichern brennt der Server die Balken physisch in die Seitenpixel ein und entfernt den alten Text-Layer restlos, sodass geschwärzter Text niemals nachträglich per Copy &amp; Paste ausgelesen werden kann!</li>
                    </ul>
                </li>
                <li><strong>🔐 Passwort-Manager &amp; Schlüsselbund (<a href="passwords.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Schlüsselbund</a>):</strong> Speichern Sie Zugangsdaten, WLAN-Schlüssel, Server-Logins, Software-Lizenzen und PINs serverseitig <strong>AES-256-GCM-verschlüsselt</strong> in Ihrer eigenen Cloud. Mit integriertem Passwort-Generator, 1-Klick-Kopieren von Benutzername/Passwort, Passwort-Alterswarnung (&gt; 180 Tage) und optionaler Team-/Gruppen-Freigabe.</li>
                <li><strong>📚 E-Book-Reader &amp; Bibliothek (<a href="ebooks.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">E-Books</a>):</strong> Digitales Bücherregal für alle <code>.epub</code>-Dateien in Ihrer Cloud mit automatischer Cover- &amp; Metadaten-Extraktion, Kapitel-Inhaltsverzeichnis, Volltextsuche im Buch, persönlichen Lesezeichen mit Notizen, 4 Lesedesigns (Papier, Sepia, Schiefer, Nachtmodus) und geräteübergreifend synchronisiertem Lesefortschritt.</li>
                <li><strong>🎵 Musik-, Podcast- &amp; Hörbuch-Studio (<a href="music.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Musik</a>):</strong> Automatische Cloud-Audiothek für alle eigenen und geteilten Audiodateien (<code>.mp3</code>, <code>.m4a</code>, <code>.flac</code>, <code>.wav</code>, <code>.ogg</code>, <code>.aac</code>, <code>.opus</code>, <code>.webm</code>) ohne doppelten Speicherverbrauch. Mit <strong>100 % lokalem ID3v2/v1-Tag- &amp; Cover-Parser</strong>, echtem <strong>HTTP-Range-Streaming (<code>206 Partial Content</code>)</strong>, Live-Frequenz-Visualizer, <strong>4 Klang-Profilen (Equalizer: Neutral, Bass-Boost, Stimme/Hörbuch, Pop)</strong>, speziellem <strong>📖 Hörbuch-Modus</strong> (erkennt automatisch Kapitel-, Track- und CD-Nummerierungen <code>1 &rarr; 99</code> aus ID3-Tags und Dateinamen, schützt vor Zufallswiedergabe und merkt sich das zuletzt gehörte Kapitel samt exakter Sekunde), <strong>🌙 Sleep-Timer</strong>, <strong>📱 Smartphone-Sperrbildschirm-/Auto-Bluetooth-Steuerung</strong> sowie eigenen &amp; per QR-Code teilbaren Playlists.</li>
                <li><strong>🧠 Mind-Map-Editor &amp; Ideensammler (<a href="mindmap.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Mind-Maps</a>):</strong> Interaktive Zeichenfläche für visuelle Mind-Maps mit Tastatur-Steuerung (<code>Tab</code> = Unterknoten, <code>Enter</code> = Geschwisterknoten, <code>Entf</code> = Löschen), Auto-Layout (Radial &amp; Baum), Vorlagen, Team-Freigabe, Export als PNG/SVG/Markdown direkt in die Cloud sowie 1-Klick-Umwandlung von Knoten in Aufgaben oder Haftnotizen.</li>
                <li><strong>💰 Haushaltsplaner &amp; Finanz-Übersicht (<a href="budget.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Haushalt</a>):</strong> Persönliche und geteilte Haushaltsbücher mit Einnahmen &amp; Ausgaben, Kategorien, 6-Monats-Verlauf, Fixkosten-/Abo-Verwaltung (inkl. 1-Klick-Monatsbuchung), Sparzielen, automatischem WG-/Familien-Kostenausgleich (<em>„Wer schuldet wem?“</em>), Beleg-Verknüpfung zu Cloud-Dateien und CSV-Export.</li>
                <li><strong>🏛️ Vereinsverwaltung &amp; Vorstands-Portal (<a href="club.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Verein</a>):</strong> Komplette Vereinszentrale mit Mitgliederdatei, <strong>Multi-Quellen-Import (<code>📤 Importieren</code> aus Excel/CSV, vCard <code>.vcf</code>, Cloud-Adressbuch oder Cloud-Benutzerkonten)</strong>, Beitragstarifen, 1-Klick-Jahresbeitragsgenerator, Kassenwart-Sync in den Haushaltsplaner, Ehrenamts-Arbeitsstunden, Inventar-/Schlüssel-Verleih und feingranularer Aufgabenverteilung auf bis zu 6 Vorstandsrollen.</li>
                <li><strong>🖼️ Foto-Alben &amp; Medien-Galerie (<a href="gallery.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Galerie</a>):</strong> Chronologische Fotowand aller eigenen und geteilten Cloud-Bilder sowie <strong>virtuelle Foto-Alben ohne doppelten Speicherverbrauch</strong>. Mit Vollbild-Lightbox, automatischer Diashow (3s/5s/8s), Tastatursteuerung, lokalem <strong>EXIF-Metadaten-Inspektor</strong> (Kamera, Blende, ISO, Aufnahmedatum, Auflösung) und <strong>öffentlichen Gäste-Sammelalben per QR-Code</strong> (Gäste können auf Festen Fotos direkt vom Smartphone beisteuern).</li>
                <li><strong>📝 Online-Formulare &amp; Veranstaltungs-Anmeldung (<a href="forms.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Formulare</a>):</strong> Selbstgehosteter Formular-Baukasten mit <strong>4 Schnellstart-Vorlagen</strong> (<em>🎉 Vereinsfest-/Event-Anmeldung, 🤝 Helfer- &amp; Schichtliste, 🏛️ Online-Mitgliedsantrag, 💬 Feedback-Bogen</em>), Kapazitätslimit (Max. Plätze), Anmeldeschluss, öffentlichem Gäste-Link samt lokalem SVG-QR-Code, CSV-Ablage in der Cloud (<code>Formulare/</code>) sowie <strong>1-Klick-Übernahme von Online-Mitgliedsanträgen direkt in die Vereinsverwaltung</strong>!</li>
                <li><strong>🎨 Kollaboratives Whiteboard &amp; Skizzenbuch (<a href="whiteboard.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Whiteboard</a>):</strong> Unendliche Zeichenfläche (Pan &amp; Zoom mit Punktraster-, Karo- oder Blanko-Hintergrund) für Freihand-Skizzen, Textmarker, Linien, Pfeile, Rechtecke, Kreise, Rauten, farbige Haftnotizen (Sticky Notes) und Textfelder inkl. 1-Klick-Vorlagen (<em>💡 Brainstorming, 🔀 Flussdiagramm, 📋 Projekt-Board</em>). Im <strong>👥 Team-Board-Modus</strong> zeichnen mehrere Cloud-Mitglieder gleichzeitig in Echtzeit auf demselben Board (inkl. Live-Mauszeiger der Mitzeichner!) und speichern das Ergebnis per Klick als PNG-Bild direkt in <em>„Meine Dateien“</em>.</li>
                <li><strong>🧰 Alltags-Werkzeuge &amp; Toolbox (<a href="toolbox.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Toolbox</a>):</strong> 6-in-1 Schweizer Taschenmesser für Alltag, Familie, Verein &amp; Büro (100 % lokal &amp; werbefrei): <strong>📶 QR-Code, druckfertige Gäste-WLAN-Karten &amp; GiroCode-SEPA-Überweisungs-Studio</strong>, <strong>🎡 animiertes Glücksrad, Team-/Turnier-Auslosung &amp; Würfel</strong>, <strong>⏱️ Präzisions-Stoppuhr mit Rundenzeiten, Küchentimer &amp; Weltzeituhr</strong>, <strong>🔍 zeilenweiser Text-Diff-Checker &amp; Wort-/Lesezeit-Analyse</strong>, <strong>🎨 Farb-Umrechner &amp; WCAG-Kontrast-Prüfer</strong> sowie <strong>🔐 deutsche Wort-Passphrasen, UUID- &amp; Hash-Werkbank</strong> inkl. 1-Klick-Speicherung in der Cloud (<code>Werkzeuge/</code>) oder auf der Notizwand.</li>
                <li><strong>🎬 Video-Studio &amp; Schnittplatz (<a href="video_studio.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Video-Studio</a>):</strong> Kompletter Videoschnittplatz direkt im Browser (100 % lokal ohne Server-Last, voll kompatibel mit STRATO &amp; <code>runready.cloud</code>): <strong>🎞️ Visuelle Filmstreifen-Timeline</strong> zum sekundengenauen Kürzen (Start <code>[In]</code> / Ende <code>[Out]</code>), <strong>🔄 90°-/180°-Drehung &amp; Spiegelung</strong> für falsch herum gefilmte Handyvideos, <strong>📐 Formatwechsel</strong> (<code>Original</code>, <code>16:9 TV</code>, <code>9:16 Reels</code>, <code>1:1</code>, <code>4:3</code>) mit automatischem <strong>✨ Kino-Unschärferand (Blur-Background)</strong>, <strong>🔤 Text-Einblendungen, Bauchbinden &amp; Wasserzeichen</strong> (auch zeitgesteuert von Sekunde X bis Y), <strong>🎨 8 Kino-Farbfilter &amp; Live-Farbkorrektur</strong>, <strong>🔊 Tonspur-Mixer</strong> (Originalton stummschalten, eigene Cloud-MP3 als Hintergrundmusik darunterlegen, Ein-/Ausblenden, Zeitlupe <code>0.5×</code> bis Zeitraffer <code>2.0×</code>), <strong>📸 1-Klick-Standbild-Schnappschuss</strong> als Foto sowie integrierte <strong>📹 Webcam- &amp; 🖥️ Bildschirmaufnahme (Screencast)</strong> mit ausfallsicherer 2-MB-Chunk-Speicherung direkt in die Cloud!</li>
                <li><strong>🍿 Heimkino &amp; Film-Mediathek (<a href="cinema.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Heimkino</a>):</strong> Verwandelt alle eigenen und geteilten Videodateien (<code>.mp4</code>, <code>.webm</code>, <code>.mov</code>, <code>.m4v</code>, <code>.mkv</code>) ohne doppelten Speicherverbrauch in ein privates Streaming-Portal: <strong>🛋️ Kinosaal-Bühne mit Echtzeit-Ambilight-Leuchten</strong> (Live-Farbschein auf Basis des aktuellen Videobildes), <strong>💡 „Saallicht dimmen“ (Taste <kbd class="px-1.5 py-0.5 rounded bg-slate-200 dark:bg-slate-800 text-xs font-mono">L</kbd>)</strong>, <strong>🔊 WebAudio Kino-Sound-Booster</strong> (<em>Neutral, 🔊 Kino-Bass, 🗣️ Dialog-Verstärker, 🌙 Nacht-Modus</em> bis <code>250 %</code>), <strong>⏯️ „Weiterschauen“-Automatik</strong> (merkt sich sekundengenau Ihre letzte Position), <strong>📸 1-Klick-Film-Poster aus dem aktuellen Frame</strong>, <strong>💬 Untertitel-Unterstützung (<code>.srt</code> / <code>.vtt</code>)</strong>, <strong>🔖 Szenen-Sprungmarken</strong> sowie <strong>🎟️ teilbare Filmreihen &amp; passwortgeschützte Gäste-Kino-Tickets</strong> mit flüssigem 4-MB-Range-Streaming (<code>HTTP 206</code>).</li>
                <li><strong>🌐 Neuronales Übersetzungs-Studio &amp; Dokument-Übersetzer (<a href="translate.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Übersetzer</a>):</strong> Übersetzt freie Texte, E-Mails, Verträge und komplette Cloud-Dokumente im vollen Satz- und Absatzkontext (statt bloßer Wort-für-Wort-Ersetzung) zwischen <strong>18 Sprachen</strong> (🇩🇪 DE, 🇬🇧 EN, 🇫🇷 FR, 🇪🇸 ES, 🇮🇹 IT, 🇳🇱 NL, 🇵🇱 PL, 🇵🇹 PT, 🇨🇿 CS, 🇹🇷 TR, 🇺🇦 UK, 🇩🇰 DA, 🇸🇪 SV, 🇬🇷 EL, 🇷🇺 RU, 🇸🇦 AR, 🇨🇳 ZH, 🇯🇵 JA) inkl. automatischer Spracherkennung und Flaggen-Schnellwahl:
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>⚡ 3-Stufen-Engine (Online-NMT, DeepL API &amp; 🔒 100 % Lokal im Intranet):</strong> Läuft sofort ohne API-Key über die schnelle neuronale Standard-Engine (STRATO- &amp; VPS-tauglich), nutzt optional einen im Admin-Bereich hinterlegten <strong>DeepL API-Key (Free <code>:fx</code> oder Pro)</strong> und bietet zusätzlich den Modus <strong>„🔒 100 % Lokal / Intranet (Offline)“</strong> über das lokale Server-Sprachmodell (<code>Ollama gemma2:2b</code> auf <code>127.0.0.1:11434</code> mit abschnittsweiser Live-Anzeige) oder eine lokale <code>LibreTranslate</code>-Instanz – dabei verlässt kein einziges Byte den Server!</li>
                        <li><strong>📄 Direktes Laden von Cloud-Dokumenten (inkl. Scan-OCR) &amp; 💾 1-Klick-Cloud-Export:</strong> Wählen Sie oben rechts (oder im <code>⋯</code>-Aktionsmenü einer Datei in <em>„Meine Dateien“</em> über <strong>🌐 Übersetzen</strong>) ein PDF-Dokument (auch gescannte PDFs dank automatischer Volltext-/OCR-Anbindung!), ein Word-Dokument (<code>.docx</code>) oder eine Text-/Markdown-/Untertitel-Datei (<code>.txt</code>, <code>.md</code>, <code>.csv</code>, <code>.srt</code>) aus. Die fertige Übersetzung speichern Sie per 1-Klick als echtes <strong>Word-Dokument (<code>.docx</code>)</strong>, <strong>Markdown (<code>.md</code>)</strong> oder <strong>Textdatei (<code>.txt</code>)</strong> direkt in Ihrem Wunschordner in der Cloud oder lassen sie per <strong>🔊 Sprachausgabe (TTS)</strong> vorlesen.</li>
                    </ul>
                </li>
                <li><strong>🏄‍♂️ Surftagebuch &amp; Quiver-Log (<a href="surflog.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Surftagebuch</a>):</strong> Persönliches Wassersport-Logbuch für <strong>Windsurfen, Kitesurfen, Wingfoilen, Wellenreiten &amp; SUP</strong> mit <strong>🌬️ Knoten-zu-Beaufort-Automatik (0–12 Bft)</strong>, interaktiver <strong>🧭 16er-Windrose</strong>, Wellenhöhe, Wasser-/Lufttemperatur, Gezeiten, <strong>🛹 Quiver-Materialverwaltung</strong> (berechnet automatisch Einsätze, Wasserstunden und den <em>Preis pro Session</em> Ihrer Boards, Segel, Kites, Wings &amp; Foils), <strong>📍 lokalem GPX-Speed-Tracker</strong> (liest <code>.gpx</code>-Dateien Ihrer GPS-Uhr im Browser aus, berechnet Top-Speed in <code>km/h</code> &amp; <code>kn</code>, Distanz und zeichnet eine farbcodierte Speed-Heatmap), Direktverknüpfung zu Fotos/Videos aus der Cloud sowie <strong>📊 1-Klick-CSV-Export</strong> nach <code>/Surftagebuch</code>.</li>
                <li><strong>🎮 Browserspiele &amp; Pausenraum (<a href="games.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Spiele</a>):</strong> 20 werbefreie HTML5-Spiele mit <strong>⛶ 1-Klick-Vollbild- &amp; Smartphone-Großansicht</strong>, kategorisierter, einklappbarer Spiele-Bibliothek (<em>🕹️ Retro &amp; Arcade, 🏎️ Rennspiel, Sport &amp; RPG, 🧠 Logik &amp; Duell</em>), Live-Suche, <code>⭐</code> Favoriten-Filter und Cloud-Bestenlisten:
                    <ul class="list-disc pl-5 mt-1 space-y-0.5">
                        <li><strong>🏎️ Gummigeruch Kart-Cup:</strong> Kult-Rennspiel mit Drift-Physik, animierten Vorderrädern, Scheinwerfern, Bremslichtern, 3 KI-Gegnern, <strong>wählbarer Rundenzahl (3, 5, 8 oder 11 Runden)</strong>, <strong>6 Strecken</strong> (3 Klassik-Strecken + 3 anspruchsvolle Profi-Kurse: <em>⛰️ Alpen-Serpentinen, 🏜️ Wüsten-Schikane, 🌋 Vulkan-Höllenring</em>), automatischem <strong>👻 Streckenrekord-Ghost</strong> und direkten <strong>Ghost-Duell-Herausforderungen</strong> gegen andere Cloud-Mitglieder!</li>
                        <li><strong>🚘 Cloud-Traffic-Racer:</strong> 4-spurige Highway-Action mit <strong>⚡ Close-Overtake-Kombos</strong> (ab 100 km/h), <strong>🔄 Gegenverkehrs-Modus</strong> (2× Punkte), <strong>🚓 Polizei-Verfolgungsjagd</strong>, <strong>📣 Lichthupe &amp; Hupe</strong> (drängt langsamere Autos von der Überholspur), 3 Wetter-/Tageszeiten (<em>🌃 Nachtfahrt mit Lichtkegeln, ☀️ Sonnen-Highway, 🌧️ Regen</em>) sowie einer Cloud-gespeicherten <strong>🔧 Tuning-Garage mit 5 Fahrzeugen</strong>!</li>
                        <li><strong>🎯 Cloud-Darts (Meister-Arena):</strong> Turnier-Sisal-Dartboard mit <strong>4 Spielmodi</strong> (<em>🏆 501 Double-Out Turnier, ⚡ 301 Straight-Out, 🔥 Highscore-Shootout über 7 Runden / 21 Darts, 🕒 Around the Clock 1–20 + Bull</em>), <strong>🔍 2,5× Ziel-Lupe</strong>, <strong>🧮 Live-Checkout-Berater</strong> (z. B. <code>170 = T20 • T20 • Bull</code>), <strong>3-Dart-Average (Ø)</strong>, Sprachausgabe des <strong>📢 Master-Callers</strong> (<em>„ONE HUNDRED AND EIGHTY!“</em>) sowie Duellen gegen die Kneipen-KI, den Turnier-Profi oder zu zweit!</li>
                        <li><strong>🐸 Teich-Hüpfer:</strong> Der unsterbliche Spielhallen-Klassiker auf 13 Reihen – hüpfe über den 5-spurigen Highway (Trucks, Turbo-Rennwagen, Bulldozer), überquere den reißenden Fluss auf treibenden Baumstämmen und <strong>🐢 Tauch-Schildkröten</strong>, nimm den <strong>🩷 Lady-Frosch</strong> (<code>+200 Pkt.</code>) mit, schnapp dir die <strong>🪰 Bonus-Fliege</strong> (<code>+200 Pkt.</code>) und rette alle 5 Frösche in die Seerosen-Buchten (<code>+1.000 Pkt.</code> Level-Bonus)!</li>
                        <li><strong>🕵️‍♂️ Geheimagent Zero (Operation Bunker-Code):</strong> <em>„Ein Eindringling! Bleib eine Weile… bleib FÜR IMMER!“</em> – Akrobatische 360°-Vorwärts-Salti über patrouillierende Killer-Roboter und schwarze Plasma-Kugeln in 6 unterirdischen Bunker-Sektoren, Aufzugschacht-Navigation, Durchsuchen von Bunker-Terminals nach den <strong>9 Passwort-Lochkarten</strong> (<code>O-M-E-G-A-C-O-D-E</code>) sowie <code>💤 SNOOZE</code>- und <code>🛗 LIFT-INIT</code>-Codes im Agenten-Pocket-Computer!</li>
                        <li><strong>🐹 Cloud-Wuseler:</strong> Echtes zerstörbares Pixel-Gelände in 4 Strategie-Leveln (<em>Grabe einfach!, Schirmchen-Sprung, Die drei Steinsäulen, Durch das Bergmassiv</em>) mit allen 8 Spezial-Fähigkeiten (<strong>🧗 Climber, ☂️ Floater, 💣 Bomber, 🛑 Blocker, 🪜 Builder, 🔨 Basher, ⛏️ Miner, 🕳️ Digger</strong>), <code>2×</code>-Schnellvorlauf und <code>☢️ Nuke</code>-Button!</li>
                        <li><strong>🥷 Schatten-Shinobi:</strong> Das isometrische Ninja-Action-Adventure auf der Insel Kuroshima mit 6 Räumen (inkl. Trittstein-Flussüberquerung per Salto-Sprung und feuerspeiendem Drachenschrein), 5 Waffen (<em>👊 Fäuste, ⚔️ Ninjatō-Schwert, ✴️ Shuriken, ⛓️ Nunchaku, 🦯 Bo-Stab</em>), Rätsel-Artefakten (<em>💎 Feuer-Amulett, 🗝️ Palastschlüssel</em>) und dem Endkampf gegen <strong>👹 Shogun Kuroda</strong>!</li>
                        <li><strong>🏃‍♂️ Dach-Sprinter:</strong> Rasante Ein-Tasten-Flucht mit variabler Sprunghöhe (kurz antippen = flacher Sprung durch Büro-Glasfenster, gedrückt halten = weiter Satz über Häuserschluchten), bebenden &amp; einstürzenden Wolkenkratzern, bremsenden Dachkisten, auffliegenden Taubenschwärmen und Distanz-Wertung in Metern (<code>m</code>).</li>
                        <li><strong>👾 Galaxis-Abwehr:</strong> Der Weltraum-Arcade-Klassiker mit 55 marschierenden Pixel-Aliens (<em>🦑 +30, 🦀 +20, 🐙 +10 Pkt.</em>), die mit jedem Treffer schneller werden, <strong>🛡️ 4 zerstörbaren Schutzbunkern</strong> und dem <strong>🛸 Bonus-UFO</strong>!</li>
                        <li><strong>🦍 Fass-Kletterer:</strong> Der Plattform-Klassiker auf 6 schrägen Stahlträgern – klettere über Leitern, springe über rollende Fässer (<code>+100 Pkt.</code>), schnapp dir den <strong>🔨 Power-Hammer</strong> (<code>+300 Pkt.</code> pro zertrümmertem Fass) und rette die Prinzessin ganz oben vor dem großen Gorilla!</li>
                        <li><strong>🟡 Pillen-Schnapper:</strong> Neon-Labyrinth-Klassiker mit Warp-Tunnel, 4 Geister-KIs (<em>🔴 Rudi, 🩷 Trixi, 🩵 Zack, 🟠 Bumm</em>), Power-Pillen-Jagdmodus (<code>+200</code> bis <code>+1600 Pkt.</code>) und Bonusfrüchten.</li>
                        <li><strong>✈️ Bomben-Flieger:</strong> Der 8-Bit-Ein-Tasten-Klassiker mit bunten Hochhäusern und beleuchteten Retro-Fenstern – wirf im Sinkflug mit der Leertaste Bomben ab (immer 1 gleichzeitig), um alle Türme vor der Landung auf der Rollbahn einzuebnen!</li>
                        <li><strong>⚔️ Helden von MiniCloud (Co-Op RPG), 🧠 Cloud-Sudoku, 💣 Minesweeper, 🃏 Memory, 🧱 Block-Puzzle (Fallblöcke), 🐍 Retro-Snake, 🏓 Brick Breaker</strong> sowie <strong>🔴🟡 Vier Gewinnt &amp; ❌⭕ Tic-Tac-Toe</strong> (gegen Computer-KI oder live gegen andere Cloud-Benutzer).</li>
                    </ul>
                </li>
                <li><strong>🤖 Mehrsprachiger 2-Stufen-Cloud-Assistent (<a href="assistant.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Assistent</a>) &amp; ⊞ Modul-Übersicht (<a href="modules.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Alle Module</a>):</strong>
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>⚡ Stufe 1 (100 % Lokale PHP-&amp;-MySQL-Engine – immer aktiv, auch auf STRATO/Shared-Webhosting):</strong> Beantwortet Fragen zu allen 22 Modulen sowie zum <strong>🛡️ IT-Sicherheitskonzept</strong> und <strong>🗂️ Datenverzeichnis</strong> in <strong>11 Sprachen</strong>, merkt sich den <strong>Gesprächskontext für Anschlussfragen</strong> (z. B. <em>„Und wie teile ich das?“</em>, <em>„Geht das auch mit Passwort?“</em>), durchsucht live Speicherplatz, Kalendertermine, offene Aufgaben, <strong>💰 Haushaltsbuch-Bilanzen</strong>, <strong>🎧 Hörbuch- &amp; 📚 E-Book-Lesestände</strong>, <strong>🏛️ Vereins-Kennzahlen</strong>, <strong>🎂 anstehende Geburtstage</strong>, <strong>🚀 Projekte</strong> sowie den Volltext Ihrer <code>.txt</code>-/<code>.md</code>-Dokumente. Zusätzlich enthält Stufe 1 eingebaute <strong>Alltags-Helfer</strong> (<strong>🧮 Taschen- &amp; Prozentrechner</strong> wie <code>19% von 249,50</code>, <strong>📆 Datums-, Wochentags- &amp; Countdown-Rechner</strong> sowie <strong>🔑 kryptografischen Passwort- &amp; PIN-Generator</strong>) und legt per Schnellbefehl (<code>Notiz: ...</code>, <code>Aufgabe: ...</code> oder <code>Termin: Morgen 15:00 Zahnarzt</code>) sofort Einträge an.</li>
                        <li><strong>🧠 Stufe 2 (Optionales lokales KI-Sprachmodell via Ollama auf <code>127.0.0.1:11434</code> im Dual-Modus):</strong> Auf dedizierten Servern oder vServern (wie <code>runready.cloud</code>) nutzt der Assistent optional ein lokal installiertes Sprachmodell (z. B. <code>gemma2:2b</code>), das sowohl <strong>komplexe Cloud-Fragen im Hybrid-RAG-Verfahren</strong> (Wissensdatenbank + Live-Kontodaten) als auch <strong>🌍 allgemeine Wissens-, Alltags-, Übersetzungs- und Textfragen</strong> (z. B. Einladungen für die Vereinsversammlung entwerfen, Fachbegriffe erklären, Ideen sammeln) beantwortet – zu 100 % lokal auf dem Server ohne externe Cloud-API. Auf Shared-Webhosting schaltet er verzögerungsfrei (&lt; 1 ms) auf Stufe 1 zurück.</li>
                        <li><strong>📄 „Frag deine Datei“ (KI-Dokumenten-Chat &amp; 1-Klick-Zusammenfassung in Office Light):</strong> Wählen Sie im <a href="assistant.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Assistenten</a> über die grüne Leiste <strong>„📄 Frag deine Datei:“</strong> ein beliebiges Cloud-Dokument (<code>.docx</code>, <code>.odt</code>, <code>.doc</code>, <code>.xlsx</code>, <code>.pptx</code>, <code>.txt</code>, <code>.md</code>, <code>.csv</code>, <code>.json</code>, <code>.html</code>) aus oder klicken Sie direkt in <strong>Office Light</strong> oben auf den Button <strong>„🤖 KI-Zusammenfassung“</strong> (bzw. im schwebenden Assistenten-Fenster auf <strong>„📋 Zusammenfassen“</strong> / <strong>„🔑 Kernpunkte“</strong>). Der Assistent liest den Dateiinhalt (inklusive noch ungespeicherter Live-Änderungen im geöffneten Editor!) zu 100 % lokal aus und fasst ihn strukturiert zusammen, extrahiert Fristen/Zahlen oder übersetzt ihn.</li>
                        <li><strong>⊞ Modul-Übersicht:</strong> Über die Vollbild-Seite <strong>⊞ Module</strong> durchsuchen und sortieren Sie alle 22 Cloud-Module und heften Ihre Favoriten per Stern (<code>⭐</code>) direkt in die obere Kopfzeile an.</li>
                    </ul>
                </li>
            </ul>
        </section>
    </div>

    <!-- 4c. TRESOR-MODUS (Einzeldatei-Verschlüsselung AES-256 Zero-Knowledge) -->
    <section id="tresor-modus" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-amber-200 dark:border-amber-800/70 p-5 sm:p-6 shadow-xs mb-6 space-y-4">
        <div class="flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">🔒</span>
            <div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Tresor-Modus – Einzeldatei-Verschlüsselung (AES-256)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Sensible Dokumente mit einem nur Ihnen bekannten Passwort schützen (Zero-Knowledge-Prinzip)</p>
            </div>
        </div>

        <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
            <li><strong>Über das Schloss-Symbol verschlüsseln:</strong> Sie können hochgeladene Dateien jederzeit über das <strong>🔒 Schloss-Symbol</strong> in der Spalte <em>„Aktionen“</em> der Dateiliste mit <strong>AES-256-GCM (PBKDF2-HMAC-SHA256)</strong> und Ihrem persönlichen Tresor-Passwort verschlüsseln.</li>
            <li><strong>Entschlüsseln &amp; Teilen:</strong> Tresor-Dateien sind mit dem Abzeichen <strong>🔒 Tresor</strong> markiert. Beim Herunterladen (auch über geteilte Links oder Freigaben an andere Benutzer) wird das Tresor-Passwort abgefragt. Zudem können Sie den Tresor-Schutz über das Schloss-Symbol jederzeit wieder dauerhaft aufheben.</li>
        </ul>

        <div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-300/80 dark:border-amber-800 text-xs sm:text-sm text-amber-950 dark:text-amber-200 leading-relaxed">
            <strong>⚠️ Wichtiger Hinweis zum Tresor-Passwort (Zero-Knowledge):</strong><br>
            Das von Ihnen gewählte Tresor-Passwort wird <strong>nirgendwo auf dem Server gespeichert</strong> – weder in der Datenbank noch in einer Konfigurationsdatei. Nach dem Verschlüsseln wird der Schlüssel sofort aus dem Arbeitsspeicher verworfen. <strong>Wenn Sie das Tresor-Passwort für eine Datei vergessen, kann niemand – auch nicht der Administrator – die Datei wiederherstellen!</strong>
        </div>
    </section>

    <!-- 5. Papierkorb, Einstellungen & Admin-Hilfe -->
    <div class="grid grid-cols-1 <?= $isAdminUser ? 'md:grid-cols-2' : '' ?> gap-6 mb-8">
        <section id="papierkorb-themes" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-slate-200 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-bold">🗑️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Papierkorb, Farbdesigns, Einstellungen &amp; 2FA (E-Mail / Google Auth)</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Konfigurierbare Papierkorb-Aufbewahrungsfrist (Standard: 30 Tage):</strong> Gelöschte Dateien wandern zunächst in den <a href="trash.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Papierkorb</a>. Dort können sie während der vom Administrator festgelegten Frist (wahlweise 7, 14, 30, 60, 90, 180, 365 Tage oder unbegrenzt) mit einem Klick samt ursprünglicher Ordnerstruktur wiederhergestellt oder sofort endgültig gelöscht werden.</li>
                <li><strong>13 Farbdesigns, 5 Design-Stile (UI-Skins) &amp; 11 Sprachen:</strong> In den <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen</a> (⚙️ Zahnrad-Symbol) wählen Sie zwischen 11 europäischen Sprachen (🇩🇪 DE, 🇬🇧 EN, 🇫🇷 FR, 🇪🇸 ES, 🇮🇹 IT, 🇳🇱 NL, 🇵🇱 PL, 🇵🇹 PT, 🇨🇿 CS, 🇹🇷 TR, 🇺🇦 UK), 13 augenfreundlichen Farbschemata (5 helle und 8 dunkle Designs inkl. <em>🌌 Mitternacht</em>) sowie <strong>5 frei kombinierbaren Design-Stilen / Formensprachen</strong> (<em>✨ Modern Soft, 📐 Enterprise Sharp, 🔮 Glassmorphism, ⬛ Neo-Kontrast, 🫧 Pill &amp; Lounge</em>), die sich auch jederzeit live oben rechts im Benutzermenü umschalten lassen.</li>
                <li><strong>🧾 Kostenaufteilung &amp; Reisekasse (<a href="split.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">split.php</a>):</strong> Legen Sie Projekte, Reisen oder Ausflüge an, fügen Sie sowohl registrierte Cloud-Nutzer als auch beliebig viele externe Personen per Freitext hinzu und erfassen Sie Auslagen (z. B. <em>„Busfahrt“</em>, <em>„Essen am Donnerstag“</em>) samt individuellen Profiteuren (gleichmäßig, nach Anteilen oder mit exakten Beträgen). MiniCloud berechnet daraus automatisch alle Einzelsalden sowie den optimalen Ausgleichsplan (<strong>„Wer schuldet wem wie viel?“</strong>) mit minimalen Überweisungen inklusive 1-Klick-Export in die Cloud oder Zwischenablage.</li>
                <li><strong>Individuelle Komfort- &amp; Datenschutzoptionen:</strong> Legen Sie Ihre persönliche <strong>Anzeigedauer für Statusmeldungen</strong> (1–300 Sek. oder manuell), Ihr <strong>automatisches Sitzungs-Timeout</strong> (5 Min. bis 24 Std. oder dauerhaft), Ihren <strong>Chat-Benachrichtigungsmodus</strong>, Ihre <strong>Teilen-Sichtbarkeit</strong> sowie Ihre <strong>E-Mail-Adresse</strong> fest.</li>
                <li><strong>Zwei-Faktor-Authentifizierung (2FA) – E-Mail oder Google Authenticator:</strong> Unter <a href="settings.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen &rarr; Sicherheit &amp; 2FA</a> stehen Ihnen zwei vollwertige Methoden zur Auswahl:
                    <ul class="list-disc pl-5 mt-1 space-y-1">
                        <li><strong>✉️ Per E-Mail-Code:</strong> Bei jeder Anmeldung wird ein 6-stelliger Einmalcode (10 Min. gültig) an Ihre hinterlegte E-Mail-Adresse gesendet.</li>
                        <li><strong>📱 Per Authenticator-App (Google Authenticator / TOTP):</strong> Wählen Sie <em>„Per Authenticator-App“</em>, scannen Sie den <strong>100 % lokal auf dem Server erzeugten QR-Code</strong> (oder kopieren Sie den Base32-Schlüssel) mit <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong>, <strong>Authy</strong>, <strong>Aegis</strong>, <strong>Bitwarden</strong> oder <strong>Apple Passwörter</strong> und geben Sie zur Aktivierung einmalig den 6-stelligen App-Code ein. Die Codes werden alle 30 Sekunden offline auf Ihrem Smartphone berechnet.</li>
                        <li><strong>Notfall-Fallback beim Login:</strong> Sollte Ihr Smartphone beim Login einmal nicht griffbereit sein und Sie haben eine E-Mail-Adresse hinterlegt, können Sie auf der 2FA-Abfrageseite per Klick auf <em>„✉️ Stattdessen Code per E-Mail senden“</em> einen Einmalcode per E-Mail anfordern.</li>
                    </ul>
                </li>
                <li><strong>🔑 Passwortloses Anmelden per Passkey / Biometrie (Fingerabdruck, FaceID &amp; Windows Hello):</strong> Unter <a href="settings.php#sec-passkeys" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Einstellungen &rarr; Passkeys &amp; Biometrischer Login</a> können Sie Ihr Smartphone, Ihren Laptop oder einen Hardware-Sicherheitsschlüssel (YubiKey) nach dem <strong>W3C-WebAuthn-/FIDO2-Standard</strong> (P-256 / ES256) hinterlegen und sich künftig auf der Anmeldeseite mit 1 Klick per <strong>Fingerabdruck (TouchID / Android)</strong> oder <strong>Gesichtserkennung (FaceID / Windows Hello)</strong> anmelden – 100 % lokal auf Ihrem Server verifiziert ohne externe Cloud-Dienste.</li>
                <li><strong>Vergessenes Passwort zurücksetzen:</strong> Haben Sie eine E-Mail-Adresse hinterlegt, können Sie auf der Anmeldeseite jederzeit über <em>„Passwort vergessen?“</em> (<a href="forgot_password.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Passwort zurücksetzen</a>) einen 30 Minuten gültigen Einmal-Link anfordern.</li>
            </ul>
        </section>

        <?php if ($isAdminUser): ?>
        <section id="admin-hilfe" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs space-y-3">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300 font-bold">⚙️</span>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Hinweise für Administratoren</h2>
            </div>
            <ul class="text-xs sm:text-sm text-slate-600 dark:text-slate-300 space-y-2 list-disc pl-5 leading-relaxed">
                <li><strong>Thematisch geordnete 5-Reiter-Administration:</strong> Die <a href="admin.php" class="text-brand-600 dark:text-brand-400 hover:underline font-semibold">Administration</a> ist übersichtlich in 5 Themenbereiche gegliedert: <strong>👥 Benutzer &amp; Registrierung</strong>, <strong>🧩 Module &amp; Funktionen</strong>, <strong>🎨 Darstellung &amp; Impressum</strong>, <strong>✉️ E-Mail &amp; SMTP</strong> sowie <strong>🖥️ System, Update &amp; Status</strong> (plus Gesamtansicht <em>📋 Alle Bereiche</em>).</li>
                <li><strong>1-Klick-System-Update, Versionsauswahl, Rollback, 📜 Audit-Log &amp; Datenbank-Backup (<code>.sql</code>):</strong> Im Reiter <em>🖥️ System, Update &amp; Status</em> prüft MiniCloud per Klick den aktuellen Commit-Stand auf GitHub und aktualisiert alle Programmdateien vollautomatisch, während <code>uploads/</code> und die Datenbank-Zugangsdaten in <code>config/database.php</code> geschützt bleiben. Vor jedem Update wird automatisch ein <strong>lokaler Sicherungspunkt (Snapshot)</strong> angelegt, sodass Sie ein Update jederzeit per 1-Klick über <strong>„⏪ Letztes Update rückgängig machen“</strong> zurücknehmen oder aus der GitHub-Versionshistorie über <strong>„⏮️ Diesen Stand installieren“</strong> zu jedem beliebigen früheren Stand wechseln können. Darunter finden Sie das <strong>💾 1-Klick Datenbank-Backup (<code>.sql</code>)</strong> aller 15 Tabellen sowie das DSGVO-konforme <strong>📜 Sicherheits- &amp; Aktivitätsprotokoll (Audit-Log)</strong> mit Live-Filter.</li>
                <li><strong>Einzelne Module freigeben/abschalten, 2-Stufen-Assistent &amp; Intranet-Übersetzer (Ollama), Nutzungsstatistik &amp; Branding:</strong> Im Reiter <em>🧩 Module &amp; Funktionen</em> können Sie jedes der 24 Cloud-Module (inkl. Übersetzungs-Studio, Heimkino, Surftagebuch, Video-Studio, Office Light mit Foto-Studio, Taschenrechner &amp; Rechen-Studio, Musik- &amp; Hörbuch-Studio, E-Books, Mind-Map-Editor, Haushaltsplaner, Vereinsverwaltung, Foto-Galerie, Online-Formulare, Spiele-Zentrale, Cloud-Assistent, Projekte, PDF &amp; OCR, Kalender/CalDAV, Kontakte/CardDAV, WebDAV u. v. m.) einzeln aktivieren oder deaktivieren sowie die anonymisierte <strong>📊 Modul-Nutzungsstatistik</strong> einsehen. Direkt bei <strong>Modul 14 (Cloud-Assistent)</strong> und beim <strong>🌐 Übersetzungs-Studio</strong> sehen Sie außerdem den Live-Status für das <strong>lokale KI-Sprachmodell via Ollama auf <code>127.0.0.1:11434</code> (z. B. <code>gemma2:2b</code>)</strong> sowie optionale Einstellungen für DeepL API-Key und lokale Intranet-LibreTranslate-Server (auf Shared-Webhosting/STRATO nutzt MiniCloud automatisch und verzögerungsfrei Stufe 1 bzw. die direkte NMT-Engine).</li>
                <li><strong>Benutzer sperren (🔒), Teil-Admin-Rechte übertragen (🛡️), Quotas &amp; 2FA-Verwaltung:</strong> Verwalten Sie Benutzerkonten und Speicher-Quotas (in MB). Einzelne Benutzer können jederzeit per <strong>„🔒 Sperren“</strong> (inkl. optionalem Sperrgrund) mit sofortiger Sitzungsbeendigung gesperrt und per <strong>„🔓 Entsperren“</strong> wieder freigegeben werden. Über das Menü <strong>„🛡️ Teil-Rechte“</strong> können Voll-Administratoren einzelne Verwaltungsbereiche (<em>Benutzer, Module, Branding, E-Mail, System</em>) gezielt an vertrauenswürdige Benutzer delegieren.</li>
            </ul>
        </section>
        <?php endif; ?>
    </div>

    <!-- 6. IT-SICHERHEITSKONZEPT (inkl. Ergebnis des Sicherheitsaudits) -->
    <section id="it-sicherheit" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-5">
        <div class="flex flex-wrap items-center justify-between gap-2 pb-4 border-b border-slate-200 dark:border-slate-800">
            <div class="flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-bold text-lg">🛡️</span>
                <div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">IT-Sicherheitskonzept</h2>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Technische und organisatorische Schutzmaßnahmen (TOM) sowie Ergebnisse der Sicherheitsprüfung</p>
                </div>
            </div>
            <span class="text-xs font-semibold px-3 py-1 rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-300">
                Stand: <?= e($dateStr) ?> &bull; Geprüft &amp; Gehärtet
            </span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs sm:text-sm">
            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">1. Authentifizierung, 🔑 Passkeys (WebAuthn), 2FA (E-Mail &amp; TOTP) &amp; Brute-Force-Schutz</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Geschlossenes Benutzersystem (Selbstregistrierung standardmäßig deaktiviert und optional nur per Einladungs-Code). Passwörter werden ausschließlich mittels <strong>BCRYPT (Cost-Faktor 12)</strong> gehasht. Benutzer können wahlweise <strong>🔑 WebAuthn-Passkeys (Fingerabdruck, FaceID, Windows Hello, YubiKey mit asymmetrischer P-256/SHA-256-Signaturprüfung, Origin-/RP-ID-Prüfung und Signaturzähler-Klon-Erkennung)</strong> sowie eine <strong>2FA per E-Mail-Code</strong> oder per <strong>Authenticator-App (Google Authenticator / TOTP nach RFC 6238 mit 100 % lokaler QR-Code-Erzeugung)</strong> aktivieren. Nach <strong>5 Fehlversuchen innerhalb von 5 Minuten</strong> greift eine automatische temporäre Sperre.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">2. Session-, Timeout- &amp; Zugriffsschutz (RBAC)</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Sessions nutzen <code>session.use_strict_mode</code>, <code>HttpOnly</code>, <code>SameSite=Lax</code>, bei HTTPS <code>Secure</code> sowie ein konfigurierbares Inaktivitäts-Timeout (Standard: 30 Min.). Nach erfolgreichem Login erfolgt <code>session_regenerate_id(true)</code> gegen Session-Fixation. Bei jedem Seitenaufruf gleicht das System Benutzerexistenz und Administrator-Status in Echtzeit mit der Datenbank ab.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">3. Dateispeicher-Isolierung, Office Light &amp; Tresor-Verschlüsselung</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Hochgeladene Dateien werden niemals unter ihrem Originalnamen im Dateisystem abgelegt, sondern erhalten einen kryptografisch zufälligen Dateinamen mit der nicht ausführbaren Endung <code>.bin</code> (z. B. <code>mc_..._a1b2c3d4.bin</code>) und restriktiven Dateirechten (<code>0640</code>). Das Verzeichnis <code>uploads/</code> ist per <code>.htaccess</code> gegen direkten Web-Zugriff gesperrt. Die Verarbeitung von Word-/Excel-Dokumenten in <strong>MiniCloud Office Light</strong> erfolgt zu 100 % lokal auf dem Server (mit <code>LIBXML_NONET</code> gegen XXE) ohne externe Cloud-Anbieter. Zusätzlich können Dateien mit <strong>AES-256-GCM (Zero-Knowledge)</strong> verschlüsselt werden.
                </p>
            </div>

            <div class="p-4 rounded-xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-700/80 space-y-1.5">
                <h3 class="font-bold text-slate-900 dark:text-white">4. Schutz vor SQLi, XSS, CSRF &amp; Path-Traversal</h3>
                <p class="text-slate-600 dark:text-slate-300 leading-relaxed">
                    Alle Datenbankabfragen nutzen echte <strong>PDO Prepared Statements</strong> (<code>ATTR_EMULATE_PREPARES = false</code>). Sämtliche Ausgaben werden per <code>htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')</code> bzw. <code>JSON_HEX_TAG</code> maskiert. Jede zustandsändernde Aktion erfordert einen <code>POST</code>-Request mit kryptografischem 256-Bit-CSRF-Token (<code>hash_equals</code>). Pfadangaben und ZIP-Update-Archive werden strikt gegen <code>..</code> und SQL-Wildcards (<code>%</code>) bereinigt.
                </p>
            </div>
        </div>

        <!-- Ergebnisse des Sicherheitsaudits -->
        <div class="p-4 sm:p-5 rounded-xl bg-emerald-50/70 dark:bg-emerald-950/30 border border-emerald-200/80 dark:border-emerald-800/60">
            <h3 class="text-sm sm:text-base font-bold text-emerald-950 dark:text-emerald-200 mb-2">
                Ergebnisse der durchgeführten Sicherheitsüberprüfung &amp; Härtung
            </h3>
            <ul class="text-xs sm:text-sm text-emerald-900 dark:text-emerald-200/90 space-y-1.5 list-disc pl-5">
                <li><strong>Script-Kontext- &amp; DOM-XSS-Härtung:</strong> JSON-Serialisierung in Inline-Skripten (Dateivorschau, Chat-Kanal, CSRF-Tokens) mit <code>JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT</code> sowie vollständige HTML-Maskierung (<code>escapeHtml</code>) im Teilen-Dialog, Chat-Polling und GitHub-Updater.</li>
                <li><strong>Datenschutz-Sichtbarkeit &amp; 3-Stufen-Rechteprüfung:</strong> Strikte serverseitige Prüfung von <code>allow_user_sharing = 1</code> sowie granulare Berechtigungsprüfung (<code>can_write</code>, <code>can_delete</code>, <code>can_edit</code>) für Datei-, Ordner-, Office-Light- und Kalender-Freigaben.</li>
                <li><strong>Abgesicherter GitHub-Updater, Lokale Snapshots &amp; Rollback:</strong> Zertifikatsvalidierung (<code>CURLOPT_SSL_VERIFYPEER</code> / <code>CURLOPT_SSL_VERIFYHOST = 2</code>), Schutz vor Zip-Slip (<code>..</code>-Filter), automatische lokale Sicherungspunkte (<code>uploads/.system_backups/</code> mit <code>.htaccess</code>-Sperre) vor jedem Update sowie automatischer Erhalt von <code>uploads/</code> und <code>config/database.php</code>-Zugangsdaten.</li>
                <li><strong>Host-Header-, Open-Redirect- &amp; Forced-Logout-Schutz:</strong> Strikte Regex-Validierung von <code>HTTP_HOST</code>, Whitelist-Prüfung lokaler Zielseiten und CSRF-geschützter <code>POST</code>-Logout.</li>
                <li><strong>Brute-Force-Sperre, Kontosperrung, Teil-Admin-RBAC, WebDAV/CardDAV/CalDAV-Härtung &amp; SVG-Sanitisierung:</strong> Ratenbegrenzung für Login, Registrierung, Freigabelinks, passwortgeschützte Foto-Alben &amp; Musik-Playlists, Mentimeter-PINs sowie die nativen <code>webdav.php</code>-, <code>carddav.php</code>- und <code>caldav.php</code>-Endpunkte, durchgängige Prüfung von Kontosperren (<code>is_locked</code>) über Web-Login, aktive Sitzungen, Passwort-Reset, WebDAV/CardDAV/CalDAV und Abo-Feeds, feingranulare Prüfung delegierter Teil-Admin-Rechte (<code>admin_permissions</code>), IDOR-Schutz bei Beleg-Verknüpfungen im Haushaltsplaner sowie bei Audio-/Sprachnotiz-Streams und Metadaten-Aktionen (<code>music.php</code>, <code>notes.php</code>) sowie Abwehr aktiver Skriptelemente in SVG-Logos und Mind-Map-Exporten.</li>
                <li><strong>Datenschutzkonforme 2-Stufen-KI-Architektur &amp; 100 % Intranet-Übersetzung (Loopback-Isolierung &amp; SSRF-Schutz):</strong> Der Cloud-Assistent (<code>assistant.php</code>) verarbeitet sämtliche Live-Kontodaten und das Sitzungs-Gesprächsgedächtnis ausschließlich lokal. Das optionale Stufe-2-Sprachmodell (Ollama) ist per Systemd-Override strikt an die lokale Loopback-Schnittstelle <code>127.0.0.1:11434</code> gebunden (von außen nicht erreichbar) und steht auch im <strong>Übersetzungs-Studio (<code>translate.php</code>)</strong> als <strong>🔒 100 % Lokaler Intranet-/Offline-Modus</strong> bereit, bei dem kein einziges Byte den Server verlässt. Tresor-verschlüsselte Dateien (<code>is_encrypted = 1</code>) sind vom Auslesen im Übersetzer strikt ausgeschlossen; sämtliche HTTP-Übersetzungsaufrufe unterbinden offene Weiterleitungen (<code>CURLOPT_FOLLOWLOCATION = false</code>) und blockieren Cloud-Metadaten-IPs sowie interne Nicht-HTTP-Systemports gegen SSRF.</li>
                <li><strong>Blockadefreies High-Speed-Medien-Streaming (<code>session_write_close</code> &amp; 4-MB-Range-Chunks), Chunk-Upload-Härtung &amp; CSV-Formelschutz:</strong> Alle Datei-, Musik-, Heimkino-, Thumbnail- und Freigabe-Streams (<code>download.php</code>, <code>thumbnail.php</code>, <code>gallery.php</code>, <code>cinema.php</code>, <code>music.php</code>, <code>share.php</code>) geben die PHP-Sitzungssperre vor dem Senden der Binärdaten sofort frei (<code>session_write_close()</code>) und begrenzen offene Video-/Audio-Range-Abrufe (<code>HTTP 206 Partial Content</code>) auf 4-MB-Pakete, sodass paralleles Vor-/Zurückspulen und Seitenwechsel niemals PHP-Worker blockieren. Das Video-Studio (<code>video_studio.php</code>) prüft bei jedem 2-MB-Chunk strikt das Speicherkontingent und bereinigt verwaiste <code>.part</code>-Dateien automatisch; CSV-Exporte (<code>surflog.php</code>) sind gegen CSV-Formel-Injection (<code>=</code>, <code>+</code>, <code>-</code>, <code>@</code>) gehärtet.</li>
                <li><strong>Serverseitiger Thumbnail-Cache mit Zero-Knowledge-Tresor-Bereinigung, Schema-Version-Caching &amp; Batch-SQL-Optimierung:</strong> Vorschaubilder (<code>uploads/.thumbs/</code>) sind per <code>.htaccess</code> und <code>index.php</code> gegen Direktzugriff geschützt, durchlaufen in <code>thumbnail.php</code> dieselbe 3-stufige RBAC-/Token-Prüfung wie Originaldateien und werden beim Verschlüsseln einer Datei im <strong>🔒 Tresor</strong> (zusammen mit dem <code>file_hash</code>) sowie beim endgültigen Löschen sofort physisch vom Datenträger entfernt (<code>cleanupFileThumbnails</code>). Datenbank-Migrationen werden über <code>schema_version</code> gecacht und korrelierte Datei-Subqueries durch gebündelte <code>GROUP BY</code>-Abfragen nach der Seitenbegrenzung (Pagination) ersetzt.</li>
            </ul>
        </div>
    </section>

    <!-- 7. DATENVERZEICHNIS -->
    <section id="datenverzeichnis" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-7 shadow-xs mb-8 space-y-4">
        <div class="flex items-center gap-2.5 pb-4 border-b border-slate-200 dark:border-slate-800">
            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-bold text-lg">🗂️</span>
            <div>
                <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white">Datenverzeichnis (Data Dictionary &amp; Bestandsübersicht)</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400">Übersicht aller verarbeiteten Datenbestände, Datenbanktabellen, Speicherorte und Löschfristen</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs sm:text-sm">
                <thead>
                    <tr class="bg-slate-50/80 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        <th class="py-3 px-4">Tabelle / Speicherort</th>
                        <th class="py-3 px-4">Wesentliche Felder / Inhalte</th>
                        <th class="py-3 px-4">Zweck &amp; Personenbezug</th>
                        <th class="py-3 px-4">Aufbewahrung / Löschung</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200/80 dark:divide-slate-800 text-slate-700 dark:text-slate-300">
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">users</td>
                        <td class="py-3 px-4"><code>id, username, email, password_hash, two_factor_enabled, totp_secret, reset_token_hash, reset_expires_at, is_admin, is_locked, lock_reason, admin_permissions, theme, session_timeout, flash_duration, chat_notify_mode, allow_user_sharing, calendar_token, contacts_token, storage_quota, created_at</code></td>
                        <td class="py-3 px-4">Benutzerauthentifizierung, optionale 2FA (E-Mail-Code oder Google Authenticator / TOTP), Passwort-Wiederherstellung, Kontosperrung (<code>is_locked</code>, <code>lock_reason</code>), delegierte Teil-Admin-Rechte (<code>admin_permissions</code>), Kontingent, Kalender-/Adressbuch-Abo-Tokens (<code>calendar_token</code>, <code>contacts_token</code>), UI-Präferenzen &amp; Datenschutz-Sichtbarkeit (personenbezogen)</td>
                        <td class="py-3 px-4">Bis zur Löschung des Benutzerkontos durch den Administrator (Kaskadenlöschung)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">files</td>
                        <td class="py-3 px-4"><code>id, user_id, filename, original_name, folder_path, file_size, file_hash, mime_type, is_encrypted, is_favorite, upload_date, deleted_at</code></td>
                        <td class="py-3 px-4">Metadaten hochgeladener Dateien, SHA-256-Inhalts-Hash zur Duplikaterkennung (<code>file_hash</code>, bei Tresor-Dateien <code>NULL</code>), Favoriten-Markierung (<code>is_favorite</code>) &amp; Zuordnung zu Benutzern und Ordnerpfaden</td>
                        <td class="py-3 px-4">Aktiv bis zum Löschen; im Papierkorb (<code>deleted_at</code>) automatische Endlöschung nach Ablauf von <code>trash_retention_days</code> (Standard: 30 Tage)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">user_folders</td>
                        <td class="py-3 px-4"><code>id, user_id, folder_path, created_at</code></td>
                        <td class="py-3 px-4">Verzeichnisstruktur (inkl. leerer Ordner) in der persönlichen Cloud</td>
                        <td class="py-3 px-4">Bis zum Löschen/Verschieben des Ordners oder Löschung des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">groups &amp; group_members</td>
                        <td class="py-3 px-4"><code>id, name, description</code> / <code>group_id, user_id</code></td>
                        <td class="py-3 px-4">Benutzergruppen und Mitgliedschaftszuordnung (z. B. für gemeinsame Kalender-Termine &amp; Kalender-Freigaben)</td>
                        <td class="py-3 px-4">Bis zum Entfernen der Mitgliedschaft oder Löschen der Gruppe / des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">shares</td>
                        <td class="py-3 px-4"><code>id, file_id, folder_path, user_id, share_token, share_mode, password_hash, expires_at, max_downloads, download_count, created_at</code></td>
                        <td class="py-3 px-4">Öffentliche Freigabelinks (Einzeldatei oder Ordner im Modus <code>read</code>, <code>upload_only</code> / Digitaler Briefkasten oder <code>read_write</code>) inkl. optionalem BCRYPT-Passwort, Ablaufdatum &amp; Selbstzerstörungs-Zähler (<code>max_downloads</code>)</td>
                        <td class="py-3 px-4">Bis zum manuellen Widerruf, Erreichen von <code>max_downloads</code>, Ablauf (<code>expires_at</code>) oder Löschen der Datei / des Ordners</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">user_shares</td>
                        <td class="py-3 px-4"><code>id, file_id, folder_path, owner_id, shared_with_user_id, can_write, can_delete, created_at</code></td>
                        <td class="py-3 px-4">Interne Direktfreigaben von Dateien oder Ordnern zwischen Cloud-Benutzern mit 3-stufigen Rechten (Nur Lesen, Schreiben, Löschen)</td>
                        <td class="py-3 px-4">Bis zum Widerruf durch den Eigentümer oder Löschung der Datei / des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">calendar_events &amp; calendar_shares</td>
                        <td class="py-3 px-4"><code>user_id, title, start_at, end_at, all_day, recurrence, group_id, shared_user_id, attached_file_id, is_private</code> / <code>owner_user_id, target_user_id, target_group_id, can_edit, can_delete</code></td>
                        <td class="py-3 px-4">Persönliche und geteilte Kalendertermine, Serien-Wiederholungen sowie 3-stufige Kalender-Freigaben an Benutzer oder Gruppen</td>
                        <td class="py-3 px-4">Bis zur Löschung des Termins / Widerruf der Kalender-Freigabe oder Löschung des Benutzers</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">messages &amp; message_broadcast_reads</td>
                        <td class="py-3 px-4"><code>id, sender_id, recipient_id, is_broadcast, message_text, attached_file_id, is_read, created_at</code> / <code>user_id, last_read_broadcast_id</code></td>
                        <td class="py-3 px-4">Direkte 1:1-Chat-Nachrichten (inkl. optionaler Dateianhänge <code>attached_file_id</code>) sowie offizielle Admin-Rundnachrichten samt Lesestatus</td>
                        <td class="py-3 px-4">Bis zur Einzellöschung, Leerung des Chatverlaufs (<em>„Verlauf leeren“</em>) oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">file_versions &amp; file_comments</td>
                        <td class="py-3 px-4"><code>file_id, user_id, stored_name, file_size, version_note, created_at</code> / <code>file_id, user_id, comment_text, created_at</code></td>
                        <td class="py-3 px-4">Automatische Dateiversionierung (bis zu 5 Stände pro Datei für 1-Klick-Zeitreise) sowie Kommentare &amp; Notizen direkt an Dateien</td>
                        <td class="py-3 px-4">Automatische Rotation älterer Versionen ab 5 Ständen; Endlöschung zusammen mit der Hauptdatei</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">kanban_boards, tasks, notes &amp; contacts</td>
                        <td class="py-3 px-4"><code>title, icon, color, share_token, share_mode</code> / <code>board_id, title, status, priority, checklist_json</code> / <code>title, content, color, tags, is_pinned, audio_file_id, audio_duration_sec</code> / <code>first_name, last_name, email, phone, birthday, vcard_uid</code></td>
                        <td class="py-3 px-4">Multi-Projekt-Kanban-Boards &amp; Aufgaben (inkl. Ganzes-Board-Freigabe), farbige Haftnotizen inkl. 🎙️ Mikrofon-Sprachnotizen &amp; Diktat (<code>notes.php</code>) sowie persönliches/geteiltes Adressbuch (inkl. CardDAV-Server <code>carddav.php</code>, <code>.vcf</code>-Abo-Feed, vCard Import/Export &amp; Geburtstags-Synchronisation in den Kalender)</td>
                        <td class="py-3 px-4">Bis zur manuellen Löschung durch den Eigentümer oder Löschung des Benutzerkontos</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">polls, poll_options &amp; poll_votes</td>
                        <td class="py-3 px-4"><code>title, poll_type, public_token, is_closed</code> / <code>label_text, option_date, option_time</code> / <code>voter_user_id, voter_name, vote_value</code></td>
                        <td class="py-3 px-4">Terminfinder &amp; Abstimmungen (interne Benutzer/Gruppen sowie optionaler öffentlicher Gäste-Abstimmungslink <code>public_token</code>)</td>
                        <td class="py-3 px-4">Bis zur Löschung der Umfrage durch den Ersteller oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">menti_sessions, menti_slides &amp; menti_responses</td>
                        <td class="py-3 px-4"><code>title, pin_code, public_token, active_slide_id, show_results, voting_locked</code> / <code>slide_type, question, options_json, correct_option</code> / <code>participant_token, participant_name, answer_value, points, upvotes</code></td>
                        <td class="py-3 px-4">Interaktive Live-Mentimeter-Präsentationen (<code>menti.php</code>) mit Wortwolken, Balkendiagrammen, Skalen, Q&amp;A-Upvoting und Live-Quiz</td>
                        <td class="py-3 px-4">Bis zum Zurücksetzen der Antworten oder Löschen der Präsentation durch den Ersteller</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">passwords, app_passwords &amp; user_passkeys</td>
                        <td class="py-3 px-4"><code>title, category, username_enc, password_enc, notes_enc</code> / <code>label, password_hash, token_prefix, last_used_at, last_used_service</code> / <code>user_id, credential_id, public_key_pem, cose_alg, device_name, sign_count, last_used_at</code></td>
                        <td class="py-3 px-4">AES-256-GCM-verschlüsselter Passwort-Schlüsselbund, separate pro Gerät widerrufbare App-Passwörter (BCRYPT-Hash) für WebDAV/CardDAV/CalDAV sowie öffentliche WebAuthn-Schlüssel (<code>user_passkeys</code>) für den passwortlosen Login per Fingerabdruck/FaceID</td>
                        <td class="py-3 px-4">Bis zum Widerruf / Löschen durch den Benutzer oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">whiteboards &amp; whiteboard_peers</td>
                        <td class="py-3 px-4"><code>id, user_id, title, is_shared, bg_style, elements_json, updated_at</code> / <code>board_id, user_id, username, cursor_x, cursor_y, updated_at</code></td>
                        <td class="py-3 px-4">Kollaboratives Whiteboard &amp; Skizzenbuch (<code>whiteboard.php</code>): Vektor-Zeichenelemente, Haftnotizen, Formen sowie kurzlebige Live-Mauszeiger-Positionen für das gemeinsame Echtzeit-Zeichnen im Team</td>
                        <td class="py-3 px-4">Boards bis zum Löschen durch den Eigentümer; Live-Mauszeiger (<code>whiteboard_peers</code>) verfallen automatisch nach 12 Sekunden Inaktivität</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">ebook_progress, ebook_bookmarks &amp; ebook_metadata_cache</td>
                        <td class="py-3 px-4"><code>user_id, file_id, chapter_index, scroll_pct, progress_pct, reader_prefs</code> / <code>chapter_title, note</code> / <code>title, author, cover_data_uri</code></td>
                        <td class="py-3 px-4">Synchronisierter Lesefortschritt, persönliche Lesezeichen und lokaler Cover-/Metadaten-Cache für den E-Book-Reader (<code>ebooks.php</code>)</td>
                        <td class="py-3 px-4">Bis zum Löschen des E-Books / Lesezeichens oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">mindmaps</td>
                        <td class="py-3 px-4"><code>id, user_id, title, description, map_json, shared_users, created_at, updated_at</code></td>
                        <td class="py-3 px-4">Interaktive Mind-Maps (Knotenstruktur, Farben, Notizen, Layout) inkl. optionaler Team-Freigabe (<code>mindmap.php</code>)</td>
                        <td class="py-3 px-4">Bis zum Löschen der Mind-Map durch den Eigentümer oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">budget_books, budget_entries, budget_subscriptions &amp; budget_goals</td>
                        <td class="py-3 px-4"><code>title, currency, monthly_limit, shared_users</code> / <code>type, category, amount, entry_date, is_shared_split, file_id</code> / <code>billing_cycle, day_of_month</code> / <code>target_amount, current_amount</code></td>
                        <td class="py-3 px-4">Persönliche und geteilte Haushaltsbücher, Einnahmen/Ausgaben, Fixkosten/Abos, Sparziele und Kostenausgleich (<code>budget.php</code>)</td>
                        <td class="py-3 px-4">Bis zum Löschen der Buchung / des Haushaltsbuchs oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">clubs, club_roles, club_members, club_fee_tiers, club_fee_payments, club_work_logs, club_inventory &amp; club_documents</td>
                        <td class="py-3 px-4"><code>name, register_no, required_work_hours, budget_book_id</code> / <code>user_id, role_title, permissions</code> / <code>member_no, first_name, last_name, status, department, fee_tier_id, iban, sepa_mandate_ref</code> / <code>period_year, amount, status</code> / <code>hours, activity</code> / <code>item_name, assigned_member_id</code> / <code>title, doc_category, file_id</code></td>
                        <td class="py-3 px-4">Vereinsverwaltung &amp; Vorstands-Portal (<code>club.php</code>) mit feingranularer Vorstands-Rollenverteilung, Mitgliederdatei (inkl. Multi-Quellen-Import), Beitragstarifen &amp; Jahresbeitragslauf, Kassenwart-Sync in <code>budget.php</code>, Ehrenamts-Arbeitsstunden, Inventar-/Schlüssel-Verleih und Protokoll-Archiv</td>
                        <td class="py-3 px-4">Bis zur Löschung des Eintrags / Vereins durch den Vorstand oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">gallery_albums &amp; gallery_album_items</td>
                        <td class="py-3 px-4"><code>title, description, cover_file_id, event_date, shared_user_id, group_id, share_token, password_hash, allow_guest_upload</code> / <code>album_id, file_id, caption, added_by_name, sort_order</code></td>
                        <td class="py-3 px-4">Foto-Alben &amp; Medien-Galerie (<code>gallery.php</code>): Virtuelle Foto-Alben ohne physische Dateiduplizierung, Bildunterschriften sowie öffentliche Gäste-Alben &amp; Party-/Vereins-Sammelalben per QR-Code</td>
                        <td class="py-3 px-4">Bis zum Löschen des Albums / der Zuordnung oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">forms &amp; form_submissions</td>
                        <td class="py-3 px-4"><code>title, category, fields_json, share_token, allow_guests, max_submissions, closes_at, is_closed</code> / <code>form_id, submitter_name, submitter_email, answers_json, imported_to_club</code></td>
                        <td class="py-3 px-4">Online-Formulare &amp; Veranstaltungs-Anmeldung (<code>forms.php</code>): Event-Anmeldungen, Helfer-/Schichtlisten, Online-Mitgliedsanträge und Feedback-Bögen inkl. 1-Klick-Übernahme in <code>club.php</code></td>
                        <td class="py-3 px-4">Bis zur Einzellöschung einer Rückmeldung, Löschung des Formulars oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">music_playlists, music_playlist_items &amp; music_track_meta</td>
                        <td class="py-3 px-4"><code>title, description, shared_user_id, share_token, password_hash</code> / <code>playlist_id, file_id, sort_order</code> / <code>file_id, cached_title, cached_artist, cached_album, cached_track_no, cached_disc_no, cached_duration_sec, has_cover, is_favorite, last_position_sec, play_count</code></td>
                        <td class="py-3 px-4">Musik-, Podcast- &amp; Hörbuch-Studio (<code>music.php</code>): Virtuelle Playlists (inkl. interner Freigabe &amp; öffentlichem Link), lokaler ID3-Tag-, Kapitelnummern- (<code>1 &rarr; 99</code>) &amp; Cover-Cache sowie persönlicher Hörbuch-Positionsmerker (<code>last_position_sec</code>)</td>
                        <td class="py-3 px-4">Bis zum Löschen der Playlist / Audiodatei oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">cinema_media_meta, cinema_user_state, cinema_playlists &amp; cinema_playlist_items</td>
                        <td class="py-3 px-4"><code>file_id, custom_title, category, release_year, poster_data_uri, subtitle_file_id, bookmarks_json</code> / <code>user_id, last_position_sec, is_watchlist, is_favorite, play_count</code> / <code>title, share_token, password_hash</code></td>
                        <td class="py-3 px-4">Heimkino &amp; Film-Mediathek (<code>cinema.php</code>): Film-Poster, Szenen-Sprungmarken, sekundengenaue „Weiterschauen“-Position je Benutzer, Watchlist sowie Filmreihen/Gäste-Kino-Tickets</td>
                        <td class="py-3 px-4">Bis zum Löschen der Videodatei / Filmreihe oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">surf_gear &amp; surf_sessions</td>
                        <td class="py-3 px-4"><code>user_id, category, brand, model, size_label, purchase_price</code> / <code>session_date, sport_type, spot_name, wind_kn_min, wind_kn_max, wind_bft, wind_dir, board_gear_id, rig_gear_id, max_speed_kmh, gpx_summary_json, media_file_id</code></td>
                        <td class="py-3 px-4">Surftagebuch &amp; Quiver-Materialverwaltung (<code>surflog.php</code>): Wassersport-Sessions, Spot-/Wind-/Wellen-Logbuch, Quiver-Ausrüstung (inkl. Kosten/Session) und lokale GPX-Track-Zusammenfassung</td>
                        <td class="py-3 px-4">Bis zum Löschen der Session / des Ausrüstungs-Eintrags oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">game_scores, game_saves &amp; game_matches</td>
                        <td class="py-3 px-4"><code>user_id, game_key, score, duration_sec</code> / <code>state_json</code> / <code>game_type, player1_id, player2_id, board_json, winner_id</code></td>
                        <td class="py-3 px-4">Highscore-Bestenlisten, gespeicherte Einzelspieler-Spielstände und Live-Mehrspieler-Duelle in der Spiele-Zentrale (<code>games.php</code>)</td>
                        <td class="py-3 px-4">Bis zum Zurücksetzen des Spielstands oder Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">module_usage_stats</td>
                        <td class="py-3 px-4"><code>module_key, stat_date, hit_count, last_used_at</code></td>
                        <td class="py-3 px-4">Rein aggregierte, anonyme Nutzungszähler je Cloud-Modul und Kalendertag ohne Benutzerbezug</td>
                        <td class="py-3 px-4">Jederzeit per 1-Klick im Admin-Bereich zurücksetzbar</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">audit_logs</td>
                        <td class="py-3 px-4"><code>id, user_id, username, event_type, description, ip_address, created_at</code></td>
                        <td class="py-3 px-4">DSGVO-konformes Sicherheits- &amp; Aktivitätsprotokoll (Logins, Freigaben, Selbstzerstörung, ZIP-Entpackung, Backups) mit anonymisierter IP-Adresse (letztes Oktett maskiert)</td>
                        <td class="py-3 px-4">Automatische Bereinigung nach 90 Tagen oder jederzeit per 1-Klick im Admin-Bereich leeren</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">settings</td>
                        <td class="py-3 px-4"><code>setting_key, setting_value, updated_at</code></td>
                        <td class="py-3 px-4">Systemeinstellungen (Branding, SMTP, Modulschalter <code>enable_calendar</code>/<code>enable_chat</code>/<code>enable_translator</code>, 2-Stufen-Assistent <code>assistant_use_ollama</code> &amp; <code>assistant_ollama_model</code>, Übersetzer-Optionen <code>translator_default_target</code>, <code>translator_deepl_api_key</code> &amp; <code>translator_libretranslate_url</code>, Volltextsuche <code>enable_fulltext_search</code>, Registrierung, <code>installed_commit_sha</code>)</td>
                        <td class="py-3 px-4">Dauerhaft (durch Administrator jederzeit änderbar)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">Dateisystem: uploads/ &amp; uploads/.thumbs/</td>
                        <td class="py-3 px-4"><code>mc_&lt;uniqid&gt;_&lt;hex16&gt;.bin</code>, <code>.thumbs/thumb_&lt;id&gt;_&lt;mtime&gt;_360.webp</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Physische Binärdaten der hochgeladenen Dateien (inkl. Office-Light-Dokumente, pseudonymisierter Dateiname, optional AES-256-verschlüsselt) sowie komprimierter Thumbnail-Cache für unverschlüsselte Bilder</td>
                        <td class="py-3 px-4">Physische Löschung (<code>unlink</code>) inkl. aller gecachten Thumbnails bei Leerung des Papierkorbs, nach Ablauf der Papierkorb-Frist, beim Verschlüsseln im Tresor oder bei Kontolöschung</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">Dateisystem: uploads/.system_backups/</td>
                        <td class="py-3 px-4"><code>snapshot_&lt;YYYYmmdd_His&gt;_&lt;sha&gt;.zip</code>, <code>.htaccess</code></td>
                        <td class="py-3 px-4">Lokale Programm-Sicherungspunkte für den 1-Klick-Update-Rollback (reiner Anwendungscode ohne Nutzerdateien und ohne <code>config/database.php</code>)</td>
                        <td class="py-3 px-4">Automatische Rotation (max. die 8 neuesten Sicherungspunkte werden aufbewahrt; jederzeit im Admin-Bereich löschbar)</td>
                    </tr>
                    <tr>
                        <td class="py-3 px-4 font-mono font-bold text-slate-900 dark:text-white">Browser localStorage / sessionStorage &amp; PHP-Session</td>
                        <td class="py-3 px-4"><code>mc_pinned_header_nav_u&lt;id&gt;</code>, <code>mc_launcher_order_u&lt;id&gt;</code>, <code>mc_dash_hidden_widgets_u&lt;id&gt;</code>, <code>mc_pinned_file_actions_u&lt;id&gt;</code>, <code>mc_translator_history_u&lt;id&gt;</code>, <code>mc_file_view_mode</code>, <code>$_SESSION['assistant_context']</code></td>
                        <td class="py-3 px-4">Vom Benutzer per <code>⭐</code> angeheftete und per Drag &amp; Drop sortierte Favoriten-Module in der Kopfzeile und im <code>⊞ Module</code>-Menü, ein-/ausgeblendete Kacheln im „Mein Tag“-Cockpit, Favoriten-Aktions-Icons in der Dateiauflistung, lokaler Schnellverlauf der letzten 9 Übersetzungen im Übersetzungs-Studio (jederzeit per 1-Klick löschbar), Listen-/Kachel-Ansicht sowie temporäres Gesprächsgedächtnis des 2-Stufen-Assistenten (rein lokal im Browser bzw. in der flüchtigen PHP-Sitzung)</td>
                        <td class="py-3 px-4">Sitzungsgedächtnis bis zum Logout/Session-Ende; <code>localStorage</code> bis zum Zurücksetzen auf „Standard“, Klick auf „Verlauf leeren“ oder Löschen der Browserdaten</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
