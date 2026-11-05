<?php
declare(strict_types=1);

function startSecureSession(): void
{
    if(session_status()===PHP_SESSION_ACTIVE)return;
    $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
    session_name('research_ai_session');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
    session_start();
    if(!isset($_SESSION['created_at'])){session_regenerate_id(true);$_SESSION['created_at']=time();}
    if(!isset($_SESSION['last_activity']))$_SESSION['last_activity']=time();
    if(time()-(int)$_SESSION['last_activity']>7200){$_SESSION=[];session_regenerate_id(true);$_SESSION['created_at']=time();}
    $_SESSION['last_activity']=time();
}
function getClientIp():string{return filter_var($_SERVER['REMOTE_ADDR']??'',FILTER_VALIDATE_IP)?:'';}
function jsonResponse(array $data,int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;}
