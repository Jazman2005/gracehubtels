<?php
$file = 'c:\xampp\htdocs\gracehubtels\user\dashboard.php';
$content = file_get_contents($file);

$broken = <<<HTML
                            case 'success': \$scls = 'text-emerald-600 bg-emerald-100'; \$stxt = 'Success'; break;
                    </a>
HTML;

$fixed = <<<HTML
                            case 'success': \$scls = 'text-emerald-600 bg-emerald-100'; \$stxt = 'Success'; break;
                            case 'pending': \$scls = 'text-amber-600 bg-amber-100'; \$stxt = 'Pending'; break;
                            case 'failed': \$scls = 'text-rose-600 bg-rose-100'; \$stxt = 'Failed'; break;
                            case 'reversed': \$scls = 'text-slate-600 bg-slate-200'; \$stxt = 'Reversed'; break;
                            default: \$scls = 'text-slate-600 bg-slate-100'; \$stxt = ucfirst(\$tx['status']);
                        }
                    ?>
                    <a href="/user/receipt.php?ref=<?= urlencode(\$tx['transaction_ref']) ?>" class="flex items-center p-4 hover:bg-slate-50 transition-colors block">
                        <div class="w-10 h-10 rounded-full <?= \$cc ?> flex items-center justify-center flex-shrink-0 overflow-hidden relative">
                            <?= get_tx_logo_html(\$tx['description'] ?? '', \$ic) ?>
                        </div>
                        <div class="ml-4 flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars(\$tx['description'] ?? '') ?></p>
                            <p class="text-xs text-slate-500 mt-0.5"><?= fmt_tx_time(\$tx['created_at']) ?></p>
                        </div>
                        <div class="ml-4 text-right">
                            <p class="text-sm font-bold <?= \$cr ? 'text-emerald-600' : 'text-slate-800' ?>">
                                <?= \$cr ? '+' : '-' ?>N<?= number_format((float) \$tx['amount'], 2) ?>
                            </p>
                            <span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold mt-1 uppercase <?= \$scls ?>"><?= \$stxt ?></span>
                        </div>
                    </a>
HTML;

// Clean up newlines to ensure it matches
$broken = str_replace("\r", "", $broken);
$content = str_replace("\r", "", $content);

$content = str_replace($broken, $fixed, $content);
file_put_contents($file, $content);
echo "Fixed";
