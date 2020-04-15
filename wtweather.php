<?php
include 'wtw_config.php';
/*
В конфиге содержатся defines:

define('BOT_TOKEN', '1234567890:XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');
define('OWM_CODE', 'XXXXXXXXXXXXXXXXXXXXXXXXXXX');

BOT_TOKEN - уникальный токен бота в Telegram
OWM_CODE - API код для работы с https://api.openweathermap.org



Установка:
1. Создать бота в Telegram (через @BotFather)
2. Получить telegram-токен и api-код на openweathermap.org
3. Создать на их основе wtw_config.php или прописать здесь же
4. Залить на сервер оба файла в одну директорию, сервер должен быть с  SSL
5. Для установки Webhook на бота выполнить wtweather.php?setup или самостоятельно выполнить:
    https://api.telegram.org/bot[Токен BOT_TOKEN]/setWebhook?url=[полная ссылка на wtweather.php WEBHOOK_URL]
6. Начать работу с ботом в Telegram
*/

define('API_URL', 'https://api.telegram.org/bot'.BOT_TOKEN.'/');
define('WEBHOOK_URL','https://'.$_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME']);


function exec_curl_request($handle) {
  $response = curl_exec($handle);

  if ($response === false) {
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    error_log("Curl returned error $errno: $error\n");
    curl_close($handle);
    return false;
  }

  $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
  curl_close($handle);

  if ($http_code >= 500) {
    sleep(10);
    return false;
  } else if ($http_code != 200) {
    $response = json_decode($response, true);
    error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
    if ($http_code == 401) {
      throw new Exception('Invalid access token provided');
    }
    return false;
  } else {
    $response = json_decode($response, true);
    if (isset($response['description'])) {
      error_log("Request was successful: {$response['description']}\n");
    }
    $response = $response['result'];
  }

  return $response;
}

function apiRequestJson($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $handle = curl_init(API_URL);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);
  curl_setopt($handle, CURLOPT_POST, true);
  curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
  curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

  return exec_curl_request($handle);
}                            

function processMessage($message) {
  if (isset($message['text'])) { 
    if ($message['text'] == "/start" or $message['text'] == "/help")
      $answer = "Погодный бот\n\nАвтор: semenovkm.me\n Используется openweathermap.org";
    else $answer = getWeather($message['text']); 
  }
  else $answer = "Пришлите название города (или город и код страны), и я подскажу, как там обстоят дела.\n\nНапример, '<i>Москва</i>' или'<i>Moscow, US</i>'"; 

  apiRequestJson("sendMessage", array('chat_id' => $message['chat']['id'], "parse_mode" => "HTML", "text" => $answer));
}


function printTemp($rawval) {
  if ($rawval > 0) return '+'.$rawval.'°C';
  else return $rawval.'°C';
}
function printWindDir($deg) {
  if ($deg < 0) $deg = 360 - $deg;
  $pt = 360/32;
      if ($deg <=    $pt) return "⬆️⬆️ С";
  elseif ($deg <=  3*$pt) return "⬆️↗️ ССВ";
  elseif ($deg <=  5*$pt) return "↗️↗️ СВ";
  elseif ($deg <=  7*$pt) return "➡↗️ ВСВ";
  elseif ($deg <=  9*$pt) return "➡➡ В";
  elseif ($deg <= 11*$pt) return "➡↘️ ВЮВ";
  elseif ($deg <= 13*$pt) return "↘️↘️ ЮВ";
  elseif ($deg <= 15*$pt) return "⬇️↘️ ЮЮВ";
  elseif ($deg <= 17*$pt) return "⬇️⬇️ Ю";
  elseif ($deg <= 19*$pt) return "⬇️↙️ ЮЮЗ";
  elseif ($deg <= 21*$pt) return "⬇️↙️ ЮЗ";
  elseif ($deg <= 23*$pt) return "⬅️↙️ ЗЮЗ";
  elseif ($deg <= 25*$pt) return "⬅️⬅️ З";
  elseif ($deg <= 27*$pt) return "⬅️↖️ ЗСЗ";
  elseif ($deg <= 29*$pt) return "↖️↖️ СЗ";
  elseif ($deg <= 31*$pt) return "⬆️↖️ ССЗ";
  else return "⬆️⬆️ С";
}

function getClothes($fl_temp, $cond, $wind){
  if ($fl_temp >= 25)
    $res = "Сегодня жарко, наденьте <b>лёгкую майку</b>";
  elseif ($fl_temp >= 20)
    $res = "За окном тепло, советую <b>футболку/рубашку</b>";
  elseif (($fl_temp >= 10 and $wind->speed <=5) or $fl_temp >= 15)
    $res = "Довольно свежо, лучше надеть <b>толстовку</b>";
  elseif ($fl_temp >= 10)
    $res = "Свежо и ветрено, пора брать <b>ветровку</b>";
  elseif ($fl_temp >= 5)
    $res = "Прохладно, пора брать <b>ветровку</b>";
  elseif ($fl_temp >= -10)
    $res = "Около нуля, нужна <b>куртка</b>";
  else
    $res = "Холодно, советую <b>пуховик</b>";

  if ($cond->main == 'Rain') //дождик
    $res .= "\nИ не забудьте <b>зонтик</b>! 🌂";
  elseif ($cond->id >= 800 and $cond->id <= 802 and $fl_temp > 20) //солнечно или легкая облачность
    $res .= "\nТакже пригодятся солнцезащитные <b>очки</b> 🕶";

  return $res;
}

function getWeather($city) {
  $raw = file_get_contents('https://api.openweathermap.org/data/2.5/weather?q='.urlencode($city).'&units=metric&lang=ru&appid='.OWM_CODE);
  if (!$raw or is_numeric($city)) return "А такой город точно существует? Напишите корректное название города";
  else {
    $json = json_decode($raw);
    if ($json->cod == 200) {
      $res = "<b>".$json->name."</b> (".$json->sys->country.")\n";
      $res .= printTemp(round($json->main->temp)).", ".$json->weather[0]->description."\n";
      $res .= "Ощущается как: <b>".printTemp(round($json->main->feels_like))."</b>\n\n";
      $res .= "💨 Ветер: <b>".round($json->wind->speed)."м/с</b>  ".printWindDir($json->wind->deg)."\n";
      $res .= "🌡 Давление: <b>".round($json->main->pressure*76000/101325)."мм рт.ст.</b>\n";
      $res .= "💧 Влажность: <b>".round($json->main->humidity)."%</b>\n";
      $res .= "🌫 Облачность: <b>".round($json->clouds->all)."%</b>\n";
      if ($json->visibility>0) $res .= "Видимость: <b>".round($json->visibility/1000)."км</b>\n";

      $res .= "\nЧто надеть:\n".getClothes($json->main->temp,$json->weather[0],$json->wind);
      return $res;
    }
    else
      return "Произошла неизвестная ошибка... Если через пару минут ничего не исправится, напишите: @semenovkm с пометкой '<i>Погодный бот</i>'"; 
  }
}

if (isset($_GET['setup'])) {
  apiRequestJson('setWebhook', array('url' => WEBHOOK_URL));
  exit;
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
  exit;
}

if (isset($update["message"])) {
  processMessage($update["message"]);
}
?>