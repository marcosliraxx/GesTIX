<?php
/* Caminho: includes/ai-providers.php */
declare(strict_types=1);
/** Catálogo de APIs com protocolo de chat OpenAI-compatível; os modelos reais são consultados no provedor. */
function arc_ai_catalog(): array {
 return [
 'gemini'=>['name'=>'Google Gemini','url'=>'https://generativelanguage.googleapis.com/v1beta/openai','help'=>'https://aistudio.google.com/apikey','tier'=>'Gratuito sujeito a limites'],
 'groq'=>['name'=>'GroqCloud','url'=>'https://api.groq.com/openai/v1','help'=>'https://console.groq.com/keys','tier'=>'Plano gratuito limitado'],
 'openrouter'=>['name'=>'OpenRouter','url'=>'https://openrouter.ai/api/v1','help'=>'https://openrouter.ai/settings/keys','tier'=>'Modelos :free com limite'],
 'mistral'=>['name'=>'Mistral AI','url'=>'https://api.mistral.ai/v1','help'=>'https://console.mistral.ai/api-keys','tier'=>'Modo gratuito conforme conta'],
 'cohere'=>['name'=>'Cohere','url'=>'https://api.cohere.ai/compatibility/v1','help'=>'https://dashboard.cohere.com/api-keys','tier'=>'Chave de avaliação limitada'],
 'nvidia'=>['name'=>'NVIDIA NIM','url'=>'https://integrate.api.nvidia.com/v1','help'=>'https://build.nvidia.com/','tier'=>'Cota gratuita para testes'],
 'sambanova'=>['name'=>'SambaNova Cloud','url'=>'https://api.sambanova.ai/v1','help'=>'https://cloud.sambanova.ai/apis','tier'=>'Cota gratuita sujeita a limites'],
 'cerebras'=>['name'=>'Cerebras','url'=>'https://api.cerebras.ai/v1','help'=>'https://cloud.cerebras.ai/','tier'=>'Avaliação ou crédito limitado'],
 'openai'=>['name'=>'OpenAI','url'=>'https://api.openai.com/v1','help'=>'https://platform.openai.com/api-keys','tier'=>'Uso normalmente cobrado'],
 'deepseek'=>['name'=>'DeepSeek','url'=>'https://api.deepseek.com','help'=>'https://platform.deepseek.com/api_keys','tier'=>'Uso normalmente cobrado'],
 ];
}
function arc_ai_saved(PDO $pdo):array {
 $entries=json_decode(arc_platform_setting($pdo,'platform_ai_providers','[]'),true);
 return is_array($entries)?array_values(array_filter($entries,'is_array')):[];
}
function arc_ai_store(PDO $pdo,array $entries):void {
 if(trim((string)(arc_config()['app_secret']??''))==='')throw new RuntimeException('Configure app_secret antes de armazenar chaves de IA.');
 arc_platform_save_setting($pdo,'platform_ai_providers',json_encode(array_values($entries),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function arc_ai_request(string $provider,string $key,string $method,string $path,?array $data=null):array {
 $catalog=arc_ai_catalog();if(!isset($catalog[$provider]))throw new RuntimeException('Provedor desconhecido.');
 if($key===''||strlen($key)>512)throw new RuntimeException('Chave da API ausente ou inválida.');
 if(!extension_loaded('curl'))throw new RuntimeException('Extensão cURL indisponível.');
 $url=$catalog[$provider]['url'].$path;
 $headers=['Authorization: Bearer '.$key,'Accept: application/json'];
 if($data!==null)$headers[]='Content-Type: application/json';
 $ch=curl_init($url);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>7,CURLOPT_TIMEOUT=>18,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_MAXREDIRS=>0,CURLOPT_ENCODING=>'']);
 if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data,JSON_THROW_ON_ERROR));
 $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
 if($response===false)throw new RuntimeException('Falha de rede: '.$err);
 $json=json_decode($response,true);
 if($status<200||$status>=300)throw new RuntimeException('Provedor respondeu HTTP '.$status.($status===401||$status===403?' (verifique a chave e as permissões)':($status===429?' (limite de requisições)':'')));
 if(!is_array($json))throw new RuntimeException('Formato de resposta desconhecido.');
 return $json;
}
function arc_ai_models(string $provider,string $key):array {
 $json=arc_ai_request($provider,$key,'GET','/models');$items=$json['data']??$json['models']??[];
 $result=[];
 foreach($items as $item){$id=is_array($item)?($item['id']??$item['name']??''):'';if(is_string($id)&&strlen($id)<180&&$id!=='')$result[]=$id;}
 sort($result,SORT_NATURAL|SORT_FLAG_CASE);return array_slice(array_values(array_unique($result)),0,500);
}
/** Failover apenas em erros de disponibilidade; erros de conteúdo ou autorização não são ignorados. */
function arc_ai_chat(PDO $pdo,array $messages,int $maxTokens=256):array {
 $entries=arc_ai_saved($pdo);$errors=[];
 foreach($entries as $entry){
  if(empty($entry['enabled']))continue;
  $key=arc_platform_decrypt((string)($entry['secret']??''));if($key==='')continue;
  try {
   $model=(string)($entry['model']??'');if($model==='')continue;
   $result=arc_ai_request((string)$entry['provider'],$key,'POST','/chat/completions',['model'=>$model,'messages'=>$messages,'max_tokens'=>max(1,min(4096,$maxTokens))]);
   if(!isset($result['choices'][0]['message']['content']))throw new RuntimeException('Resposta sem conteúdo.');
   return ['provider'=>$entry['provider'],'model'=>$model,'content'=>$result['choices'][0]['message']['content']];
  }catch(Throwable $error){$errors[]=(string)($entry['provider']??'Provedor').': '.$error->getMessage();}
 }
 throw new RuntimeException('Nenhum provedor disponível: '.implode('; ',$errors));
}
