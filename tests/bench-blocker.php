<?php
/**
 * Times the HTML blocker on a synthetic ~430 KB page (run: php tests/bench-blocker.php [path/to/class-sccm-blocker.php]).
 * Use it to compare a change against the current code: run it before and after, or point it at an
 * older copy of the class.
 *
 * @package SmartCookieConsentManager
 */

// phpcs:ignoreFile
define('ABSPATH', '/'); 
function esc_attr($s){return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8');}
function __($s){return $s;} function apply_filters($h,$v){return $v;}
require $argv[1] ?? dirname(__DIR__) . '/includes/class-sccm-blocker.php';
$services = include dirname(__DIR__) . '/includes/data/services.php';
$rules=[]; foreach($services as $svc){ if($svc['category']==='necessary') continue; foreach($svc['patterns'] as $p) $rules[]=['pattern'=>$p,'category'=>$svc['category']]; }
function page($withTracker){
  $h='<html><head><title>x</title>';
  for($i=0;$i<35;$i++) $h.='<script src="/wp-content/plugins/p'.$i.'/js/app.min.js?ver=1.'.$i.'" defer></script><link rel="stylesheet" href="/wp-content/plugins/p'.$i.'/css/a.css">';
  $h.='<script>var data='.json_encode(array_fill(0,2500,'lorem ipsum dolor sit amet')).';</script>';
  if($withTracker) $h.='<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script><script>gtag(\'config\',\'G-1\');</script>';
  $h.='</head><body>'.str_repeat('<div class="c"><p>Lorem ipsum dolor sit amet, consectetur adipiscing elit.</p><a href="/x">link</a></div>',3500).'</body></html>';
  return $h;
}
foreach(['no trackers'=>false,'with GA'=>true] as $label=>$flag){
  $html=page($flag); $n=40; $t=microtime(true);
  for($i=0;$i<$n;$i++){ $out=SCCM_Blocker::rewrite($html,$rules); }
  printf("%-12s page %4d KB  %6.2f ms/page  changed=%s\n",$label,strlen($html)/1024,(microtime(true)-$t)/$n*1000,$out!==$html?'yes':'no');
}
