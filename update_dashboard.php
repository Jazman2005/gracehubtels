<?php
$f = 'c:\xampp\htdocs\gracehubtels\user\dashboard.php';
$c = file_get_contents($f);

// 1. Send Money -> Bulk SMS in Quick Services Grid
$c = str_replace(
'<a href="/user/internal-transfer" class="flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#8B5CF6]/30 transition-all hover:-translate-y-1 group">
            <div class="w-12 h-12 rounded-full bg-[#8B5CF6]/10 text-[#8B5CF6] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-user-group text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Send Money</span>
        </a>',
'<a href="/user/bulk-sms" class="flex flex-col items-center justify-center p-4 bg-white rounded-2xl border border-slate-100 card-shadow hover:border-[#EC4899]/30 transition-all hover:-translate-y-1 group">
            <div class="w-12 h-12 rounded-full bg-[#EC4899]/10 text-[#EC4899] flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <i class="fa-solid fa-sms text-xl"></i>
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-700 text-center whitespace-nowrap">Bulk SMS</span>
        </a>',
$c
);

// 2. Transfer -> Send Money in More Modal
$c = str_replace(
'<span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Transfer</span>',
'<span class="text-[11px] font-semibold text-slate-700 text-center leading-tight">Send Money</span>',
$c
);

// 3. Add Balance After to Recent Transactions
$c = str_replace(
'<span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold mt-1 uppercase <?= $scls ?>"><?= $stxt ?></span>
                        </div>',
'<span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold mt-1 uppercase <?= $scls ?>"><?= $stxt ?></span>
                            <?php if (isset($tx[\'balance_after\'])): ?>
                            <p class="text-[10px] text-slate-400 mt-1 font-medium">Bal: ₦<?= number_format((float)$tx[\'balance_after\'], 2) ?></p>
                            <?php endif; ?>
                        </div>',
$c
);

file_put_contents($f, $c);
echo "Done";
