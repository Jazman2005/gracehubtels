<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php';

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
    private function get_provider(): ?array {
        $s=db()->prepare("SELECT * FROM api_providers WHERE service='exam_pin' AND is_active=1 ORDER BY priority LIMIT 1");
        $s->execute();return $s->fetch()?:null;
    }
    
    private function api_post(string $token,array $data): ?array {
        $ch=curl_init('https://www.gladtidingsdata.com/api/epin/');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Token '.$token],CURLOPT_TIMEOUT=>30]);
        $res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=$res?json_decode($res,true):null;if($d)$d['_http_code']=$code;
        log_api('gladtidings','exam_pin',$data,$d,in_array($code,[200,201]));return $d;
    }
    
    public function process(int $uid,array $input): array {
        try {
            $exam=strtoupper(trim($input['exam_name']??$input['exam']??''));$qty=(int)($input['quantity']??1);$pin=(string)($input['pin']??'');
            if(!in_array($exam,['WAEC','NECO','NABTEB']))return['success'=>false,'message'=>'Invalid exam type'];
            if($qty<1||$qty>5)return['success'=>false,'message'=>'Quantity must be 1-5'];
            if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];
            if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
            
            $user=get_user($uid);$ut=$user['user_type']??'subscriber';
            $pr=db()->prepare("SELECT * FROM service_pricing WHERE service='exam_pin' AND plan_name=? AND is_active=1 LIMIT 1");
            $pr->execute([$exam]);$p=$pr->fetch();
            
            if(!$p||(float)$p['selling_price']<=0)return['success'=>false,'message'=>'Exam PIN pricing not configured'];
            $price=(float)$p['selling_price'];
            if($ut==='agent'&&$p['agent_price']>0)$price=(float)$p['agent_price'];
            if($ut==='vendor'&&$p['vendor_price']>0)$price=(float)$p['vendor_price'];
            $amount=$price*$qty;
            
            $debit=debit_wallet($uid,$amount,'exam_pin',"{$qty} {$exam} exam PIN(s)");
            if(!$debit['success'])return $debit;
            
            // --- NEW LOCAL STOCK LOGIC ---
            $stock_stmt = db()->prepare("SELECT * FROM exam_pins_stock WHERE exam_type=? AND is_used=0 LIMIT " . (int)$qty);
            $stock_stmt->execute([$exam]);
            $local_stock = $stock_stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($local_stock) === $qty) {
                $ids = array_column($local_stock, 'id');
                $placeholders = str_repeat('?,', count($ids) - 1) . '?';
                
                $update_stmt = db()->prepare("UPDATE exam_pins_stock SET is_used=1, used_by=?, transaction_ref=?, used_at=NOW() WHERE id IN ($placeholders)");
                $update_stmt->execute(array_merge([$uid, $debit['ref']], $ids));

                $pins_arr = [];
                foreach ($local_stock as $s) {
                    $token_str = "PIN: " . $s['pin'];
                    if (!empty($s['serial']) && strtolower(trim($s['serial'])) !== 'null') {
                        $token_str .= " | Serial: " . $s['serial'];
                    }
                    $pins_arr[] = $token_str;
                }
                
                $meta = json_encode(['token' => $pins_arr[0], 'pins' => $pins_arr]);
                db()->prepare("UPDATE transactions SET meta=? WHERE transaction_ref=?")->execute([$meta, $debit['ref']]);
                
                $costTotal=(float)($p['cost_price']??0)*$qty;
                db()->prepare("UPDATE transactions SET cost_price=?, profit=?, provider=? WHERE transaction_ref=?")->execute([$costTotal,$amount-$costTotal,'Local Stock',$debit['ref']]);
                
                update_tx_status($debit['ref'],'success','LOCAL-'.time());
                
                db()->prepare("INSERT INTO exam_pins (user_id,transaction_ref,exam_name,quantity,amount,pins_data,status) VALUES (?,?,?,?,?,?,'success')")->execute([$uid,$debit['ref'],$exam,$qty,$amount,json_encode($pins_arr)]);
                
                notify_user($uid,'Exam PINs Ready',"{$qty} {$exam} PIN(s) purchased",'success');
                return['success'=>true,'message'=>"{$qty} {$exam} PIN(s) purchased",'pins'=>$pins_arr,'ref'=>$debit['ref']];
            }
            // --- END LOCAL STOCK LOGIC ---
            
            $prov=$this->get_provider();
            if(!$prov||empty($prov['api_key'])){
                update_tx_status($debit['ref'],'failed');
                credit_wallet($uid,$amount,'exam_pin','Exam PIN reversal');
                return['success'=>false,'message'=>'Exam PIN service not configured or Out of Stock'];
            }
            
            $res=$this->api_post($prov['api_key'],['exam_name'=>$exam,'quantity'=>$qty]);
            
            if($res&&in_array($res['_http_code']??0,[200,201])){
                update_tx_status($debit['ref'],'success',(string)($res['id']??''));
                db()->prepare("INSERT INTO exam_pins (user_id,transaction_ref,exam_name,quantity,amount,pins_data,status) VALUES (?,?,?,?,?,?,'success')")->execute([$uid,$debit['ref'],$exam,$qty,$amount,json_encode($res['pins']??[])]);
                notify_user($uid,'o. Exam PINs Ready',"{$qty} {$exam} PIN(s) purchased.",'success');
                return['success'=>true,'message'=>"{$qty} {$exam} PIN(s) purchased",'pins'=>$res['pins']??[],'ref'=>$debit['ref']];
            }
            
            update_tx_status($debit['ref'],'failed');
            credit_wallet($uid,$amount,'exam_pin','Exam PIN reversal');
            return['success'=>false,'message'=>'Purchase failed. Wallet reversed.'];
            
        } catch (\Throwable $e) {
            if (isset($debit) && isset($debit['success']) && $debit['success']) {
                update_tx_status($debit['ref'], 'failed');
                credit_wallet($uid, $amount, 'exam_pin', 'System error reversal');
            }
            return ['success' => false, 'message' => 'DEBUG ERROR: ' . $e->getMessage() . ' on line ' . $e->getLine()];
        }
    }
}
function process_exam_pin(int $uid,array $input):array{return(new ExamPinService())->process($uid,$input);}
