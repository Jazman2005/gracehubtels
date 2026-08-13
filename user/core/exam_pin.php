<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';

try {
    db()->exec("CREATE TABLE IF NOT EXISTS exam_pins_stock (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_type VARCHAR(50) NOT NULL,
        pin VARCHAR(100) NOT NULL,
        serial VARCHAR(100) NOT NULL,
        is_used TINYINT(1) DEFAULT 0,
        used_by INT DEFAULT NULL,
        transaction_ref VARCHAR(100) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME DEFAULT NULL
    )");
} catch(\Throwable $e) {}

// Auto-migrate missing columns just in case they don't exist
try { db()->exec("ALTER TABLE exam_pins ADD COLUMN pins_data TEXT DEFAULT NULL"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE exam_pins ADD COLUMN amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER quantity"); } catch (\Throwable $e) {}

class ExamPinService {
    // Builds the request for whichever provider is being tried. Gladtidings
    // is the only exam_pin provider confirmed working in production
    // (/api/epin/, flat exam_name+quantity fields, 'Authorization: Token'
    // header) — any other provider added via API Registry falls back to the
    // same convention until it's actually tested and given its own branch.
    private function api_post(array $prov, array $data): ?array {
        $pname = strtolower($prov['provider_name'] ?? '');
        $token = $prov['api_key'] ?: $prov['api_token'];
        if (strpos($pname, 'gladtidings') !== false) {
            $url = rtrim($prov['api_base_url'], '/') . '/api/epin/';
        } else {
            $url = rtrim($prov['api_base_url'], '/') . '/api/epin/';
        }
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Token '.$token],CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline.
        $d=$res?json_decode($res,true):($res===false?['_network_error'=>true,'message'=>"cURL Error: ".$err]:null);
        if($d)$d['_http_code']=$code;
        log_api($prov['provider_name'] ?? 'exam_pin_api','exam_pin',$data,$d,in_array($code,[200,201]));
        return $d;
    }

    public function process(int $uid,array $input): array {
        try {
            $exam=strtoupper(trim($input['exam_name']??$input['exam']??''));$qty=(int)($input['quantity']??1);$pin=(string)($input['pin']??'');
            if(!in_array($exam,['WAEC','NECO','NABTEB']))return['success'=>false,'message'=>'Invalid exam type'];
            if($qty<1||$qty>5)return['success'=>false,'message'=>'Quantity must be 1-5'];
            if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];
            if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
            if(!claim_purchase_lock($uid,'exam_pin',[$exam,$qty]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
            $sig=purchase_signature('exam_pin',[$exam,$qty]);

            $user=get_user($uid);$ut=$user['user_type']??'subscriber';
            $pr=db()->prepare("SELECT * FROM service_pricing WHERE service='exam_pin' AND plan_name=? AND is_active=1 LIMIT 1");
            $pr->execute([$exam]);$p=$pr->fetch();
            
            if(!$p||(float)$p['selling_price']<=0)return['success'=>false,'message'=>'Exam PIN pricing not configured'];
            $price=(float)$p['selling_price'];
            if($ut==='agent'&&$p['agent_price']>0)$price=(float)$p['agent_price'];
            if($ut==='vendor'&&$p['vendor_price']>0)$price=(float)$p['vendor_price'];
            $amount=$price*$qty;
            
            $debit=debit_wallet($uid,$amount,'exam_pin',"{$qty} {$exam} exam PIN(s)",[],$sig);
            if(!$debit['success'])return $debit;
            
            // --- LOCAL STOCK LOGIC ---
            // Stock rows are claimed atomically under FOR UPDATE inside their
            // own transaction: the SELECT locks the candidate rows so no other
            // concurrent purchase (from a different user — claim_purchase_lock()
            // above only serializes THIS user's requests) can see them, and the
            // claiming UPDATE additionally requires is_used=0 as a defensive
            // second guard. Without both of these, two different users buying
            // the last unit of an exam type's stock concurrently could both be
            // handed the identical PIN — confirmed exploitable prior to this fix.
            $stock_claimed = false;
            $pins_arr = [];
            db()->beginTransaction();
            try {
                $stock_stmt = db()->prepare("SELECT * FROM exam_pins_stock WHERE exam_type=? AND is_used=0 LIMIT " . (int)$qty . " FOR UPDATE");
                $stock_stmt->execute([$exam]);
                $local_stock = $stock_stmt->fetchAll(PDO::FETCH_ASSOC);

                if (count($local_stock) === $qty) {
                    $ids = array_column($local_stock, 'id');
                    $placeholders = str_repeat('?,', count($ids) - 1) . '?';

                    $update_stmt = db()->prepare("UPDATE exam_pins_stock SET is_used=1, used_by=?, transaction_ref=?, used_at=NOW() WHERE id IN ($placeholders) AND is_used=0");
                    $update_stmt->execute(array_merge([$uid, $debit['ref']], $ids));

                    if ($update_stmt->rowCount() === count($ids)) {
                        foreach ($local_stock as $s) {
                            $token_str = "PIN: " . $s['pin'];
                            if (!empty($s['serial']) && strtolower(trim($s['serial'])) !== 'null') {
                                $token_str .= " | Serial: " . $s['serial'];
                            }
                            $pins_arr[] = $token_str;
                        }
                        db()->commit();
                        $stock_claimed = true;
                    } else {
                        // Should be structurally unreachable with FOR UPDATE held
                        // through this whole block — kept as a defensive rollback.
                        db()->rollBack();
                    }
                } else {
                    db()->rollBack();
                }
            } catch (\Throwable $e) {
                if (db()->inTransaction()) db()->rollBack();
                throw $e;
            }

            if ($stock_claimed) {
                // The pins are now irrevocably handed out (is_used=1, committed
                // above) — the status flip to 'success' must be the very next
                // statement, before any other write that could throw. If it
                // isn't, an exception here would reach the outer catch() with
                // the row still 'pending', and refund_failed_transaction()'s
                // atomic claim would refund a purchase whose pins were already
                // delivered — a real double-credit that previously existed here
                // because this ordering was reversed.
                update_tx_status($debit['ref'],'success','LOCAL-'.time());

                $meta = json_encode(['token' => $pins_arr[0], 'pins' => $pins_arr]);
                db()->prepare("UPDATE transactions SET meta=? WHERE transaction_ref=?")->execute([$meta, $debit['ref']]);

                $costTotal=(float)($p['cost_price']??0)*$qty;
                db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")->execute([$costTotal,$amount-$costTotal,'Local Stock',$debit['ref']]);

                db()->prepare("INSERT INTO exam_pins (user_id,transaction_ref,exam_name,quantity,amount,pins_data,status) VALUES (?,?,?,?,?,?,'success')")->execute([$uid,$debit['ref'],$exam,$qty,$amount,json_encode($pins_arr)]);

                $cb=apply_cashback($uid,$amount,'exam_pin',null);
                notify_user($uid,'Exam PINs Ready',"{$qty} {$exam} PIN(s) purchased",'success');
                return['success'=>true,'message'=>"{$qty} {$exam} PIN(s) purchased",'pins'=>$pins_arr,'ref'=>$debit['ref'],'cashback'=>$cb];
            }
            // --- END LOCAL STOCK LOGIC ---
            
            // Multi-provider failover (matches every other service in this
            // codebase) — previously this only ever tried a single hardcoded
            // provider row with no fallback, and the Gladtidings URL/token
            // were hardcoded in api_post() instead of coming from whichever
            // registry entry is actually routed here via API Routes.
            $providers = get_api_providers('exam_pin');
            if (empty($providers)) {
                refund_failed_transaction($debit['ref'],$uid,$amount,'Exam PIN service not configured or out of stock');
                return['success'=>false,'message'=>'Exam PIN service not configured or Out of Stock'];
            }
            $success = false; $has_pending = false; $last_res = null; $last_prov = null;
            foreach ($providers as $prov) {
                if (empty($prov['api_base_url']) || (empty($prov['api_key']) && empty($prov['api_token']))) continue;
                $last_prov = $prov;
                $res = $this->api_post($prov, ['exam_name'=>$exam,'quantity'=>$qty]);
                if ($res && in_array($res['_http_code']??0,[200,201]) && !is_api_response_failure($res) && !is_api_response_pending($res)) { $success = true; $last_res = $res; break; }
                if ($res && in_array($res['_http_code']??0,[200,201]) && is_api_response_pending($res) && !$success) { $has_pending = true; $last_res = $res; continue; }
                $last_res = $res;
            }
            $provider_resp = provider_response_text($last_res);

            if ($success && $last_prov) {
                $res = $last_res;
                $cb = 0;
                save_tx_response($debit['ref'], $provider_resp);
                // Guard against a duplicate/replayed resolution of this same
                // ref (e.g. the catch block below firing after this branch
                // already succeeded, on some later unrelated exception) —
                // only proceed to record the pins/notify if we actually won
                // the pending/processing -> success transition here.
                if (update_tx_status($debit['ref'],'success',(string)($res['id']??''))) {
                    db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);
                    db()->prepare("INSERT INTO exam_pins (user_id,transaction_ref,exam_name,quantity,amount,pins_data,status) VALUES (?,?,?,?,?,?,'success')")->execute([$uid,$debit['ref'],$exam,$qty,$amount,json_encode($res['pins']??[])]);
                    $cb=apply_cashback($uid,$amount,'exam_pin',null);
                    notify_user($uid,'✅ Exam PINs Ready',"{$qty} {$exam} PIN(s) purchased.",'success');
                }
                return['success'=>true,'message'=>"{$qty} {$exam} PIN(s) purchased",'pins'=>$res['pins']??[],'ref'=>$debit['ref'],'cashback'=>$cb];
            }

            if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);

            if ($has_pending) {
                update_tx_status($debit['ref'],'pending',(string)($last_res['id']??''));
                save_tx_response($debit['ref'], $provider_resp);
                notify_user($uid,'Exam PIN Processing',"Your {$exam} PIN purchase is being processed.",'info',null,$debit['balance_before'],$debit['balance_after']);
                return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true];
            }

            // Note: the exam-PIN provider exposes no status-verification
            // endpoint, so a network timeout here cannot be distinguished
            // from a confirmed decline the way other services in this
            // codebase now do — refunding remains the pragmatic choice for
            // this low-value, non-verifiable item type rather than leaving
            // the transaction stuck in 'processing' forever with no
            // mechanism to ever resolve it. refund_failed_transaction()
            // atomically claims the transaction first, so this is still safe
            // against being triggered more than once for the same ref.
            refund_failed_transaction($debit['ref'],$uid,$amount,$provider_resp ?: 'Purchase failed');
            save_tx_response($debit['ref'], $provider_resp ?: 'Purchase failed');
            return['success'=>false,'message'=>'Purchase failed. Wallet reversed.'];

        } catch (\Throwable $e) {
            // refund_failed_transaction() atomically claims the transaction
            // (WHERE status IN ('pending','processing')) before crediting
            // anything — previously this called update_tx_status()+
            // credit_wallet() directly with the return value ignored, so if
            // the purchase had *already* succeeded (pins delivered/marked
            // used) and a later statement in this same request threw (e.g. a
            // DB hiccup in notify_user() or the exam_pins INSERT), this catch
            // block would still refund on top of the delivered pins — a real
            // double-credit. Now a second/late claim attempt here is a safe
            // no-op if the transaction was already resolved.
            if (isset($debit) && isset($debit['success']) && $debit['success']) {
                refund_failed_transaction($debit['ref'], $uid, $amount, 'System error');
            }
            log_error('exam_pin', 'Purchase exception: ' . $e->getMessage(), ['line' => $e->getLine(), 'uid' => $uid]);
            return ['success' => false, 'message' => 'Purchase failed. Please try again.'];
        }
    }
}
function process_exam_pin(int $uid,array $input):array{return(new ExamPinService())->process($uid,$input);}
