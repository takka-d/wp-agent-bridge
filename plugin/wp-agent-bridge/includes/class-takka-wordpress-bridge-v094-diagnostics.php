<?php
if (!defined('ABSPATH')) exit;

final class TakKa_WordPress_Bridge_V094_Diagnostics
{
    private const VERSION='0.9.4';
    private const NS='takka-v094/v1';
    private const ROUTE='/takka-v094/v1/manage';
    private const OUTER='/takka-bridge/v1/execute';
    private const HEALTH='/takka-bridge/v1/health';
    private const SECRET='takka_bridge_secret';
    private const USER='takka_bridge_user_id';
    private const SKEW=300;
    private const MAX_URL=4096;
    private const MAX_BODY=1048576;
    private const MAX_TEXT=131072;
    private const MAX_REDIRECTS=5;
    private const MAX_BATCH=20;
    private const MAX_MEDIA_HASH=33554432;
    private static $allowed=false;
    private const ACTIONS=['v094.capabilities','http.probe','http.probe.batch','media.file.inspect'];
    private const HEADER_ALLOW=['accept','accept-language','cache-control','pragma','referer','sec-fetch-dest','sec-fetch-mode','sec-fetch-site','sec-fetch-user','user-agent'];

    public static function init():void{
        add_action('rest_api_init',[self::class,'register']);
        add_filter('rest_pre_dispatch',[self::class,'prepare'],60,3);
        add_filter('rest_request_after_callbacks',[self::class,'health'],390,3);
    }
    public static function register():void{
        register_rest_route(self::NS,'/manage',['methods'=>WP_REST_Server::CREATABLE,'callback'=>[self::class,'dispatch'],'permission_callback'=>[self::class,'permission']]);
    }
    public static function prepare($result,WP_REST_Server $server,WP_REST_Request $request){
        if($result!==null||$request->get_route()!==self::OUTER||strtoupper($request->get_method())!=='POST'||!self::valid_hmac($request)) return $result;
        $inner=self::inner($request);
        if(!is_array($inner)||($inner['action']??'')!=='rest.call') return $result;
        $p=isset($inner['params'])&&is_array($inner['params'])?$inner['params']:[];
        if(strtoupper((string)($p['method']??'GET'))==='POST'&&(string)($p['route']??'')===self::ROUTE) self::$allowed=true;
        return $result;
    }
    public static function permission(){
        if(!self::$allowed||!current_user_can('manage_options')) return new WP_Error('takka_bridge_v094_internal_only','This route is only callable through the signed Bridge REST proxy.',['status'=>403]);
        self::$allowed=false; return true;
    }
    public static function dispatch(WP_REST_Request $request){
        $j=$request->get_json_params();
        if(!is_array($j)) return new WP_Error('takka_bridge_v094_json','JSON body is required.',['status'=>400]);
        $a=is_string($j['action']??null)?trim($j['action']):''; $p=isset($j['params'])&&is_array($j['params'])?$j['params']:[];
        if(!in_array($a,self::ACTIONS,true)) return new WP_Error('takka_bridge_v094_action','Unknown or blocked v0.9.4 action.',['status'=>400,'action'=>$a]);
        try{
            if($a==='v094.capabilities') return rest_ensure_response(self::capabilities());
            if($a==='http.probe') return self::probe_response($p);
            if($a==='http.probe.batch') return self::batch($p);
            if($a==='media.file.inspect') return self::media($p);
        }catch(Throwable $e){return new WP_Error('takka_bridge_v094_exception',$e->getMessage(),['status'=>500,'type'=>get_class($e)]);}
        return new WP_Error('takka_bridge_v094_dispatch','Dispatch fell through.',['status'=>500]);
    }
    public static function health($response,array $handler,WP_REST_Request $request){
        if($request->get_route()!==self::HEALTH||is_wp_error($response)) return $response;
        $r=rest_ensure_response($response); $d=$r->get_data(); if(!is_array($d)) return $response;
        $d['bridge_version']=self::VERSION; $f=isset($d['features'])&&is_array($d['features'])?$d['features']:[];
        foreach(['same_origin_http_diagnostics','browser_header_profiles','bounded_http_batch_probe','media_file_integrity_inspection'] as $x) if(!in_array($x,$f,true)) $f[]=$x;
        $d['features']=$f; $r->set_data($d); return $r;
    }
    private static function capabilities():array{return[
        'version'=>self::VERSION,'internal_route'=>self::ROUTE,'actions'=>self::ACTIONS,
        'http'=>['same_origin_only'=>true,'methods'=>['GET','HEAD'],'profiles'=>['default','android_chrome_image','android_chrome_document','desktop_chrome_image','desktop_chrome_document'],'max_body_bytes'=>self::MAX_BODY,'max_redirects'=>self::MAX_REDIRECTS,'max_batch'=>self::MAX_BATCH,'cookies_sent'=>false,'authorization_header_allowed'=>false],
        'media'=>['uploads_directory_only_for_url_lookup'=>true,'max_sha256_file_bytes'=>self::MAX_MEDIA_HASH,'magic_bytes'=>true]
    ];}
    private static function probe_response(array $p){$r=self::probe($p); return is_wp_error($r)?$r:rest_ensure_response($r);}
    private static function batch(array $p){
        $q=isset($p['requests'])&&is_array($p['requests'])?$p['requests']:null;
        if(!$q) return new WP_Error('takka_bridge_v094_batch','requests must be a non-empty array.',['status'=>400]);
        if(count($q)>self::MAX_BATCH) return new WP_Error('takka_bridge_v094_batch_limit','Too many probes.',['status'=>413,'max'=>self::MAX_BATCH]);
        $out=[]; foreach($q as $i=>$item){
            if(!is_array($item)){$out[]=['index'=>$i,'ok'=>false,'error'=>['code'=>'invalid_item','message'=>'Probe item must be an object.']];continue;}
            $r=self::probe($item); if(is_wp_error($r)) $out[]=['index'=>$i,'ok'=>false,'error'=>['code'=>$r->get_error_code(),'message'=>$r->get_error_message(),'data'=>$r->get_error_data()]]; else{$r['index']=$i;$out[]=$r;}
        }
        return rest_ensure_response(['ok'=>true,'count'=>count($out),'results'=>$out]);
    }
    private static function probe(array $p){
        $raw=is_string($p['url']??null)?trim($p['url']):''; if($raw===''||strlen($raw)>self::MAX_URL) return new WP_Error('takka_bridge_v094_url','url is required and must be within the size limit.',['status'=>400]);
        $url=self::same_origin($raw,null); if(is_wp_error($url)) return $url;
        $method=strtoupper((string)($p['method']??'GET')); if(!in_array($method,['GET','HEAD'],true)) return new WP_Error('takka_bridge_v094_method','Only GET and HEAD are allowed.',['status'=>400]);
        $profile=is_string($p['profile']??null)?sanitize_key($p['profile']):'default'; $headers=self::profile($profile); if(is_wp_error($headers)) return $headers;
        if(isset($p['headers'])){if(!is_array($p['headers'])) return new WP_Error('takka_bridge_v094_headers','headers must be an object.',['status'=>400]); $custom=self::headers($p['headers']); if(is_wp_error($custom)) return $custom; foreach($custom as $k=>$v)$headers[$k]=$v;}
        $timeout=max(1,min(20,(int)($p['timeout']??10))); $follow=!isset($p['follow_redirects'])||(bool)$p['follow_redirects']; $maxr=max(0,min(self::MAX_REDIRECTS,(int)($p['max_redirects']??self::MAX_REDIRECTS))); $maxb=$method==='HEAD'?0:max(0,min(self::MAX_BODY,(int)($p['max_body_bytes']??self::MAX_BODY)));
        $current=$url;$chain=[];$start=microtime(true);$resp=null;
        for($hop=0;$hop<=$maxr;$hop++){
            $args=['method'=>$method,'timeout'=>$timeout,'redirection'=>0,'reject_unsafe_urls'=>true,'headers'=>$headers,'cookies'=>[]]; if($maxb>0)$args['limit_response_size']=$maxb+1;
            $resp=wp_safe_remote_request($current,$args); if(is_wp_error($resp)) return new WP_Error('takka_bridge_v094_http',$resp->get_error_message(),['status'=>502,'url'=>$current,'inner_code'=>$resp->get_error_code()]);
            $status=(int)wp_remote_retrieve_response_code($resp);$loc=(string)wp_remote_retrieve_header($resp,'location');
            if($follow&&$status>=300&&$status<400&&$loc!==''){
                if($hop>=$maxr) return new WP_Error('takka_bridge_v094_redirect_limit','Redirect limit reached.',['status'=>508]);
                $next=self::same_origin($loc,$current); if(is_wp_error($next)) return new WP_Error('takka_bridge_v094_redirect_blocked','Redirect left the WordPress origin.',['status'=>400,'from'=>$current,'location'=>$loc]);
                $chain[]=['url'=>$current,'status'=>$status,'location'=>$next];$current=$next;continue;
            } break;
        }
        if(!is_array($resp)) return new WP_Error('takka_bridge_v094_empty','HTTP probe returned no response.',['status'=>502]);
        $body=$method==='HEAD'?'':(string)wp_remote_retrieve_body($resp);$trunc=false;if($maxb>0&&strlen($body)>$maxb){$body=substr($body,0,$maxb);$trunc=true;}
        $ct=(string)wp_remote_retrieve_header($resp,'content-type');$cl=(string)wp_remote_retrieve_header($resp,'content-length');$prefix=substr($body,0,256);$text=self::textual($ct,$body)?self::utf8(substr($body,0,self::MAX_TEXT)):null;
        $search=null;if(is_string($p['search']??null)&&$p['search']!==''){$needle=$p['search'];if(strlen($needle)>4096)return new WP_Error('takka_bridge_v094_search','search exceeds 4096 bytes.',['status'=>413]);$search=self::search($body,$needle);}
        $hs=wp_remote_retrieve_headers($resp);$hout=is_array($hs)?$hs:(is_object($hs)&&method_exists($hs,'getAll')?$hs->getAll():(array)$hs);
        return ['ok'=>true,'request'=>['url'=>$url,'final_url'=>$current,'method'=>$method,'profile'=>$profile,'headers'=>$headers,'cookies_sent'=>false],'response'=>['status'=>(int)wp_remote_retrieve_response_code($resp),'headers'=>$hout,'content_type'=>$ct?:null,'content_length_header'=>$cl?:null,'received_body_bytes'=>strlen($body),'body_truncated'=>$trunc,'sha256'=>$method==='HEAD'?null:hash('sha256',$body),'sha256_scope'=>$method==='HEAD'?null:($trunc?'received_prefix':'full_received_body'),'mime_from_magic'=>self::mime($body),'magic_prefix_hex'=>$prefix!==''?bin2hex(substr($prefix,0,32)):'','body_prefix_base64'=>$prefix!==''?base64_encode($prefix):'','text_snippet'=>$text],'redirects'=>$chain,'search'=>$search,'timing_ms'=>(int)round((microtime(true)-$start)*1000)];
    }
    private static function media(array $p){
        $id=isset($p['attachment_id'])?absint($p['attachment_id']):0;$url=is_string($p['url']??null)?trim($p['url']):'';$path='';$post=null;
        if($id<1&&$url==='') return new WP_Error('takka_bridge_v094_media_selector','attachment_id or url is required.',['status'=>400]);
        if($id>0){$post=get_post($id);if(!$post||$post->post_type!=='attachment')return new WP_Error('takka_bridge_v094_attachment','Attachment was not found.',['status'=>404]);$path=(string)get_attached_file($id,true);if($url===''){$u=wp_get_attachment_url($id);$url=is_string($u)?$u:'';}}
        else{$u=self::same_origin($url,null);if(is_wp_error($u))return $u;$url=$u;$path=self::upload_path($url);if(is_wp_error($path))return $path;}
        $uploads=wp_get_upload_dir();$base=(string)($uploads['basedir']??'');$safe=self::safe_file($path,$base);if(is_wp_error($safe))return $safe;$size=filesize($safe);if($size===false)return new WP_Error('takka_bridge_v094_stat','Could not stat media file.',['status'=>500]);
        $prefix='';$fh=@fopen($safe,'rb');if(is_resource($fh)){$prefix=(string)fread($fh,512);fclose($fh);} $finfo=null;if(function_exists('finfo_open')){$fi=@finfo_open(FILEINFO_MIME_TYPE);if($fi){$m=@finfo_file($fi,$safe);if(is_string($m)&&$m!=='')$finfo=$m;finfo_close($fi);}}
        $check=wp_check_filetype_and_ext($safe,basename($safe));$sha=null;$skip=(int)$size>self::MAX_MEDIA_HASH;if(!$skip){$h=hash_file('sha256',$safe);$sha=is_string($h)?$h:null;}
        $out=['ok'=>true,'attachment_id'=>$id?:null,'url'=>$url?:null,'file'=>['relative_to_uploads'=>self::relative($safe,$base),'exists'=>true,'bytes'=>(int)$size,'extension'=>strtolower((string)pathinfo($safe,PATHINFO_EXTENSION)),'wordpress_type'=>!empty($check['type'])?$check['type']:null,'wordpress_ext'=>!empty($check['ext'])?$check['ext']:null,'proper_filename'=>!empty($check['proper_filename'])?$check['proper_filename']:null,'finfo_mime'=>$finfo,'mime_from_magic'=>self::mime($prefix),'magic_prefix_hex'=>bin2hex(substr($prefix,0,32)),'sha256'=>$sha,'sha256_skipped_for_size'=>$skip]];
        if($post)$out['attachment']=['mime_type'=>$post->post_mime_type,'title'=>get_the_title($id),'metadata'=>wp_get_attachment_metadata($id)];return rest_ensure_response($out);
    }
    private static function profile(string $p){$ref=home_url('/');$uaA='Mozilla/5.0 (Linux; Android 14; Pixel 8 Pro) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Mobile Safari/537.36';$uaD='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/152.0.0.0 Safari/537.36';$img='image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8';$doc='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';$all=['default'=>['User-Agent'=>'WP-Agent-Bridge/'.self::VERSION.'; '.home_url('/'),'Accept'=>'*/*'],'android_chrome_image'=>['User-Agent'=>$uaA,'Accept'=>$img,'Referer'=>$ref,'Sec-Fetch-Dest'=>'image','Sec-Fetch-Mode'=>'no-cors','Sec-Fetch-Site'=>'same-origin'],'android_chrome_document'=>['User-Agent'=>$uaA,'Accept'=>$doc,'Sec-Fetch-Dest'=>'document','Sec-Fetch-Mode'=>'navigate','Sec-Fetch-Site'=>'none','Sec-Fetch-User'=>'?1'],'desktop_chrome_image'=>['User-Agent'=>$uaD,'Accept'=>$img,'Referer'=>$ref,'Sec-Fetch-Dest'=>'image','Sec-Fetch-Mode'=>'no-cors','Sec-Fetch-Site'=>'same-origin'],'desktop_chrome_document'=>['User-Agent'=>$uaD,'Accept'=>$doc,'Sec-Fetch-Dest'=>'document','Sec-Fetch-Mode'=>'navigate','Sec-Fetch-Site'=>'none','Sec-Fetch-User'=>'?1']];return isset($all[$p])?$all[$p]:new WP_Error('takka_bridge_v094_profile','Unknown HTTP profile.',['status'=>400,'profile'=>$p]);}
    private static function headers(array $in){if(count($in)>20)return new WP_Error('takka_bridge_v094_header_count','Too many headers.',['status'=>413]);$out=[];foreach($in as $k=>$v){if(!is_string($k)||(!is_string($v)&&!is_numeric($v)))return new WP_Error('takka_bridge_v094_header_type','Header names/values must be strings.',['status'=>400]);$l=strtolower(trim($k));if(!in_array($l,self::HEADER_ALLOW,true))return new WP_Error('takka_bridge_v094_header_blocked','Header is not allowlisted.',['status'=>400,'header'=>$k]);$s=trim((string)$v);if(strlen($s)>2048||preg_match('/[\r\n]/',$s))return new WP_Error('takka_bridge_v094_header_value','Invalid header value.',['status'=>400]);if($l==='referer'){$u=self::same_origin($s,null);if(is_wp_error($u))return new WP_Error('takka_bridge_v094_referer','Referer must be same-origin.',['status'=>400]);$s=$u;}$out[implode('-',array_map('ucfirst',explode('-',$l)))]=$s;}return $out;}
    private static function same_origin(string $c,?string $base){$c=trim($c);if($c===''||strlen($c)>self::MAX_URL||preg_match('/[\r\n\0]/',$c))return new WP_Error('takka_bridge_v094_url_invalid','Invalid URL.',['status'=>400]);$home=wp_parse_url(home_url('/'));if(!is_array($home)||empty($home['scheme'])||empty($home['host']))return new WP_Error('takka_bridge_v094_home','Could not resolve home origin.',['status'=>500]);$origin=$home['scheme'].'://'.$home['host'].(isset($home['port'])?':'.(int)$home['port']:'');if(strpos($c,'//')===0)$c=$home['scheme'].':'.$c;elseif(strpos($c,'/')===0)$c=$origin.$c;elseif(!preg_match('#^https?://#i',$c)){if($base===null)$c=home_url('/'.ltrim($c,'/'));else{$b=wp_parse_url($base);if(!is_array($b))return new WP_Error('takka_bridge_v094_base','Invalid base URL.',['status'=>400]);$bo=$b['scheme'].'://'.$b['host'].(isset($b['port'])?':'.(int)$b['port']:'');$dir=preg_replace('#/[^/]*$#','/',(string)($b['path']??'/'));$c=$bo.$dir.$c;}}$p=wp_parse_url($c);if(!is_array($p)||empty($p['scheme'])||empty($p['host']))return new WP_Error('takka_bridge_v094_parse','Could not parse URL.',['status'=>400]);$scheme=strtolower((string)$p['scheme']);$hs=strtolower((string)$home['scheme']);$host=strtolower(rtrim((string)$p['host'],'.'));$hh=strtolower(rtrim((string)$home['host'],'.'));$port=isset($p['port'])?(int)$p['port']:($scheme==='https'?443:80);$hp=isset($home['port'])?(int)$home['port']:($hs==='https'?443:80);if(!in_array($scheme,['http','https'],true)||$scheme!==$hs||$host!==$hh||$port!==$hp)return new WP_Error('takka_bridge_v094_cross_origin','Diagnostics are restricted to the WordPress site origin.',['status'=>400]);return esc_url_raw($c);}
    private static function textual(string $ct,string $body):bool{$l=strtolower($ct);foreach(['text/','json','xml','javascript','css','svg','html'] as $n)if(strpos($l,$n)!==false)return true;return $body!==''&&strpos(substr($body,0,4096),"\0")===false&&wp_check_invalid_utf8(substr($body,0,4096),true)!=='';}
    private static function utf8(string $s):string{return(string)wp_check_invalid_utf8($s,true);}
    private static function mime(string $b){if($b==='')return null;if(function_exists('finfo_open')){$f=@finfo_open(FILEINFO_MIME_TYPE);if($f){$m=@finfo_buffer($f,$b);finfo_close($f);if(is_string($m)&&$m!==''&&$m!=='application/octet-stream')return $m;}}if(substr($b,0,3)==="\xFF\xD8\xFF")return'image/jpeg';if(substr($b,0,8)==="\x89PNG\r\n\x1A\n")return'image/png';if(substr($b,0,6)==='GIF87a'||substr($b,0,6)==='GIF89a')return'image/gif';if(substr($b,0,4)==='RIFF'&&substr($b,8,4)==='WEBP')return'image/webp';if(strlen($b)>=12&&substr($b,4,4)==='ftyp'&&in_array(substr($b,8,4),['avif','avis'],true))return'image/avif';return null;}
    private static function search(string $h,string $n):array{$m=[];$o=0;$l=strlen($n);while(count($m)<20&&($p=strpos($h,$n,$o))!==false){$s=max(0,$p-1280);$a=$p+$l;$m[]=['byte_offset'=>$p,'before'=>self::utf8(substr($h,$s,$p-$s)),'match'=>$n,'after'=>self::utf8(substr($h,$a,1280))];$o=$p+max(1,$l);}return['query_sha256'=>hash('sha256',$n),'returned'=>count($m),'total_matches'=>substr_count($h,$n),'matches'=>$m];}
    private static function upload_path(string $url){$u=wp_get_upload_dir();$bu=(string)($u['baseurl']??'');$bd=(string)($u['basedir']??'');$t=wp_parse_url($url);$b=wp_parse_url($bu);if($bu===''||$bd===''||!is_array($t)||!is_array($b))return new WP_Error('takka_bridge_v094_uploads','Uploads mapping unavailable.',['status'=>500]);$tp=rawurldecode((string)($t['path']??''));$bp=rtrim(rawurldecode((string)($b['path']??'')),'/');if($bp===''||strpos($tp,$bp.'/')!==0)return new WP_Error('takka_bridge_v094_upload_scope','URL is outside WordPress uploads.',['status'=>400]);$r=ltrim(substr($tp,strlen($bp)),'/');if($r===''||preg_match('#(^|/)\.\.(/|$)#',$r)||strpos($r,"\0")!==false)return new WP_Error('takka_bridge_v094_upload_path','Unsafe uploads path.',['status'=>400]);return rtrim($bd,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$r);}
    private static function safe_file(string $p,string $base){$br=realpath($base);$pr=realpath($p);if($br===false||$pr===false||!is_file($pr))return new WP_Error('takka_bridge_v094_file_missing','File was not found.',['status'=>404]);$bn=rtrim(str_replace('\\','/',$br),'/').'/';$pn=str_replace('\\','/',$pr);if(strpos($pn,$bn)!==0)return new WP_Error('takka_bridge_v094_file_scope','File is outside allowed uploads directory.',['status'=>403]);return$pr;}
    private static function relative(string $p,string $b):string{$bn=rtrim(str_replace('\\','/',$b),'/');$pn=str_replace('\\','/',$p);return strpos($pn,$bn.'/')===0?substr($pn,strlen($bn)+1):basename($p);}
    private static function valid_hmac(WP_REST_Request $r):bool{$s=(string)get_option(self::SECRET,'');$uid=(int)get_option(self::USER,0);if($s===''||$uid<1||!user_can($uid,'manage_options'))return false;$t=trim((string)$r->get_header('x-takka-timestamp'));$sig=strtolower(trim((string)$r->get_header('x-takka-signature')));if($t===''||$sig===''||!ctype_digit($t)||abs(time()-(int)$t)>self::SKEW)return false;$payload=$t."\nPOST\n".self::OUTER."\n".hash('sha256',(string)$r->get_body());return hash_equals(hash_hmac('sha256',$payload,$s),$sig);}
    private static function inner(WP_REST_Request $r){$o=json_decode((string)$r->get_body(),true);if(!is_array($o)||($o['action']??'')!=='envelope'||!isset($o['params']['payload_b64']))return null;$d=base64_decode((string)$o['params']['payload_b64'],true);if(!is_string($d))return null;$i=json_decode($d,true);return is_array($i)?$i:null;}
}
