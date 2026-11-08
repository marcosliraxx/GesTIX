<?php
/** Caminho: /includes/network-invite-template.php — convite HTML com links verificáveis. */

require_once __DIR__ . '/error-logging.php';
function arc_network_invite_email(PDO $pdo,int $networkId,string $name,bool $registered): string {
 $esc=static fn(string $v):string=>htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
 $root=rtrim(arc_base_url(),'/');$logo=$root.'/api/brand-logo.php?icon=192';
 $expires=time()+14*86400;
 $signature=hash_hmac('sha256',$networkId.'|'.$expires,(string)(arc_config()['app_secret']??''));
 $networkLogo=$root.'/api/invite-network-logo.php?network='.$networkId.'&expires='.$expires.'&sig='.$signature;
 $url=$root.($registered?'/pages/painel.php':'/index.php');
 $intro=$registered?'Seu acesso à rede compartilhada está disponível.':'Você recebeu um convite para participar de uma rede compartilhada. Entre com o e-mail convidado para validar seu acesso.';
 return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:36px 12px;background:#0b1729;font-family:Arial,sans-serif;color:#e9f4ff"><div style="max-width:570px;margin:auto;border:1px solid #2d4d69;border-radius:16px;overflow:hidden;background:#162b42"><div style="padding:24px 28px;background:#102339;border-bottom:1px solid #2d4d69"><img src="'.$esc($logo).'" alt="" width="42" height="42" style="vertical-align:middle;margin-right:12px"><strong style="font-size:21px;color:#fff">T.I Digital</strong><div style="font-size:12px;color:#9eb9d3;margin-top:5px">Gestão de Redes e Servidores</div></div><div style="padding:28px;text-align:center"><img src="'.$esc($networkLogo).'" alt="Logo da rede" width="74" height="74" style="border-radius:12px;background:#243f59;object-fit:contain"><h1 style="font-size:21px;color:#fff">'.$esc($name).'</h1><p style="color:#b9cee0;font-size:14px;line-height:1.7">'.$esc($intro).'</p><a href="'.$esc($url).'" style="display:inline-block;margin:14px 0;background:#00a6dc;color:#fff;text-decoration:none;font-weight:bold;padding:14px 28px;border-radius:9px">'.$esc($registered?'Acessar minha rede':'Aceitar convite').'</a><p style="font-size:12px;color:#92a9bb">Caso o botão não funcione, acesse: <a href="'.$esc($url).'" style="color:#70d7ff">'.$esc($url).'</a></p></div><div style="padding:15px 27px;background:#102339;color:#8da6bb;font-size:11px">Convite da plataforma T.I Digital. Caso não reconheça, ignore esta mensagem.</div></div></body></html>';
}
