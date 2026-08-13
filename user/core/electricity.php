<?php
require_once __DIR__.'/db.php'; require_once __DIR__.'/auth.php'; require_once __DIR__.'/api_router.php';
class ElectricityService {
    // AutoPilot and SageCloud both need "Authorization: Bearer <token>",
    // not the "Token <api_key>" scheme every other provider here uses.
    // For SageCloud this is confirmed empirically, not just from docs: a
    // direct test against their live electricity endpoint with a fake
    // "publicKey" header (the scheme betting.php uses) got back the exact
    // same "Authentication error" as sending NO auth header at all — proving
    // that header is silently ignored here — while a fake "Authorization:
    // Bearer" got a distinctly different "Invalid token." response, proving
    // the server actually reads that header. betting.php's publicKey scheme
    // is specific to SageCloud's betting endpoints, not shared account-wide.
    private function auth_header(array $prov): ?string {
        $pname = strtolower($prov['provider_name'] ?? '');
        if (strpos($pname, 'reteller') !== false && !empty($prov['public_key']) && !empty($prov['secret_key'])) {
            return 'Authorization: Bearer ' . $prov['public_key'] . ':' . $prov['secret_key'];
        }
        if (strpos($pname, 'autopilot') !== false || strpos($pname, 'sagecloud') !== false) {
            $token = $prov['api_token'] ?: $prov['api_key'];
            return $token ? 'Authorization: Bearer ' . $token : null;
        }
        if (!empty($prov['api_key'])) return 'Authorization: Token ' . $prov['api_key'];
        if (!empty($prov['api_token'])) return 'Authorization: Bearer ' . $prov['api_token'];
        return null;
    }
    // SageCloud's registry Base URL may or may not already include "/api" —
    // confirmed inconsistent in practice (betting.php normalizes this same
    // way). Always ensures exactly one "/api" segment rather than assuming
    // either way, so validate/purchase URLs are correct regardless of how
    // the registry field happens to be configured.
    private function sagecloud_base(array $prov): string {
        $base = rtrim($prov['api_base_url'], '/');
        if (substr($base, -4) !== '/api') $base .= '/api';
        return $base;
    }
    private function api_get(array $prov, string $url): ?array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $auth = $this->auth_header($prov);
        if ($auth) $headers[] = $auth;
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$d=$res?json_decode($res,true):null;if($d===null&&!empty($res)){$d=['_http_code'=>$code,'message'=>"HTTP ".$code.": ".substr(strip_tags($res),0,200)];}if($d)$d['_http_code']=$code;log_api($prov['provider_name']??'electricity_api','validate_meter',['url'=>$url],$d,$code==200);return $d;
    }
    private function api_post(array $prov, string $url, array $data): ?array {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $auth = $this->auth_header($prov);
        if ($auth) $headers[] = $auth;
        $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$res=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);$d=$res?json_decode($res,true):null;
        // A cURL-level failure means we genuinely don't know whether the
        // provider received/processed this request — not the same as an
        // explicit decline. Flagged so the final purchase decision can avoid
        // treating an uncertain outcome the same as a confirmed failure.
        if($res===false){$d=['_http_code'=>500,'_network_error'=>true,'message'=>"cURL Error: ".$err];}elseif($d===null&&!empty($res)){$d=['_http_code'=>$code,'message'=>"HTTP ".$code.": ".substr(strip_tags($res),0,200)];}if($d)$d['_http_code']=$code;log_api($prov['provider_name'] ?? 'electricity_api','electricity',$data,$d,in_array($code,[200,201]));return $d;
    }
    // SageCloud identifies a disco+meter-type combination with one compound
    // slug (e.g. "ikeja_electric_prepaid"), confirmed live against their own
    // account's current biller catalog (fetched via /v2/electricity/fetch-
    // billers) — not a numeric ID like every other provider here. A numeric
    // Disco-ID-keyed default turned out to be too fragile in practice
    // (confirmed live: this admin's actual Disco IDs don't match this
    // codebase's suggested 1=IKEDC...9=JED numbering, producing an empty
    // slug), so this matches by keyword against the disco's own name text
    // instead — resilient to whatever Disco ID numbers are actually
    // configured. An admin-set value_map entry ("disco_<id>=<slug>") on the
    // SageCloud electricity route always wins if set, for full manual control.
    private const SAGECLOUD_NAME_KEYWORDS = [
        'ikeja' => 'ikeja_electric', 'eko' => 'eko_electric', 'lagos' => 'eko_electric',
        'abuja' => 'abuja_electric', 'aedc' => 'abuja_electric',
        'port harcourt' => 'portharcourt_electric', 'portharcourt' => 'portharcourt_electric', 'phedc' => 'portharcourt_electric', 'phed' => 'portharcourt_electric',
        'ibadan' => 'ibadan_electric', 'ibedc' => 'ibadan_electric',
        'kano' => 'kedco_electric', 'kedco' => 'kedco_electric',
        'enugu' => 'enugu_electric', 'eedc' => 'enugu_electric',
        'kaduna' => 'kaduna_electric', 'kaedco' => 'kaduna_electric',
        'jos' => 'jos_electric', 'jed' => 'jos_electric',
        'benin' => 'benin_electric',
        'aba' => 'aba_electric',
    ];
    private function sagecloud_type(array $prov, int $disco_id, string $disco_name, string $mtype): string {
        $override = provider_value($prov, 'disco_'.$disco_id, null);
        if ($override) return $override . '_' . $mtype;
        $name_lower = strtolower($disco_name);
        foreach (self::SAGECLOUD_NAME_KEYWORDS as $kw => $slug) {
            if (stripos($name_lower, $kw) !== false) return $slug . '_' . $mtype;
        }
        return '_' . $mtype;
    }
    public function validate_meter(int $disco_id,string $meter,string $mtype): array {
        $disco=db()->prepare("SELECT * FROM electricity_discos WHERE disco_id=? LIMIT 1");$disco->execute([$disco_id]);$d=$disco->fetch();
        // Prefer a disco->provider mapping from the Disco Provider IDs page (plan_api_bindings)
        // over the disco's single legacy product_code, same precedence as data/cabletv purchases.
        $plan_bindings = $d ? get_plan_bindings('electricity', (int)$d['id'], $mtype) : [];
        $all = array_merge($plan_bindings, get_api_providers('electricity'));
        if (empty($all)) return ['success'=>false,'message'=>'Service not configured'];
        // Try every configured provider in order, same as the actual
        // purchase flow (process()) already does — previously this only
        // ever tried $all[0] with no fallback, so a stale/misconfigured
        // provider sitting in priority 1 (e.g. an old AutosyncNG route the
        // admin never intended for electricity, confirmed live: returning
        // "no gateway found for this service, kindly set it") silently
        // blocked validation entirely even when a working provider (e.g.
        // SageCloud) was also configured and would have succeeded.
        $last_message = 'Service not configured';
        foreach ($all as $prov) {
            if (empty($prov['api_base_url'])) continue;
            $result = $this->try_validate_with_provider($prov, $d, $disco_id, $meter, $mtype);
            if ($result['success']) return $result;
            $last_message = $result['message'];
        }
        return ['success'=>false,'message'=>$last_message];
    }
    private function try_validate_with_provider(array $prov, $d, int $disco_id, string $meter, string $mtype): array {
        $pname = strtolower($prov['provider_name'] ?? '');
        if (stripos($pname, 'autosyncng') !== false || stripos($pname, 'autopilot') !== false) {
            $pid=!empty($prov['product_code'])?$prov['product_code']:(!empty($d['product_code'])?$d['product_code']:$disco_id);
            $base=rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
            $url=$base.(stripos($pname, 'autopilot') !== false ? '/api/live/v1/validate/electricity' : '/api/v1/validate/electricity');
            $res=$this->api_post($prov,$url,['meter_number'=>$meter,'product_id'=>$pid,'type'=>$mtype]);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!empty($res['data']['is_valid']))return['success'=>true,'name'=>$res['data']['customer_name']??'Valid Meter','address'=>$res['data']['customer_address']??''];
            return['success'=>false,'message'=>$res['data']['customer_name']??$res['message']??$res['error']??'Invalid meter number'];
        }
        if (stripos($pname, 'msjdatasubs') !== false) {
            $pid=!empty($prov['product_code'])?$prov['product_code']:(!empty($d['product_code'])?$d['product_code']:$disco_id);
            $url=rtrim($prov['api_base_url'],'/').'/api/electricity/verify/';
            $res=$this->api_post($prov,$url,['provider'=>$pid,'meternumber'=>$meter,'metertype'=>$mtype]);
            if($res&&in_array($res['_http_code']??0,[200,201]))return['success'=>true,'name'=>$res['name']??$res['customer_name']??$res['Customer_Name']??$res['msg']??'Valid Meter','address'=>$res['address']??$res['customer_address']??$res['Customer_Address']??''];
            return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid meter number'];
        }
        if (stripos($pname, 'sagecloud') !== false) {
            // Endpoint per SageCloud's own Postman docs (docs.sagecloud.ng,
            // "Validate Meter") — POST /v2/electricity/validate-customer
            // with {type, account_number}. Auth and base-URL normalization
            // handled in auth_header()/sagecloud_base() — matches
            // betting.php's proven-working behavior, not what the docs claim.
            $type=$this->sagecloud_type($prov,$disco_id,$d['disco_name']??'',$mtype);
            if (str_starts_with($type, '_')) return ['success'=>false,'message'=>"SageCloud: couldn't match \"{$d['disco_name']}\" to a known disco — add a value_map entry (disco_{$disco_id}=<slug>) on its Electricity route."];
            $url=$this->sagecloud_base($prov).'/v2/electricity/validate-customer';
            $res=$this->api_post($prov,$url,['type'=>$type,'account_number'=>$meter]);
            if($res&&in_array($res['_http_code']??0,[200,201])&&!empty($res['success']))return['success'=>true,'name'=>$res['customer']['customerName']??'Valid Meter','address'=>$res['customer']['customerAddress']??''];
            return['success'=>false,'message'=>$res['message']??'Invalid meter number'];
        }
        if (stripos($pname, 'gladtidings') !== false) {
            // Confirmed via Gladtidings' own Postman docs
            // (ValidateMeterNumber) — a GET with a /v2/ path, unlike the
            // unversioned /api/validatemeter/ the generic fallback below uses.
            $disco_id_override=!empty($prov['product_code'])?$prov['product_code']:(!empty($d['product_code'])?$d['product_code']:$disco_id);
            $url=rtrim($prov['api_base_url'],'/')."/api/v2/validatemeter/?disco_id={$disco_id_override}&meter_type={$mtype}&meter_number={$meter}";
            $res=$this->api_get($prov,$url);
            if($res&&!($res['invalid']??true)&&empty($res['message'])&&empty($res['error']))return['success'=>true,'name'=>$res['name']??'Valid Meter','address'=>$res['address']??''];
            return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid meter number'];
        }
        if (stripos($pname, 'telecomabode') !== false) {
            // Same product_code-mapping precedence as process()'s catch-all branch,
            // so validation checks the identical disco identity the purchase will use.
            $disco_id_override = !empty($prov['product_code']) ? $prov['product_code'] : (int)provider_network_id($prov, 'disco_'.$disco_id, $disco_id);
            $url=rtrim($prov['api_base_url'],'/')."/api/electricity/validate";
            $res=$this->api_post($prov,$url,['disco_id'=>$disco_id_override,'meter_type'=>$mtype,'meter_number'=>$meter]);
            if($res&&in_array($res['_http_code']??0,[200,201]))return['success'=>true,'name'=>$res['name']??$res['customer_name']??$res['message']??'Valid Meter','address'=>$res['address']??$res['customer_address']??''];
            return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid meter number'];
        }
        $disco_id_override = !empty($prov['product_code']) ? $prov['product_code'] : (int)provider_network_id($prov, 'disco_'.$disco_id, $disco_id);
        $url=rtrim($prov['api_base_url'],'/')."/api/validatemeter/?disco_id={$disco_id_override}&meter_type={$mtype}&meter_number={$meter}";
        $res=$this->api_get($prov,$url);
        if($res&&!($res['invalid']??true)&&empty($res['message'])&&empty($res['error']))return['success'=>true,'name'=>$res['name']??'Valid Meter','address'=>$res['address']??''];
        return['success'=>false,'message'=>$res['message']??$res['error']??'Invalid meter number'];
    }
    public function process(int $uid,array $input): array {
      $debit = null; $total = 0;
      try {
        $did=(int)($input['disco_id']??0);$meter=sanitize_phone($input['meter_number']??'');$mtype=in_array($input['meter_type']??'',['prepaid','postpaid'])?$input['meter_type']:'';$amount=(float)($input['amount']??0);$phone=sanitize_phone($input['phone']??'');$pin=(string)($input['pin']??'');
        if(!$did)return['success'=>false,'message'=>'Select a disco'];if(strlen($meter)<5)return['success'=>false,'message'=>'Invalid meter number'];if(!$mtype)return['success'=>false,'message'=>'Select meter type'];
        $min = (float)setting('limit_electricity_min', '1000'); $max = (float)setting('limit_electricity_max', '50000');
        if($amount<$min||$amount>$max)return['success'=>false,'message'=>"Amount must be between ₦".number_format($min)." and ₦".number_format($max)];
        if(detect_tamper($input))return['success'=>false,'message'=>'Invalid input'];if(!verify_pin($uid,$pin))return['success'=>false,'message'=>'Invalid transaction PIN'];
        $disco=db()->prepare("SELECT * FROM electricity_discos WHERE disco_id=? AND is_active=1 LIMIT 1");$disco->execute([$did]);$d=$disco->fetch();if(!$d)return['success'=>false,'message'=>'Invalid disco'];
        $user=get_user($uid);$ut=$user['user_type']??'subscriber';
        $charge=(float)$d['charge'];
        // A NULL postpaid_charge means "no override" — keep using the base
        // charge for postpaid too, so this stays a no-op for every disco
        // until an admin explicitly sets one.
        if($mtype==='postpaid'&&$d['postpaid_charge']!==null)$charge=(float)$d['postpaid_charge'];
        if($ut==='agent'&&$d['agent_charge']!==null)$charge=(float)$d['agent_charge'];
        if($ut==='vendor'&&$d['vendor_charge']!==null)$charge=(float)$d['vendor_charge'];
        $total=$amount+$charge;
        if(!claim_purchase_lock($uid,'electricity',[$did,$meter,$amount]))return['success'=>false,'message'=>'Duplicate request detected. Please wait a moment and try again.'];
        $sig=purchase_signature('electricity',[$did,$meter,$amount]);
        $debit=debit_wallet($uid,$total,'electricity',"₦{$amount} {$d['disco_name']} for {$meter}",['disco'=>$d['disco_name'],'meter'=>$meter,'type'=>$mtype,'charge'=>$charge],$sig);if(!$debit['success'])return $debit;
        
        // Prefer a disco->provider mapping from the Disco Provider IDs page (plan_api_bindings)
        // over the disco's single legacy product_code, same precedence as data/cabletv purchases.
        $plan_bindings = get_plan_bindings('electricity', (int)$d['id'], $mtype);
        $providers = array_merge($plan_bindings, get_api_providers('electricity'));
        $success = false; $has_pending = false; $last_res = null; $last_prov = null;
        foreach ($providers as $prov) {
            if (empty($prov['api_base_url'])) continue;
            $last_prov = $prov;
            $pname = strtolower($prov['provider_name'] ?? '');
            if (stripos($pname, 'autosyncng') !== false || stripos($pname, 'autopilot') !== false) {
                $pid=!empty($prov['product_code'])?$prov['product_code']:(!empty($d['product_code'])?$d['product_code']:$did);
                $base=rtrim(str_ireplace('/api/live', '', $prov['api_base_url']), '/');
                $url=$base.(stripos($pname, 'autopilot') !== false ? '/api/live/v1/electricity' : '/api/v1/electricity');
                $api_payload = ['meter_number'=>$meter,'product_id'=>$pid,'type'=>$mtype,'amount'=>$amount,'request_ref'=>$debit['ref']];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = $prov['api_pin'];
            } elseif (stripos($pname, 'msjdatasubs') !== false) {
                $pid=!empty($prov['product_code'])?$prov['product_code']:(!empty($d['product_code'])?$d['product_code']:$did);
                $url=rtrim($prov['api_base_url'],'/').'/api/electricity/';
                $api_payload = ['provider'=>$pid,'meternumber'=>$meter,'metertype'=>$mtype,'amount'=>$amount,'phone'=>$phone?:$user['phone'],'ref'=>$debit['ref']];
                if (!empty($prov['api_pin'])) $api_payload['pin'] = $prov['api_pin'];
            } elseif (stripos($pname, 'sagecloud') !== false) {
                // Endpoint per SageCloud's own Postman docs (docs.sagecloud.ng,
                // "Purchase Electricity") — POST /v2/electricity/purchase with
                // {reference, type, disco, account_number, phone, amount}.
                // Auth and base-URL normalization handled in
                // auth_header()/sagecloud_base() — matches betting.php's
                // proven-working behavior, not what the docs claim.
                $type=$this->sagecloud_type($prov,$did,$d['disco_name'],$mtype);
                if (str_starts_with($type, '_')) { $last_res = ['message'=>"SageCloud: couldn't match \"{$d['disco_name']}\" to a known disco — add a value_map entry (disco_{$did}=<slug>) on its Electricity route."]; continue; }
                $url=$this->sagecloud_base($prov).'/v2/electricity/purchase';
                $api_payload = ['reference'=>$debit['ref'],'type'=>$type,'disco'=>strtoupper($d['disco_name']),'account_number'=>$meter,'phone'=>$phone?:$user['phone'],'amount'=>(string)$amount];
            } elseif (stripos($pname, 'gladtidings') !== false) {
                // Confirmed via Gladtidings' own Postman docs (RechargeMeter):
                // same URL as the generic fallback below, but the disco field
                // is named disco_name (not disco_id) and the meter-type field
                // is capitalized MeterType (not meter_type) — both silently
                // ignored/rejected by their server under the generic names.
                $url=rtrim($prov['api_base_url'],'/').'/api/billpayment/';
                $disco_id_override = !empty($prov['product_code']) ? $prov['product_code'] : (int)provider_network_id($prov, 'disco_'.$did, $did);
                $api_payload = ['disco_name'=>$disco_id_override,'amount'=>$amount,'meter_number'=>$meter,'MeterType'=>ucfirst($mtype),'Customer_Phone'=>$phone?:$user['phone'],'customer_name'=>$user['full_name'],'customer_address'=>''];
            } elseif (stripos($pname, 'reteller') !== false) {
                // $prov['product_code'] is already meter-type-specific here —
                // get_plan_bindings('electricity', $d['id'], $mtype) above
                // threads $mtype through, so prepaid/postpaid resolve to
                // their own distinct Reteller variation_code automatically.
                $pid = !empty($prov['product_code']) ? $prov['product_code'] : ($d['product_code'] ?? '');
                if (!$pid) { $last_res = ['message' => 'No Reteller variation_code mapped for this disco/meter-type — set it under Plan ID Mapping.']; continue; }
                $url = rtrim($prov['api_base_url'], '/') . '/purchase';
                $api_payload = ['variation_code' => (string)$pid, 'recipient' => (string)$meter, 'amount' => $amount, 'meter_type' => $mtype];
            } else {
                $url=rtrim($prov['api_base_url'],'/').'/api/billpayment/';
                $disco_id_override = !empty($prov['product_code']) ? $prov['product_code'] : (int)provider_network_id($prov, 'disco_'.$did, $did);
                $api_payload = ['disco_id'=>$disco_id_override,'amount'=>$amount,'meter_number'=>$meter,'meter_type'=>$mtype,'Customer_Phone'=>$phone?:$user['phone'],'customer_name'=>$user['full_name'],'customer_address'=>''];
            }
            $res=$this->api_post($prov,$url,$api_payload);
            $last_res = $res;
            if($res&&in_array($res['_http_code']??0,[200,201])&&!is_api_response_failure($res)&&!is_api_response_pending($res)){ $success=true; break; }
            if($res&&in_array($res['_http_code']??0,[200,201])&&is_api_response_pending($res)&&!$success){ $has_pending=true; continue; }
        }
        
        if ($success && $last_prov) {
            $prov = $last_prov; $res = $last_res;
            save_tx_response($debit['ref'], provider_response_text($res));
            if (update_tx_status($debit['ref'],'success',(string)($res['id']??$res['data']['reference']??''))) {
                $tok = $res['data']['transaction']['token'] ?? $res['data']['transaction']['pin'] ?? $res['data']['transaction']['main_token'] ?? $res['data']['transaction']['units'] ?? $res['data']['transaction']['meter_token'] ?? $res['data']['token'] ?? $res['data']['pin'] ?? $res['data']['main_token'] ?? $res['data']['units'] ?? $res['token'] ?? $res['pin'] ?? $res['meter_token'] ?? $res['vtoken'] ?? $res['tokens'] ?? $res['data_pin'] ?? $res['tokenCode'] ?? $res['token_code'] ?? $res['main_token'] ?? $res['units'] ?? $res['unit'] ?? $res['card'] ?? (is_string($res['message'] ?? null) && preg_match('/[0-9]{4,}(?:-[0-9]{4,})+/', $res['message'], $m) ? $m[0] : '') ?? (is_string($res['data']['transaction']['details'] ?? null) && preg_match('/[0-9]{4,}(?:-[0-9]{4,})+/', $res['data']['transaction']['details'], $m) ? $m[0] : '') ?: 'Check meter';
                if ($tok) db()->prepare("UPDATE transactions SET meta = JSON_SET(COALESCE(meta, '{}'), '$.token', ?) WHERE transaction_ref=?")->execute([$tok, $debit['ref']]);notify_user($uid,' ⚡ Electricity Token',"Token: {$tok} for meter {$meter}",'success',null,$debit['balance_before'],$debit['balance_after']);$cb=apply_cashback($uid,$amount,'electricity',null);return['success'=>true,'message'=>"Token: {$tok}",'token'=>$tok,'ref'=>$debit['ref'],'cashback'=>$cb];
            }
            return['success'=>true,'message'=>'Purchase successful','ref'=>$debit['ref']];
        }

        if ($last_prov) db()->prepare("UPDATE transactions SET provider=? WHERE transaction_ref=?")->execute([$last_prov['provider_name'],$debit['ref']]);
        $provider_resp = provider_response_text($last_res);
        if ($has_pending) {
            $prov_ref = $last_res['id'] ?? $last_res['data']['transaction']['reference'] ?? $last_res['data']['id'] ?? $last_res['data']['reference'] ?? $last_res['reference'] ?? '';update_tx_status($debit['ref'],'pending',$prov_ref);save_tx_response($debit['ref'], $provider_resp);notify_user($uid,'Electricity Processing',"Your electricity purchase for meter {$meter} is being processed. Check back later.",'info',null,$debit['balance_before'],$debit['balance_after']);return['success'=>true,'message'=>'Transaction is being processed. Check receipt for updates.','ref'=>$debit['ref'],'pending'=>true];
        }

        // A network-level failure on the final attempt means we don't
        // actually know whether that provider processed the request —
        // electricity purchases are the highest-value/highest-risk case for
        // this class of bug (a lost response after the disco actually issued
        // a token means refunding would lose the token cost AND give the
        // money back). Leave it 'processing' for the verification crons
        // instead of refunding blindly.
        // A "duplicate reference" reply means the provider already has a
        // record of this reference from somewhere — outcome unknown, not a
        // clean decline — treated the same as a network error, same
        // reasoning as the comment above about not refunding blindly.
        if (!empty($last_res['_network_error']) || is_duplicate_reference_response($last_res)) {
            update_tx_status($debit['ref'],'processing');
            save_tx_response($debit['ref'], $provider_resp);
            notify_user($uid,'Electricity Processing',"We're confirming your electricity purchase for meter {$meter} with the provider. This can take a few minutes.",'info',null,$debit['balance_before'],$debit['balance_after']);
            return['success'=>true,'message'=>'We could not immediately confirm this purchase due to a network issue. It is being verified — check your receipt shortly.','ref'=>$debit['ref'],'pending'=>true];
        }

        $err='Purchase failed. Wallet reversed.';
        if(!empty($last_res['message'])) $err=$last_res['message'];
        elseif(!empty($last_res['error'])) $err=is_array($last_res['error'])?json_encode($last_res['error']):$last_res['error'];
        // Refund the full debited amount (amount + service charge) — this
        // previously only refunded $amount, silently keeping the charge on a
        // purchase that never went through.
        refund_failed_transaction($debit['ref'], $uid, $total, $err);
        save_tx_response($debit['ref'], $provider_resp ?: $err);
        return['success'=>true,'message'=>$err,'ref'=>$debit['ref'],'refunded'=>true];
      } catch (\Throwable $e) {
        if ($debit !== null && ($debit['success'] ?? false)) {
            refund_failed_transaction($debit['ref'], $uid, $total, 'System error');
        }
        log_error('electricity', 'Purchase exception: ' . $e->getMessage(), ['line' => $e->getLine(), 'uid' => $uid]);
        return ['success' => false, 'message' => 'Purchase failed. Please try again.'];
      }
    }
}
function process_electricity(int $uid,array $input):array{return(new ElectricityService())->process($uid,$input);}
function validate_meter(int $did,string $meter,string $type):array{return(new ElectricityService())->validate_meter($did,$meter,$type);}
