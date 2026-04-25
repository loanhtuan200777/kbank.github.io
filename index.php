<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

$config=['site_name'=>'K-GAME Tài Xỉu 3D','admin_password'=>'admin123','data_file'=>__DIR__.'/data.json'];

function loadData($f){
    if(!file_exists($f)){
        $d=['users'=>[
            ['id'=>1,'username'=>'demo','password'=>md5('demo123'),'balance'=>500000,'avatar'=>'D','friends'=>[2,3],'created'=>date('Y-m-d')],
            ['id'=>2,'username'=>'alice','password'=>md5('alice123'),'balance'=>250000,'avatar'=>'A','friends'=>[1],'created'=>date('Y-m-d')],
            ['id'=>3,'username'=>'bob','password'=>md5('bob123'),'balance'=>180000,'avatar'=>'B','friends'=>[1],'created'=>date('Y-m-d')],
        ],'chat_messages'=>[
            ['id'=>1,'user_id'=>2,'username'=>'alice','avatar'=>'A','message'=>'Ai chơi chung không?','time'=>date('H:i')],
            ['id'=>2,'user_id'=>3,'username'=>'bob','avatar'=>'B','message'=>'Mình vào! Đặt TAI đi','time'=>date('H:i')],
        ],'private_messages'=>[],'game_history'=>[],'lixi_gifts'=>[]];
        file_put_contents($f,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        return $d;
    }
    return json_decode(file_get_contents($f),true);
}
function saveData($f,$d){file_put_contents($f,json_encode($d,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));}
function getUser($data){if(!isset($_SESSION['uid']))return null;foreach($data['users']as $u)if($u['id']==$_SESSION['uid'])return $u;return null;}
function findById($data,$id){foreach($data['users']as $u)if($u['id']==$id)return $u;return null;}

$data=loadData($config['data_file']);

// ── API ──────────────────────────────────────────────────────
if(isset($_GET['api'])){
    header('Content-Type: application/json');
    $api=$_GET['api'];
    $user=getUser($data);

    if($api==='login'){
        $un=trim($_POST['username']??'');$pw=$_POST['password']??'';
        foreach($data['users']as $u)if($u['username']===$un&&$u['password']===md5($pw)){
            $_SESSION['uid']=$u['id'];
            echo json_encode(['ok'=>true,'user'=>['id'=>$u['id'],'username'=>$u['username'],'balance'=>$u['balance'],'avatar'=>$u['avatar']]]);exit;
        }
        echo json_encode(['ok'=>false,'msg'=>'Sai tài khoản hoặc mật khẩu']);exit;
    }
    if($api==='register'){
        $un=trim($_POST['username']??'');$pw=$_POST['password']??'';
        if(strlen($un)<3){echo json_encode(['ok'=>false,'msg'=>'Tên ít nhất 3 ký tự']);exit;}
        foreach($data['users']as $u)if($u['username']===$un){echo json_encode(['ok'=>false,'msg'=>'Tên đã tồn tại']);exit;}
        $id=max(array_column($data['users'],'id'))+1;
        $nu=['id'=>$id,'username'=>$un,'password'=>md5($pw),'balance'=>100000,'avatar'=>strtoupper($un[0]),'friends'=>[],'created'=>date('Y-m-d')];
        $data['users'][]=$nu;saveData($config['data_file'],$data);
        $_SESSION['uid']=$id;
        echo json_encode(['ok'=>true,'user'=>['id'=>$id,'username'=>$un,'balance'=>100000,'avatar'=>$nu['avatar']]]);exit;
    }
    if($api==='logout'){session_destroy();echo json_encode(['ok'=>true]);exit;}

    // GAME RESOLVE (server-side dice)
    if($api==='resolve'){
        if(!$user){echo json_encode(['ok'=>false,'msg'=>'Chưa đăng nhập']);exit;}
        $bets=json_decode($_POST['bets']??'{}',true);
        if(!is_array($bets))$bets=[];
        $total=0;foreach($bets as $k=>&$v){$v=max(0,(int)$v);$total+=$v;}
        if($total>0&&$total>$user['balance']){echo json_encode(['ok'=>false,'msg'=>'Không đủ số dư!']);exit;}
        $uidx=null;foreach($data['users']as $i=>&$u)if($u['id']==$user['id']){$uidx=$i;break;}
        $data['users'][$uidx]['balance']-=$total;
        $d=[rand(1,6),rand(1,6),rand(1,6)];$sum=array_sum($d);
        $tai=$sum>=11;$chan=$sum%2===0;
        $win=0;
        foreach($bets as $k=>$amt){
            if($amt<=0)continue;
            $hit=($k==='TAI'&&$tai)||($k==='XIU'&&!$tai)||($k==='CHAN'&&$chan)||($k==='LE'&&!$chan);
            if($hit)$win+=$amt*1.98;
        }
        $data['users'][$uidx]['balance']+=(int)$win;
        $data['game_history'][]=['d'=>$d,'sum'=>$sum,'tai'=>$tai,'bets'=>$bets,'win'=>(int)$win,'lost'=>$total,'user'=>$user['username'],'time'=>date('H:i:s')];
        saveData($config['data_file'],$data);
        echo json_encode(['ok'=>true,'d1'=>$d[0],'d2'=>$d[1],'d3'=>$d[2],'sum'=>$sum,'is_tai'=>$tai,'is_chan'=>$chan,'win'=>(int)$win,'lost'=>$total,'balance'=>$data['users'][$uidx]['balance']]);exit;
    }

    if($api==='get_chat'){
        $type=$_GET['type']??'public';$with=intval($_GET['with']??0);
        if($type==='public'){$msgs=array_slice($data['chat_messages'],-50);}
        else{$msgs=array_values(array_filter($data['private_messages'],function($m)use($user,$with){return($m['from_id']==$user['id']&&$m['to_id']==$with)||($m['from_id']==$with&&$m['to_id']==$user['id']);}));}
        echo json_encode(['ok'=>true,'messages'=>array_values($msgs)]);exit;
    }
    if($api==='send_chat'){
        if(!$user){echo json_encode(['ok'=>false]);exit;}
        $msg=trim($_POST['message']??'');if(empty($msg)){echo json_encode(['ok'=>false]);exit;}
        $type=$_POST['type']??'public';$toId=intval($_POST['to_id']??0);
        $nm=['id'=>time(),'user_id'=>$user['id'],'username'=>$user['username'],'avatar'=>$user['avatar'],'message'=>htmlspecialchars($msg),'time'=>date('H:i')];
        if($type==='public'){$data['chat_messages'][]=$nm;if(count($data['chat_messages'])>200)$data['chat_messages']=array_slice($data['chat_messages'],-200);}
        else{$nm['from_id']=$user['id'];$nm['to_id']=$toId;$data['private_messages'][]=$nm;}
        saveData($config['data_file'],$data);echo json_encode(['ok'=>true]);exit;
    }
    if($api==='get_friends'){
        if(!$user){echo json_encode(['ok'=>false,'friends'=>[]]);exit;}
        $fr=[];foreach($user['friends']??[]as $fid){$f=findById($data,$fid);if($f)$fr[]=['id'=>$f['id'],'username'=>$f['username'],'avatar'=>$f['avatar']];}
        echo json_encode(['ok'=>true,'friends'=>$fr]);exit;
    }
    if($api==='search_users'){
        $q=trim($_GET['q']??'');$res=[];
        foreach($data['users']as $u){
            if($user&&$u['id']==$user['id'])continue;
            if(stripos($u['username'],$q)!==false)$res[]=['id'=>$u['id'],'username'=>$u['username'],'avatar'=>$u['avatar'],'is_friend'=>$user&&in_array($u['id'],$user['friends']??[])];
        }
        echo json_encode(['ok'=>true,'users'=>$res]);exit;
    }
    if($api==='add_friend'){
        if(!$user){echo json_encode(['ok'=>false]);exit;}
        $fid=intval($_POST['friend_id']??0);
        foreach($data['users']as &$u){
            if($u['id']==$user['id']&&!in_array($fid,$u['friends']??[]))$u['friends'][]=$fid;
            if($u['id']==$fid&&!in_array($user['id'],$u['friends']??[]))$u['friends'][]=$user['id'];
        }
        saveData($config['data_file'],$data);echo json_encode(['ok'=>true,'msg'=>'Đã kết bạn!']);exit;
    }
    if($api==='send_lixi'){
        if(!$user){echo json_encode(['ok'=>false]);exit;}
        $toId=intval($_POST['to_id']??0);$amt=intval($_POST['amount']??0);
        if($amt<1000){echo json_encode(['ok'=>false,'msg'=>'Lì xì tối thiểu 1,000đ']);exit;}
        $ui=null;$ti=null;foreach($data['users']as $i=>&$u){if($u['id']==$user['id'])$ui=$i;if($u['id']==$toId)$ti=$i;}
        if($ui===null||$ti===null){echo json_encode(['ok'=>false,'msg'=>'Không tìm thấy']);exit;}
        if($data['users'][$ui]['balance']<$amt){echo json_encode(['ok'=>false,'msg'=>'Không đủ tiền']);exit;}
        $data['users'][$ui]['balance']-=$amt;$data['users'][$ti]['balance']+=$amt;
        saveData($config['data_file'],$data);
        echo json_encode(['ok'=>true,'msg'=>'Đã gửi lì xì '.number_format($amt).'đ!','balance'=>$data['users'][$ui]['balance']]);exit;
    }
    if($api==='history'){
        echo json_encode(['ok'=>true,'history'=>array_slice(array_reverse($data['game_history']),0,20)]);exit;
    }
    // CHIA SẺ LỆNH CƯỢC VÀO CHAT
    if($api==='share_bet'){
        if(!$user){echo json_encode(['ok'=>false,'msg'=>'Chưa đăng nhập']);exit;}
        $choice=$_POST['choice']??'';$amount=intval($_POST['amount']??0);
        if(!in_array($choice,['TAI','XIU','CHAN','LE'])||$amount<1000){echo json_encode(['ok'=>false,'msg'=>'Dữ liệu không hợp lệ']);exit;}
        $shareId='s'.time().'_'.$user['id'];
        $nm=[
            'id'=>time(),'user_id'=>$user['id'],'username'=>$user['username'],'avatar'=>$user['avatar'],
            'message'=>'','time'=>date('H:i'),
            'is_share'=>true,'share_id'=>$shareId,'share_choice'=>$choice,'share_amount'=>$amount
        ];
        $data['chat_messages'][]=$nm;
        if(count($data['chat_messages'])>200)$data['chat_messages']=array_slice($data['chat_messages'],-200);
        saveData($config['data_file'],$data);
        echo json_encode(['ok'=>true,'share_id'=>$shareId]);exit;
    }
    // THAM GIA CƯỢC THEO LỆNH CHIA SẺ
    if($api==='join_bet'){
        if(!$user){echo json_encode(['ok'=>false,'msg'=>'Chưa đăng nhập']);exit;}
        $choice=$_POST['choice']??'';$amount=intval($_POST['amount']??0);
        if(!in_array($choice,['TAI','XIU','CHAN','LE'])){echo json_encode(['ok'=>false,'msg'=>'Lựa chọn không hợp lệ']);exit;}
        if($amount<1000){echo json_encode(['ok'=>false,'msg'=>'Cược tối thiểu 1,000đ']);exit;}
        $uidx=null;foreach($data['users']as $i=>&$u)if($u['id']==$user['id']){$uidx=$i;break;}
        if($data['users'][$uidx]['balance']<$amount){echo json_encode(['ok'=>false,'msg'=>'Không đủ số dư!']);exit;}
        $data['users'][$uidx]['balance']-=$amount;
        saveData($config['data_file'],$data);
        // Gửi tin chat xác nhận tham gia
        $nm=['id'=>time()+1,'user_id'=>$user['id'],'username'=>$user['username'],'avatar'=>$user['avatar'],
            'message'=>'🤝 Tôi đặt cùng <b>'.$choice.'</b> '.number_format($amount).'đ!','time'=>date('H:i')];
        $data['chat_messages'][]=$nm;saveData($config['data_file'],$data);
        echo json_encode(['ok'=>true,'balance'=>$data['users'][$uidx]['balance'],'choice'=>$choice,'amount'=>$amount,'msg'=>'Đã đặt '.$choice.' '.number_format($amount).'đ!']);exit;
    }
    echo json_encode(['ok'=>false,'msg'=>'Unknown']);exit;
}

$currentUser=getUser($data);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>K-GAME Tài Xỉu 3D</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;-webkit-tap-highlight-color:transparent}
:root{--blue:#1565c0;--blue-dk:#0d47a1;--red:#e53935;--green:#2ecc71;--gold:#f1c40f;--bd:#c5d8ff;--bg:#f0f5ff;--sh:0 2px 12px rgba(21,101,192,.13)}
body{font-family:'Segoe UI',sans-serif;background:#dce8f8;display:flex;justify-content:center;min-height:100vh}
.app{width:100%;max-width:460px;min-height:100vh;background:var(--bg);display:flex;flex-direction:column;position:relative;overflow:hidden}

/* HEADER */
.hdr{background:linear-gradient(135deg,var(--blue-dk),var(--blue));padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0}
.hdr-title{color:#fff;font-weight:900;font-size:16px;letter-spacing:1px}
.hdr-bal{font-size:12px;color:#90caf9;text-align:right}
.hdr-bal b{color:#ffd54f;font-size:15px;display:block}
.btn-login{background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4);border-radius:20px;padding:6px 14px;cursor:pointer;font-size:13px}

/* GAME CENTER */
.game-wrap{padding:14px;display:flex;flex-direction:column;align-items:center;gap:10px;flex-shrink:0}
.dice-circle{position:relative;width:180px;height:180px;background:#fff;border:3px solid var(--bd);border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 24px rgba(21,101,192,.18)}
#timer{font-size:72px;font-weight:900;color:var(--blue);transition:color .3s}
#timer.danger{color:var(--red);animation:pulse .5s infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
#dice-wrap{display:none;position:absolute;width:100%;height:100%;pointer-events:none}
#d1{position:absolute;top:28px;left:60px;width:54px}
#d2{position:absolute;top:84px;left:26px;width:54px}
#d3{position:absolute;top:84px;left:96px;width:54px}
#sum-bar{font-size:14px;color:#555;background:#e8f0fe;border-radius:8px;padding:6px 16px;border:1px solid var(--bd);min-height:32px;text-align:center;width:100%}

/* BET GRID */
.bet-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%;padding:0 14px}
.bet-box{position:relative;overflow:hidden;background:#fff;border:2.5px solid var(--bd);border-radius:14px;height:108px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;transition:all .15s;box-shadow:var(--sh)}
.bet-box:active{transform:scale(.94)}
.bet-box.locked{opacity:.3;pointer-events:none}
.bet-box.on-TAI{border-color:#e53935;background:#fff5f5}
.bet-box.on-XIU{border-color:#1565c0;background:#e8f0fe}
.bet-box.on-CHAN{border-color:#2ecc71;background:#f0fff8}
.bet-box.on-LE{border-color:var(--gold);background:#fffde7}
.bl{font-size:32px;font-weight:900}
.c-tai{color:#e53935}.c-xiu{color:#1565c0}.c-chan{color:#2ecc71}.c-le{color:#e6ac00}
.bsub{font-size:11px;color:#999;margin-top:2px}
.bamt{font-size:13px;font-weight:800;color:#27ae60;min-height:18px;margin-top:4px}
.chip-el{position:absolute;width:28px;height:28px;background:radial-gradient(circle,var(--gold),#e67e22);border:2px solid rgba(255,255,255,.7);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:7px;font-weight:900;color:#333;pointer-events:none}

/* CHIP BAR */
.chip-bar{display:flex;justify-content:center;gap:10px;padding:10px 14px;background:#e8f0fe;border-top:1px solid var(--bd);border-bottom:1px solid var(--bd);width:100%}
.chip-btn{width:58px;height:58px;border-radius:50%;border:2.5px dashed var(--bd);background:#fff;color:#555;font-weight:900;cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;transition:all .15s;box-shadow:var(--sh)}
.chip-btn:active{transform:scale(.88)}
.chip-btn.sel{transform:translateY(-8px);background:radial-gradient(circle,#ffe066,#e67e22);color:#333;border-style:solid;border-color:var(--gold);box-shadow:0 6px 14px rgba(241,196,15,.4)}
.status-bar{text-align:center;padding:6px 14px;font-size:12px;color:#888;min-height:26px;flex-shrink:0}

/* FLOAT CHAT BUTTON */
#floatChatBtn{position:fixed;width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--blue-dk));color:#fff;border:none;font-size:22px;cursor:pointer;box-shadow:0 4px 16px rgba(21,101,192,.45);display:flex;align-items:center;justify-content:center;z-index:300;touch-action:none;right:16px;bottom:80px;transition:box-shadow .2s}
#floatChatBtn:active{box-shadow:0 2px 8px rgba(21,101,192,.3)}

/* CHAT OVERLAY */
#chatOverlay{position:fixed;background:#fff;border-radius:18px 18px 0 0;bottom:0;left:0;right:0;height:68vh;max-width:460px;margin:0 auto;box-shadow:0 -4px 24px rgba(0,0,0,.18);display:flex;flex-direction:column;transform:translateY(100%);transition:transform .3s cubic-bezier(.25,.8,.25,1);z-index:400}
#chatOverlay.open{transform:translateY(0)}
#chatOverlayHeader{background:linear-gradient(135deg,var(--blue-dk),var(--blue));border-radius:18px 18px 0 0;padding:10px 14px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
#chatOverlayTitle{color:#fff;font-weight:700;font-size:14px}
.chat-tab-btn{padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;border:1px solid rgba(255,255,255,.4);background:rgba(255,255,255,.15);color:#fff}
.chat-tab-btn.active{background:#fff;color:var(--blue);border-color:#fff}
.chat-msgs{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:7px;background:#f8faff}
.cmsg{display:flex;gap:7px;align-items:flex-end}
.cmsg.me{flex-direction:row-reverse}
.av{width:28px;height:28px;border-radius:50%;background:var(--blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0}
.bub{max-width:72%;background:#fff;border-radius:14px;padding:7px 11px;font-size:12px;line-height:1.4;border:1px solid #e8f0fe;word-break:break-word}
.cmsg.me .bub{background:#e8f0fe;border-color:#c5d8ff}
.bub .sn{font-size:9px;color:#999;margin-bottom:2px;font-weight:700}
.bub .tm{font-size:9px;color:#bbb;margin-top:2px}
.chat-in-row{display:flex;gap:8px;padding:8px 12px;border-top:1px solid var(--bd);flex-shrink:0;background:#fff}
.chat-in{flex:1;padding:9px 13px;border:1px solid var(--bd);border-radius:22px;font-size:13px;outline:none;background:#f8faff}
.chat-send{width:36px;height:36px;border-radius:50%;background:var(--blue);color:#fff;border:none;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center}

/* SHARE CARD TRONG CHAT */
.share-card{background:linear-gradient(135deg,#fffde7,#fff8e1);border:2px solid #f59e0b;border-radius:14px;padding:12px 14px;max-width:86%;cursor:default}
.share-card .sc-head{display:flex;align-items:center;gap:7px;margin-bottom:8px}
.share-card .sc-avatar{width:26px;height:26px;border-radius:50%;background:var(--blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0}
.share-card .sc-name{font-size:12px;font-weight:700;color:#333}
.share-card .sc-time{font-size:10px;color:#aaa;margin-left:auto}
.share-card .sc-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;background:#fff3cd;color:#92400e;margin-bottom:6px}
.share-card .sc-choice{font-size:26px;font-weight:900;line-height:1.1}
.sc-TAI{color:#e53935}.sc-XIU{color:#1565c0}.sc-CHAN{color:#2ecc71}.sc-LE{color:#e6ac00}
.share-card .sc-amt{font-size:12px;color:#666;margin:3px 0 10px}
.share-card .sc-join{width:100%;padding:9px;background:linear-gradient(135deg,#1565c0,#0d47a1);color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(21,101,192,.3)}

/* JOIN MODAL */
#joinModal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:800;display:flex;align-items:flex-end;justify-content:center}
#joinModal.hidden{display:none}
.join-box{background:#fff;border-radius:20px 20px 0 0;width:100%;max-width:460px;padding:22px}
.join-box h3{font-size:17px;font-weight:700;margin-bottom:8px}
.jc-info{font-size:13px;color:#555;padding:10px 14px;border-radius:10px;background:#f0f5ff;border:1px solid var(--bd);margin-bottom:14px;line-height:1.6}
.join-amts{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.ja{padding:8px 15px;border-radius:20px;border:2px solid var(--bd);background:#f0f5ff;color:#555;font-weight:700;cursor:pointer;font-size:13px}
.ja.active{background:var(--blue);color:#fff;border-color:var(--blue)}
.join-custom{width:100%;padding:11px 14px;border:1.5px solid var(--bd);border-radius:10px;font-size:14px;margin-bottom:14px;outline:none;text-align:center}
.join-custom:focus{border-color:var(--blue)}

/* TAB BAR */
.tab-bar{display:flex;background:#fff;border-top:1px solid var(--bd);flex-shrink:0;box-shadow:0 -2px 8px rgba(0,0,0,.07)}
.tab{flex:1;display:flex;flex-direction:column;align-items:center;padding:7px 4px;cursor:pointer;color:#999;font-size:10px;gap:2px}
.tab.active{color:var(--blue)}
.tab-ic{font-size:19px}

/* PANELS */
.panel{position:absolute;top:0;left:0;right:0;bottom:0;background:var(--bg);z-index:200;display:flex;flex-direction:column;transform:translateX(100%);transition:transform .3s}
.panel.open{transform:translateX(0)}
.panel-hdr{padding:12px 14px;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:10px;background:#fff;flex-shrink:0}
.panel-hdr h2{font-size:15px;font-weight:700;flex:1}
.back-btn{width:30px;height:30px;border-radius:50%;background:#f0f5ff;border:none;cursor:pointer;font-size:15px}
.panel-body{flex:1;overflow-y:auto;padding:12px}
.social-tabs{display:flex;border-bottom:1px solid var(--bd);flex-shrink:0;background:#fff}
.stab{flex:1;padding:9px 4px;text-align:center;font-size:11px;font-weight:700;cursor:pointer;color:#999;border-bottom:2px solid transparent}
.stab.active{color:var(--blue);border-color:var(--blue)}
.fi{display:flex;align-items:center;gap:9px;padding:10px;border-radius:12px;background:#fff;margin-bottom:8px;box-shadow:var(--sh)}
.fi-info{flex:1}
.fi-name{font-size:13px;font-weight:700}
.fi-sub{font-size:11px;color:#999}
.btn-sm{padding:5px 11px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;border:none}
.btn-lixi{background:#f59e0b;color:#fff}
.btn-msg{background:#e8f0fe;color:var(--blue)}
.btn-add{background:#dcfce7;color:#15803d}
.search-in{width:100%;padding:10px 13px;border:1px solid var(--bd);border-radius:10px;font-size:13px;margin-bottom:10px;outline:none}

/* LIXI MODAL */
.modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:500;display:flex;align-items:center;justify-content:center}
.modal-bg.hidden{display:none}
.modal-box{background:#fff;border-radius:18px;padding:22px;width:88%;max-width:340px;text-align:center}
.modal-box h3{color:#f59e0b;font-size:17px;margin-bottom:14px}
.lixi-amts{display:flex;flex-wrap:wrap;gap:7px;justify-content:center;margin-bottom:14px}
.la{padding:7px 14px;border-radius:20px;border:2px solid #f59e0b;background:#fffbeb;color:#92400e;font-weight:700;cursor:pointer;font-size:12px}
.la.active{background:#f59e0b;color:#fff}

/* AUTH MODAL */
.auth-modal{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:600;display:flex;align-items:flex-end;justify-content:center}
.auth-modal.hidden{display:none}
.auth-box{background:#fff;border-radius:18px 18px 0 0;width:100%;max-width:460px;padding:22px}
.auth-box h2{font-size:17px;font-weight:700;margin-bottom:18px}
.fg{margin-bottom:12px}
.fg label{font-size:12px;color:#999;display:block;margin-bottom:4px}
.fg input{width:100%;padding:11px 13px;border:1px solid var(--bd);border-radius:9px;font-size:14px;outline:none}
.btn-primary{width:100%;padding:13px;background:var(--blue);color:#fff;border:none;border-radius:11px;font-size:14px;font-weight:700;cursor:pointer;margin-top:4px}
.btn-link{width:100%;padding:8px;background:none;border:none;color:var(--blue);font-size:12px;cursor:pointer;margin-top:8px}

/* WIN OVERLAY */
#win-ov{position:fixed;inset:0;display:none;z-index:9999;pointer-events:none;align-items:center;justify-content:center;background:rgba(0,0,0,.55);flex-direction:column;gap:8px}
#win-ov.show{display:flex}
.ov-win{font-size:60px;font-weight:900;color:var(--gold);text-shadow:0 0 30px rgba(241,196,15,.8);animation:pop .4s cubic-bezier(.175,.885,.32,1.275) forwards}
.ov-amt{font-size:36px;font-weight:900;animation:pop .4s .12s cubic-bezier(.175,.885,.32,1.275) both}
.ov-amt.w{color:#6eff7a;text-shadow:0 0 20px rgba(110,255,122,.7)}
.ov-amt.l{color:var(--red)}
@keyframes pop{0%{transform:scale(0);opacity:0}100%{transform:scale(1);opacity:1}}

/* TOAST */
.toast{position:fixed;top:68px;left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;padding:9px 18px;border-radius:22px;font-size:12px;z-index:999;opacity:0;transition:opacity .3s;pointer-events:none;white-space:nowrap}
.toast.show{opacity:1}

/* HIST */
.hist-dots{display:flex;flex-wrap:wrap;gap:6px;padding:10px 14px}
.hdot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:900}
.hdot.T{background:linear-gradient(135deg,var(--red),#b71c1c)}
.hdot.X{background:linear-gradient(135deg,var(--blue),var(--blue-dk))}
</style>
</head>
<body>
<div class="app">
<div id="win-ov"></div>

<!-- HEADER -->
<div class="hdr">
  <div class="hdr-title">🎲 K-GAME 3D</div>
  <?php if($currentUser):?>
  <div class="hdr-bal">Số dư <b id="balDisplay"><?=number_format($currentUser['balance'])?>đ</b></div>
  <?php else:?>
  <button class="btn-login" onclick="openAuth('login')">Đăng nhập</button>
  <?php endif;?>
</div>

<!-- GAME CENTER -->
<div class="game-wrap">
  <div class="dice-circle">
    <div id="timer">30</div>
    <div id="dice-wrap"><div id="d1"></div><div id="d2"></div><div id="d3"></div></div>
  </div>
  <div id="sum-bar">Chọn chip và đặt cược!</div>
</div>

<!-- BET GRID -->
<div class="bet-grid">
  <div class="bet-box" id="box-TAI" onclick="doBet('TAI')"><span class="bl c-tai">TÀI</span><span class="bsub">11–18</span><div class="bamt" id="v-TAI"></div></div>
  <div class="bet-box" id="box-XIU" onclick="doBet('XIU')"><span class="bl c-xiu">XỈU</span><span class="bsub">3–10</span><div class="bamt" id="v-XIU"></div></div>
  <div class="bet-box" id="box-CHAN" onclick="doBet('CHAN')"><span class="bl c-chan">CHẴN</span><span class="bsub">Tổng chẵn</span><div class="bamt" id="v-CHAN"></div></div>
  <div class="bet-box" id="box-LE" onclick="doBet('LE')"><span class="bl c-le">LẺ</span><span class="bsub">Tổng lẻ</span><div class="bamt" id="v-LE"></div></div>
</div>

<!-- CHIP BAR -->
<div class="chip-bar">
  <div class="chip-btn" onclick="selChip(this,1000)">1K</div>
  <div class="chip-btn" onclick="selChip(this,10000)">10K</div>
  <div class="chip-btn" onclick="selChip(this,50000)">50K</div>
  <div class="chip-btn" onclick="selChip(this,500000)">500K</div>
</div>
<div class="status-bar" id="status-bar">Chọn chip rồi nhấn ô đặt cược!</div>
<div id="share-bar" style="display:none;padding:0 14px 10px;justify-content:center">
  <button onclick="shareBet()" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;border-radius:22px;padding:10px 22px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 3px 10px rgba(245,158,11,.4);display:flex;align-items:center;gap:6px">📤 Chia sẻ lệnh cược lên Chat</button>
</div>

<!-- FLOAT CHAT BUTTON -->
<button id="floatChatBtn" onclick="toggleChatOverlay()" title="Chat">💬</button>

<!-- CHAT OVERLAY -->
<div id="chatOverlay">
  <div id="chatOverlayHeader">
    <span id="chatOverlayTitle">💬 Chat</span>
    <div style="display:flex;gap:6px;align-items:center">
      <button class="chat-tab-btn active" onclick="setChatType('public',this)">Cộng đồng</button>
      <button class="chat-tab-btn" onclick="setChatType('friends',this)">Bạn bè</button>
      <button onclick="toggleChatOverlay()" style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:50%;width:26px;height:26px;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center">✕</button>
    </div>
  </div>
  <div class="chat-msgs" id="chatMsgs"></div>
  <div class="chat-in-row">
    <input class="chat-in" id="chatIn" placeholder="Nhắn tin..." onkeydown="if(event.key==='Enter')sendChat()">
    <button class="chat-send" onclick="sendChat()">➤</button>
  </div>
</div>

<!-- TAB BAR -->
<div class="tab-bar">
  <div class="tab active" onclick="setTab('game',this)"><div class="tab-ic">🎲</div>Game</div>
  <div class="tab" onclick="setTab('social',this)"><div class="tab-ic">👥</div>Bạn bè</div>
  <div class="tab" onclick="setTab('history',this)"><div class="tab-ic">📊</div>Lịch sử</div>
  <div class="tab" onclick="setTab('lixi',this)"><div class="tab-ic">🧧</div>Lì xì</div>
  <?php if($currentUser):?>
  <div class="tab" onclick="logout()"><div class="tab-ic">🚪</div>Thoát</div>
  <?php else:?>
  <div class="tab" onclick="openAuth('login')"><div class="tab-ic">👤</div>Đăng nhập</div>
  <?php endif;?>
</div>

<!-- SOCIAL PANEL -->
<div class="panel" id="socialPanel">
  <div class="panel-hdr"><button class="back-btn" onclick="closePanel('socialPanel')">←</button><h2>👥 Bạn bè</h2></div>
  <div class="social-tabs">
    <div class="stab active" onclick="stab('friends',this)">Bạn bè</div>
    <div class="stab" onclick="stab('search',this)">Tìm kiếm</div>
  </div>
  <div class="panel-body" id="socialBody"></div>
</div>

<!-- HISTORY PANEL -->
<div class="panel" id="histPanel">
  <div class="panel-hdr"><button class="back-btn" onclick="closePanel('histPanel')">←</button><h2>📊 Lịch sử</h2></div>
  <div id="histDots" class="hist-dots"></div>
</div>

<!-- LIXI MODAL -->
<div class="modal-bg hidden" id="lixiModal">
  <div class="modal-box">
    <h3>🧧 Tặng Lì Xì</h3>
    <p id="lixiTarget" style="font-size:13px;color:#999;margin-bottom:12px"></p>
    <div class="lixi-amts">
      <button class="la active" onclick="setLA(this,5000)">5K</button>
      <button class="la" onclick="setLA(this,10000)">10K</button>
      <button class="la" onclick="setLA(this,20000)">20K</button>
      <button class="la" onclick="setLA(this,50000)">50K</button>
      <button class="la" onclick="setLA(this,100000)">100K</button>
    </div>
    <input type="number" id="lixiCustom" placeholder="Số khác..." style="width:100%;padding:9px;border:1px solid var(--bd);border-radius:9px;font-size:13px;margin-bottom:12px;text-align:center">
    <button class="btn-primary" onclick="sendLixi()">🧧 Gửi Lì Xì</button>
    <button onclick="document.getElementById('lixiModal').classList.add('hidden')" style="margin-top:8px;width:100%;padding:9px;background:#f0f5ff;border:none;border-radius:9px;cursor:pointer;color:#999">Hủy</button>
  </div>
</div>

<!-- JOIN BET MODAL -->
<div id="joinModal" class="hidden">
  <div class="join-box">
    <h3>🤝 Đặt cùng lệnh</h3>
    <div class="jc-info" id="joinInfo"></div>
    <div class="join-amts">
      <button class="ja active" onclick="setJA(this,10000)">10K</button>
      <button class="ja" onclick="setJA(this,20000)">20K</button>
      <button class="ja" onclick="setJA(this,50000)">50K</button>
      <button class="ja" onclick="setJA(this,100000)">100K</button>
      <button class="ja" onclick="setJA(this,200000)">200K</button>
    </div>
    <input class="join-custom" type="number" id="joinCustom" placeholder="Nhập số tiền khác...">
    <button class="btn-primary" onclick="confirmJoin()">🎲 Xác nhận đặt cược</button>
    <button onclick="document.getElementById('joinModal').classList.add('hidden')" style="margin-top:10px;width:100%;padding:10px;background:#f0f5ff;border:none;border-radius:10px;cursor:pointer;color:#888;font-size:13px">Hủy</button>
  </div>
</div>

<!-- AUTH MODAL -->
<div class="auth-modal hidden" id="authModal">
  <div class="auth-box">
    <h2 id="authTitle">Đăng nhập</h2>
    <div class="fg"><label>Tên đăng nhập</label><input id="authUser" placeholder="Username" autocomplete="username"></div>
    <div class="fg"><label>Mật khẩu</label><input type="password" id="authPass" placeholder="Password" autocomplete="current-password"></div>
    <p id="authErr" style="color:#e53935;font-size:12px;margin-bottom:8px"></p>
    <button class="btn-primary" onclick="submitAuth()" id="authBtn">Đăng nhập</button>
    <button class="btn-link" onclick="toggleAuthMode()">Chưa có tài khoản? Đăng ký ngay</button>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── DICE RENDER ─────────────────────────────────────────────
var DP={1:[[50,50]],2:[[28,28],[72,72]],3:[[28,28],[50,50],[72,72]],
        4:[[28,28],[72,28],[28,72],[72,72]],5:[[28,28],[72,28],[50,50],[28,72],[72,72]],
        6:[[28,22],[72,22],[28,50],[72,50],[28,78],[72,78]]};
function drawDie(id,n){
  var el=document.getElementById(id);if(!el)return;
  var s='';DP[n].forEach(function(p){var c=(n===1||(n===5&&p[0]===50))?'#e53935':'#333';s+='<circle cx="'+p[0]+'" cy="'+p[1]+'" r="9" fill="'+c+'"/>';});
  el.innerHTML='<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg"><rect x="4" y="4" width="92" height="92" rx="18" fill="white" stroke="#c5d8ff" stroke-width="3"/>'+s+'</svg>';
}
function animDice(f,cb){
  var n=0,max=14;
  var iv=setInterval(function(){
    drawDie('d1',Math.ceil(Math.random()*6));drawDie('d2',Math.ceil(Math.random()*6));drawDie('d3',Math.ceil(Math.random()*6));
    if(++n>=max){clearInterval(iv);drawDie('d1',f[0]);drawDie('d2',f[1]);drawDie('d3',f[2]);if(cb)cb();}
  },65);
}

// ── STATE ───────────────────────────────────────────────────
var bal=<?=$currentUser?$currentUser['balance']:0?>;
var bets={TAI:0,XIU:0,CHAN:0,LE:0};
var locks={TAI:false,XIU:false,CHAN:false,LE:false};
var chipVal=0,timeLeft=30,isOpening=false,mainTimer=null;
var cUser=<?=$currentUser?json_encode(['id'=>$currentUser['id'],'username'=>$currentUser['username'],'avatar'=>$currentUser['avatar']]):json_encode(null)?>;
var chatType='public',chatWith=null,authMode='login';
var lixiToId=null,lixiAmt=5000;
var oppMap={TAI:'XIU',XIU:'TAI',CHAN:'LE',LE:'CHAN'};

// ── UTILS ───────────────────────────────────────────────────
function fmt(n){return Math.floor(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,',');}
function updBal(){document.getElementById('balDisplay').textContent=fmt(bal)+'đ';}
function setStatus(m){document.getElementById('status-bar').innerHTML=m;}
function toast(m,d){var t=document.getElementById('toast');t.textContent=m;t.classList.add('show');setTimeout(function(){t.classList.remove('show');},d||2500);}

var joinChoice='',joinAmtVal=10000;

// ── CHIP & BET ───────────────────────────────────────────────
function selChip(el,v){
  document.querySelectorAll('.chip-btn').forEach(function(c){c.classList.remove('sel');});
  el.classList.add('sel');chipVal=v;
  setStatus('Chip <b>'+fmt(v)+'đ</b> — Nhấn ô để đặt!');
}
function doBet(t){
  if(!cUser){openAuth('login');return;}
  if(timeLeft<=0||chipVal===0||isOpening)return;
  if(locks[t]){toast('Không thể đặt '+t+' khi đã đặt '+oppMap[t]+'!');return;}
  if(bal<chipVal){toast('Không đủ số dư!');return;}
  bal-=chipVal;bets[t]+=chipVal;updBal();
  document.getElementById('v-'+t).textContent=fmt(bets[t])+'đ';
  var op=oppMap[t];if(op){locks[op]=true;document.getElementById('box-'+op).classList.add('locked');}
  document.getElementById('box-'+t).classList.add('on-'+t);
  // chip visual
  var chip=document.createElement('div');chip.className='chip-el';
  chip.textContent=chipVal>=1000?(chipVal/1000)+'K':chipVal;
  chip.style.cssText='position:absolute;left:'+(Math.random()*55+15)+'%;top:'+(Math.random()*35+15)+'%;width:28px;height:28px;border-radius:50%;background:radial-gradient(circle,#ffe066,#e67e22);border:2px solid rgba(255,255,255,.7);display:flex;align-items:center;justify-content:center;font-size:7px;font-weight:900;color:#333;pointer-events:none';
  document.getElementById('box-'+t).appendChild(chip);
  var tot=bets.TAI+bets.XIU+bets.CHAN+bets.LE;
  setStatus('Đã cược tổng <b style="color:#e67e22">'+fmt(tot)+'đ</b>');
  // Hiện nút chia sẻ
  document.getElementById('share-bar').style.display='flex';
}

// ── SHARE BET ───────────────────────────────────────────────
async function shareBet(){
  if(!cUser){openAuth('login');return;}
  // Tìm lệnh đã đặt
  var choices=['TAI','XIU','CHAN','LE'];
  var shared=false;
  for(var i=0;i<choices.length;i++){
    var c=choices[i];
    if(bets[c]>0){
      var r=await fetch('?api=share_bet',{method:'POST',body:new URLSearchParams({choice:c,amount:bets[c]})});
      var d=await r.json();
      if(d.ok){shared=true;}
    }
  }
  if(shared){
    toast('📤 Đã chia sẻ lệnh cược lên Chat!');
    document.getElementById('share-bar').style.display='none';
    if(!chatOpen){toggleChatOverlay();}else{loadChat();}
  }else{
    toast('Hãy đặt cược trước!');
  }
}

// ── JOIN BET (từ share card trong chat) ─────────────────────
function openJoinModal(choice, sharerName, sharerAmt){
  if(!cUser){openAuth('login');return;}
  joinChoice=choice;
  var choiceLabel={TAI:'TÀI 🔺',XIU:'XỈU 🔻',CHAN:'CHẴN',LE:'LẺ'};
  document.getElementById('joinInfo').innerHTML=
    '<b>'+sharerName+'</b> đang đặt <b class="sc-'+choice+'">'+choiceLabel[choice]+'</b><br>'
    +'Số tiền họ cược: <b style="color:#e67e22">'+fmt(sharerAmt)+'đ</b><br>'
    +'<span style="font-size:11px;color:#999">Chọn số tiền bạn muốn đặt cùng:</span>';
  document.getElementById('joinCustom').value='';
  document.querySelectorAll('.ja').forEach(function(b){b.classList.remove('active');});
  document.querySelector('.ja').classList.add('active');
  joinAmtVal=10000;
  document.getElementById('joinModal').classList.remove('hidden');
}
function setJA(btn,amt){
  joinAmtVal=amt;
  document.querySelectorAll('.ja').forEach(function(b){b.classList.remove('active');});
  btn.classList.add('active');
  document.getElementById('joinCustom').value='';
}
async function confirmJoin(){
  var custom=document.getElementById('joinCustom').value;
  var amt=custom?parseInt(custom):joinAmtVal;
  if(!amt||amt<1000){toast('Nhập số tiền ít nhất 1,000đ');return;}
  var r=await fetch('?api=join_bet',{method:'POST',body:new URLSearchParams({choice:joinChoice,amount:amt})});
  var d=await r.json();
  if(d.ok){
    bal=d.balance;updBal();
    document.getElementById('joinModal').classList.add('hidden');
    toast('✅ '+d.msg);
    // Thêm vào local bets để hiển thị
    bets[joinChoice]+=amt;
    document.getElementById('v-'+joinChoice).textContent=fmt(bets[joinChoice])+'đ';
    document.getElementById('box-'+joinChoice).classList.add('on-'+joinChoice);
    loadChat();
  }else{
    toast('❌ '+d.msg);
  }
}

// ── GAME LOOP ───────────────────────────────────────────────
function resolveRound(){
  if(!cUser){resetRound(true);return;}
  isOpening=true;
  document.getElementById('timer').style.display='none';
  document.getElementById('dice-wrap').style.display='block';
  var fd=new FormData();fd.append('action','resolve');fd.append('bets',JSON.stringify(bets));
  fetch('?api=resolve',{method:'POST',body:fd})
  .then(function(r){return r.json();})
  .then(function(data){
    if(!data.ok){toast(data.msg||'Lỗi!');resetRound(true);return;}
    bal=data.balance;updBal();
    animDice([data.d1,data.d2,data.d3],function(){showResult(data);});
  }).catch(function(){resetRound(true);});
}
function showResult(data){
  var tx=data.is_tai?'TÀI':'XỈU',cl=data.is_chan?'CHẴN':'LẺ';
  var tc=data.is_tai?'#e53935':'#1565c0',cc=data.is_chan?'#2ecc71':'#e6ac00';
  document.getElementById('sum-bar').innerHTML='Tổng: <b style="color:#e67e22;font-size:18px">'+data.sum+'</b> → <b style="color:'+tc+'">'+tx+'</b> · <b style="color:'+cc+'">'+cl+'</b>';
  showWinOv(data.win,data.lost);
  setTimeout(function(){resetRound(false);},7000);
}
function showWinOv(win,lost){
  var ov=document.getElementById('win-ov');ov.className='show';
  if(win>0){ov.innerHTML='<div class="ov-win">THẮNG!</div><div class="ov-amt w">+'+fmt(win)+'đ</div>';}
  else if(lost>0){ov.innerHTML='<div class="ov-win" style="color:#fc8181">THUA</div><div class="ov-amt l">-'+fmt(lost)+'đ</div>';}
  else{ov.className='';}
  setTimeout(function(){ov.className='';},4500);
}
function resetRound(quick){
  isOpening=false;timeLeft=30;
  bets={TAI:0,XIU:0,CHAN:0,LE:0};locks={TAI:false,XIU:false,CHAN:false,LE:false};
  document.getElementById('dice-wrap').style.display='none';
  var t=document.getElementById('timer');t.style.display='block';t.textContent=timeLeft;t.classList.remove('danger');
  if(!quick)document.getElementById('sum-bar').textContent='Phiên mới! Chọn chip và đặt cược.';
  document.querySelectorAll('.chip-el').forEach(function(c){c.remove();});
  document.querySelectorAll('.bamt').forEach(function(d){d.textContent='';});
  ['TAI','XIU','CHAN','LE'].forEach(function(k){document.getElementById('box-'+k).className='bet-box';});
  setStatus('Chọn chip rồi nhấn ô đặt cược!');
  document.getElementById('share-bar').style.display='none';
}
function startLoop(){
  if(mainTimer)clearInterval(mainTimer);
  mainTimer=setInterval(function(){
    if(!isOpening){
      timeLeft--;var t=document.getElementById('timer');
      if(t){t.textContent=timeLeft;if(timeLeft<=5)t.classList.add('danger');else t.classList.remove('danger');}
      if(timeLeft<=0)resolveRound();
    }
  },1000);
}

// ── FLOAT CHAT DRAG ─────────────────────────────────────────
var chatOpen=false;
var fbtn=document.getElementById('floatChatBtn');
var dragging=false,dox,doy,movedPx=0;
fbtn.addEventListener('touchstart',function(e){
  dragging=false;movedPx=0;
  var t=e.touches[0],r=fbtn.getBoundingClientRect();
  dox=t.clientX-r.left;doy=t.clientY-r.top;
},{passive:true});
fbtn.addEventListener('touchmove',function(e){
  e.preventDefault();dragging=true;
  var t=e.touches[0];movedPx++;
  var x=t.clientX-dox,y=t.clientY-doy;
  fbtn.style.right='auto';fbtn.style.bottom='auto';
  fbtn.style.left=Math.max(0,Math.min(window.innerWidth-52,x))+'px';
  fbtn.style.top=Math.max(60,Math.min(window.innerHeight-120,y))+'px';
},{passive:false});
fbtn.addEventListener('touchend',function(e){if(dragging&&movedPx>5)e.preventDefault();});

function toggleChatOverlay(){
  if(dragging&&movedPx>5){dragging=false;return;}
  chatOpen=!chatOpen;
  document.getElementById('chatOverlay').classList.toggle('open',chatOpen);
  if(chatOpen)loadChat();
}

// ── CHAT ───────────────────────────────────────────────────
async function loadChat(){
  var url='?api=get_chat&type='+chatType+(chatWith?'&with='+chatWith:'');
  var r=await fetch(url);var d=await r.json();
  var el=document.getElementById('chatMsgs');var myId=cUser?cUser.id:0;
  var choiceLabel={TAI:'TÀI 🔺',XIU:'XỈU 🔻',CHAN:'CHẴN',LE:'LẺ'};
  el.innerHTML=d.messages.map(function(m){
    var mine=m.user_id==myId;
    // Share card
    if(m.is_share){
      var isMine=m.user_id==myId;
      return '<div style="display:flex;gap:7px;align-items:flex-end;'+(isMine?'flex-direction:row-reverse':'')+'">'
        +'<div class="av">'+(m.avatar||m.username[0].toUpperCase())+'</div>'
        +'<div class="share-card">'
        +'<div class="sc-head"><span class="sc-name">'+m.username+'</span><span class="sc-time">'+m.time+'</span></div>'
        +'<span class="sc-badge">📤 Chia sẻ lệnh cược</span><br>'
        +'<span class="sc-choice sc-'+m.share_choice+'">'+choiceLabel[m.share_choice]+'</span>'
        +'<div class="sc-amt">Đặt <b>'+fmt(m.share_amount)+'đ</b> — Ai cùng không?</div>'
        +(isMine?'<div style="font-size:11px;color:#aaa;text-align:center">Lệnh của bạn</div>'
          :'<button class="sc-join" onclick="openJoinModal(\''+m.share_choice+'\',\''+m.username+'\','+m.share_amount+')">🤝 Đặt cùng '+choiceLabel[m.share_choice]+'</button>')
        +'</div></div>';
    }
    // Tin nhắn thường
    return '<div class="cmsg '+(mine?'me':'')+'"><div class="av">'+(m.avatar||m.username[0].toUpperCase())+'</div><div class="bub">'
      +(mine?'':'<div class="sn">'+m.username+'</div>')
      +'<div>'+m.message+'</div><div class="tm">'+m.time+'</div></div></div>';
  }).join('');
  el.scrollTop=el.scrollHeight;
}
function setChatType(type,btn){
  chatType=type;chatWith=null;
  document.querySelectorAll('#chatOverlayHeader .chat-tab-btn').forEach(function(b){b.classList.remove('active');});
  btn.classList.add('active');
  if(type==='friends'){loadFriendChat();}else{loadChat();}
}
async function loadFriendChat(){
  var r=await fetch('?api=get_friends');var d=await r.json();
  var el=document.getElementById('chatMsgs');
  if(!d.friends||d.friends.length===0){
    el.innerHTML='<div style="text-align:center;color:#999;padding:20px;font-size:13px">Chưa có bạn bè</div>';return;
  }
  el.innerHTML=d.friends.map(function(f){
    return '<div class="fi" onclick="openDM('+f.id+',\''+f.username+'\')" style="cursor:pointer;display:flex;align-items:center;gap:8px;padding:10px;border-radius:12px;background:#f0f5ff;margin-bottom:7px"><div class="av">'+f.avatar+'</div><div style="flex:1"><div style="font-size:13px;font-weight:700">'+f.username+'</div><div style="font-size:11px;color:#999">Nhắn tin riêng →</div></div></div>';
  }).join('');
}
function openDM(uid,uname){
  chatType='private';chatWith=uid;
  document.getElementById('chatOverlayTitle').textContent='💬 '+uname;
  loadChat();
}
async function sendChat(){
  if(!cUser){openAuth('login');return;}
  var inp=document.getElementById('chatIn');var msg=inp.value.trim();if(!msg)return;
  await fetch('?api=send_chat',{method:'POST',body:new URLSearchParams({message:msg,type:chatType,to_id:chatWith||0})});
  inp.value='';loadChat();
}
setInterval(function(){if(chatOpen&&chatType!=='friends')loadChat();},3000);

// ── SOCIAL PANEL ─────────────────────────────────────────
function stab(tab,el){
  document.querySelectorAll('.stab').forEach(function(t){t.classList.remove('active');});
  el.classList.add('active');
  var body=document.getElementById('socialBody');
  if(tab==='friends'){
    fetch('?api=get_friends').then(function(r){return r.json();}).then(function(d){
      body.innerHTML=(d.friends||[]).length?
        d.friends.map(function(f){return '<div class="fi"><div class="av">'+f.avatar+'</div><div class="fi-info"><div class="fi-name">'+f.username+'</div></div><button class="btn-sm btn-msg" onclick="chatDM('+f.id+',\''+f.username+'\')">💬</button><button class="btn-sm btn-lixi" onclick="openLixi('+f.id+',\''+f.username+'\')">🧧</button></div>';}).join(''):
        '<div style="text-align:center;color:#999;padding:20px">Chưa có bạn bè. Tìm kiếm để kết bạn!</div>';
    });
  } else {
    body.innerHTML='<input class="search-in" id="searchQ" placeholder="Tìm tên người chơi..." oninput="doSearch()"><div id="searchRes"></div>';
  }
}
async function doSearch(){
  var q=document.getElementById('searchQ').value;if(q.length<1)return;
  var r=await fetch('?api=search_users&q='+encodeURIComponent(q));var d=await r.json();
  document.getElementById('searchRes').innerHTML=(d.users||[]).map(function(u){return '<div class="fi"><div class="av">'+u.avatar+'</div><div class="fi-info"><div class="fi-name">'+u.username+'</div></div>'+(u.is_friend?'<span style="font-size:11px;color:#2ecc71">✅</span>':'<button class="btn-sm btn-add" onclick="addFriend('+u.id+',this)">+ Kết bạn</button>')+'<button class="btn-sm btn-lixi" onclick="openLixi('+u.id+',\''+u.username+'\')">🧧</button></div>';}).join('')||'<div style="color:#999;text-align:center;padding:10px">Không tìm thấy</div>';
}
async function addFriend(uid,btn){
  var r=await fetch('?api=add_friend',{method:'POST',body:new URLSearchParams({friend_id:uid})});
  var d=await r.json();toast(d.msg||'✅');
  if(d.ok)btn.replaceWith(Object.assign(document.createElement('span'),{textContent:'✅',style:'font-size:13px'}));
}
function chatDM(uid,uname){closePanel('socialPanel');chatType='private';chatWith=uid;loadChat();}

// ── HISTORY ─────────────────────────────────────────────
async function loadHistory(){
  var r=await fetch('?api=history');var d=await r.json();
  document.getElementById('histDots').innerHTML=(d.history||[]).map(function(h){return '<div class="hdot '+(h.tai?'T':'X')+'" title="'+h.sum+'">'+h.sum+'</div>';}).join('');
}

// ── LIXI ───────────────────────────────────────────────
function openLixi(uid,uname){lixiToId=uid;document.getElementById('lixiTarget').textContent='Tặng cho: '+uname;document.getElementById('lixiModal').classList.remove('hidden');}
function setLA(btn,amt){lixiAmt=amt;document.querySelectorAll('.la').forEach(function(b){b.classList.remove('active');});btn.classList.add('active');}
async function sendLixi(){
  var custom=document.getElementById('lixiCustom').value;var amt=custom?parseInt(custom):lixiAmt;
  var r=await fetch('?api=send_lixi',{method:'POST',body:new URLSearchParams({to_id:lixiToId,amount:amt})});
  var d=await r.json();toast(d.msg||(d.ok?'🧧 Đã gửi!':'Lỗi'));
  if(d.ok){document.getElementById('lixiModal').classList.add('hidden');bal=d.balance;updBal();}
}

// ── AUTH ───────────────────────────────────────────────
function openAuth(mode){authMode=mode;document.getElementById('authTitle').textContent=mode==='login'?'Đăng nhập':'Đăng ký';document.getElementById('authBtn').textContent=mode==='login'?'Đăng nhập':'Đăng ký';document.getElementById('authModal').classList.remove('hidden');document.getElementById('authErr').textContent='';}
function toggleAuthMode(){authMode=authMode==='login'?'register':'login';openAuth(authMode);}
async function submitAuth(){
  var un=document.getElementById('authUser').value.trim();var pw=document.getElementById('authPass').value;
  var r=await fetch('?api='+(authMode==='login'?'login':'register'),{method:'POST',body:new URLSearchParams({username:un,password:pw})});
  var d=await r.json();
  if(d.ok){document.getElementById('authModal').classList.add('hidden');location.reload();}
  else document.getElementById('authErr').textContent=d.msg;
}
async function logout(){await fetch('?api=logout',{method:'POST'});location.reload();}
document.getElementById('authModal').addEventListener('click',function(e){if(e.target===this)this.classList.add('hidden');});

// ── TABS / PANELS ──────────────────────────────────────
function setTab(tab,el){
  document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('active');});
  el.classList.add('active');
  if(tab==='social'){document.getElementById('socialPanel').classList.add('open');stab('friends',document.querySelector('.stab'));}
  else if(tab==='history'){document.getElementById('histPanel').classList.add('open');loadHistory();}
  else if(tab==='lixi'){if(!cUser){openAuth('login');return;}document.getElementById('socialPanel').classList.add('open');stab('friends',document.querySelector('.stab'));}
}
function closePanel(id){document.getElementById(id).classList.remove('open');document.querySelectorAll('.tab').forEach(function(t){t.classList.remove('active');});document.querySelector('.tab').classList.add('active');}

// ── INIT ───────────────────────────────────────────────
startLoop();
<?php if(!$currentUser):?>toast('💡 Demo: username=demo, pass=demo123');<?php endif;?>
</script>
</body>
</html>
